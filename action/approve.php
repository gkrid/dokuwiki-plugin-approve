<?php

use dokuwiki\plugin\approve\meta\ApproveConst;

if(!defined('DOKU_INC')) die();

class action_plugin_approve_approve extends DokuWiki_Action_Plugin {

    /** @var helper_plugin_sqlite */
    protected $sqlite;

    /** @var helper_plugin_approve */
    protected $helper;

    /**
     * @return helper_plugin_sqlite
     */
    protected function sqlite() {
        if (!$this->sqlite) {
            /** @var helper_plugin_approve_db $db_helper */
            $db_helper = plugin_load('helper', 'approve_db');
            $this->sqlite = $db_helper->getDB();
        }
        return $this->sqlite;
    }

    /**
     * @return helper_plugin_approve
     */
    protected function helper() {
        if (!$this->helper) {
            $helper = plugin_load('helper', 'approve');
            $this->helper = $helper;
        }
        return $this->helper;
    }


    /**
     * @param Doku_Event_Handler $controller
     */
    public function register(Doku_Event_Handler $controller) {
        $controller->register_hook('TPL_ACT_RENDER', 'AFTER', $this, 'handle_diff_accept');
        $controller->register_hook('HTML_SHOWREV_OUTPUT', 'BEFORE', $this, 'handle_showrev');
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handle_approve');
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handle_viewer');
        $controller->register_hook('TPL_ACT_RENDER', 'BEFORE', $this, 'handle_display_banner');
        $controller->register_hook('COMMON_WIKIPAGE_SAVE', 'AFTER', $this, 'handle_pagesave_after');
    }

    /**
     * @param Doku_Event $event
     */
    public function handle_diff_accept(Doku_Event $event) {
		global $INFO;

		if (!$this->helper()->use_approve_here($INFO['id'])) return;

		if ($event->data == 'diff' && isset($_GET['approve'])) {
		    $href = wl($INFO['id'], ['approve' => 'approve']);
			ptln('<a href="' . $href . '">'.$this->getLang('approve').'</a>');
		}

        if ($this->getConf('ready_for_approval') && $event->data == 'diff' && isset($_GET['ready_for_approval'])) {
            $href = wl($INFO['id'], ['ready_for_approval' => 'ready_for_approval']);
            ptln('<a href="' . $href . '">'.$this->getLang('approve').'</a>');
		}
	}

    /**
     * @param Doku_Event $event
     */
    public function handle_showrev(Doku_Event $event) {
        global $INFO;

        if (!$this->helper()->use_approve_here($INFO['id'])) return;

        $last_approved_rev = $this->helper()->find_last_approved($INFO['id']);
		if ($last_approved_rev == $INFO['rev']) {
            $event->preventDefault();
        }
	}

	/**
     * @param Doku_Event $event
     */
    public function handle_approve(Doku_Event $event) {
		global $INFO;

        if (!$this->helper()->use_approve_here($INFO['id'])) return;
		
		if ($event->data == 'show' && isset($_GET['approve']) &&
            auth_quickaclcheck($INFO['id']) >= AUTH_DELETE) {

		    $res = $this->sqlite()->query('SELECT MAX(version)+1 FROM revision
                                            WHERE page=?', $INFO['id']);
		    $next_version = $this->sqlite()->res2single($res);
		    if (!$next_version) {
                $next_version = 1;
            }
		    //approved IS NULL prevents from overriding already approved page
		    $this->sqlite()->query('UPDATE revision
		                    SET approved=?, version=?
                            WHERE page=? AND current=1 AND approved IS NULL',
                            date('c'), $next_version, $INFO['id']);

			header('Location: ' . wl($INFO['id']));
		} elseif ($event->data == 'show' && isset($_GET['ready_for_approval']) &&
            auth_quickaclcheck($INFO['id']) >= AUTH_EDIT) {

            $this->sqlite()->query('UPDATE revision SET ready_for_approval=?
                            WHERE page=? AND current=1 AND ready_for_approval IS NULL',
                            date('c'), $INFO['id']);

            header('Location: ' . wl($INFO['id']));
		}		
	}

    /**
     * Redirect to newest approved page for user that don't have EDIT permission.
     *
     * @param Doku_Event $event
     */
    public function handle_viewer(Doku_Event $event) {
        global $INFO;

        if ($event->data != 'show') return;
        //apply only to current page
        if (!$INFO['rev']) return;
        if (auth_quickaclcheck($INFO['id']) >= AUTH_EDIT) return;
        if (!$this->helper()->use_approve_here($INFO['id'])) return;

        $last_approved_rev = $this->helper()->find_last_approved($INFO['id']);
        //no page is approved
        if (!$last_approved_rev) return;

        $last_change_date = @filemtime(wikiFN($INFO['id']));
        //current page is approved
        if ($last_approved_rev == $last_change_date) return;

	    header("Location: " . wl($INFO['id'], ['rev' => $last_approved_rev]));
	}

    /**
     * @param Doku_Event $event
     */
    public function handle_display_banner(Doku_Event $event) {
		global $INFO;

        if ($event->data != 'show') return;
        if (!$INFO['exists']) return;
        if (!$this->helper()->use_approve_here($INFO['id'])) return;

//        $last_change_date = p_get_metadata($INFO['id'], 'last_change date');
        $last_change_date = @filemtime(wikiFN($INFO['id']));
        $rev = !$INFO['rev'] ? $last_change_date : $INFO['rev'];


        $res = $this->sqlite()->query('SELECT ready_for_approval, approved, version
                                FROM revision
                                WHERE page=? AND rev=?', $INFO['id'], $rev);

        $approve = $this->sqlite()->res_fetch_assoc($res);

		$classes = [];
		if ($this->getConf('prettyprint')) {
		    $classes[] = 'plugin__approve_noprint';
        }

        if ($approve['approved']) {
		    $classes[] = 'plugin__approve_green';
		} elseif ($this->getConf('ready_for_approval') && $approve['ready_for_approval']) {
		    $classes[] = 'plugin__approve_ready';
        } else {
            $classes[] = 'plugin__approve_red';
        }

		ptln('<div id="plugin__approve" class="' . implode(' ', $classes) . '">');

		tpl_pageinfo();
		ptln(' | ');

		if ($approve['approved']) {
			ptln('<strong>'.$this->getLang('approved').'</strong> ('
                . $this->getLang('version') .  ': ' . $approve['version'] . ')');

			//not the newest page
			if ($rev != $last_change_date) {
                $res = $this->sqlite()->query('SELECT rev, current FROM revision
                                WHERE page=? AND approved IS NOT NULL
                                ORDER BY rev DESC LIMIT 1', $INFO['id']);

                $lastest_approve = $this->sqlite()->res_fetch_assoc($res);

			    //we can see drafts
                if (auth_quickaclcheck($INFO['id']) >= AUTH_EDIT) {
                    ptln('<a href="' . wl($INFO['id']) . '">');
                    ptln($this->getLang($lastest_approve['current'] ? 'newest_approved' : 'newest_draft'));
                    ptln('</a>');
                } else {
                    $urlParameters = [];
                    if (!$lastest_approve['current']) {
                        $urlParameters['rev'] = $lastest_approve['rev'];
                    }
                    ptln('<a href="' . wl($INFO['id'], $urlParameters) . '">');
                    ptln($this->getLang('newest_approved'));
                    ptln('</a>');
                }
            }

		} else {
			ptln('<span>'.$this->getLang('draft').'</span>');

			if ($this->getConf('ready_for_approval') && $approve['ready_for_approval']) {
				ptln('<span>| '.$this->getLang('marked_approve_ready').'</span>');
			}


            $res = $this->sqlite()->query('SELECT rev, current FROM revision
                            WHERE page=? AND approved IS NOT NULL
                            ORDER BY rev DESC LIMIT 1', $INFO['id']);

            $lastest_approve = $this->sqlite()->res_fetch_assoc($res);


            //not exists approve for current page
			if (!$lastest_approve) {
                //not the newest page
                if ($rev != $last_change_date) {
				    ptln('<a href="'.wl($INFO['id']).'">');
                    ptln($this->getLang('newest_draft'));
				    ptln('</a>');
				}
			} else {
                $urlParameters = [];
                if (!$lastest_approve['current']) {
                    $urlParameters['rev'] = $lastest_approve['rev'];
                }
                ptln('<a href="' . wl($INFO['id'], $urlParameters) . '">');
                ptln($this->getLang('newest_approved'));
				ptln('</a>');
			}

			//we are in current page
			if ($rev == $last_change_date) {

                $last_approved_rev = 0;
                if (isset($lastest_approve['rev'])) {
                    $last_approved_rev = $lastest_approve['rev'];
                }

                if ($this->getConf('ready_for_approval') &&
                    auth_quickaclcheck($INFO['id']) >= AUTH_EDIT &&
                    !$approve['ready_for_approval']) {

                    $urlParameters = [
                        'rev' => $last_approved_rev,
                        'do' => 'diff',
                        'ready_for_approval' => 'ready_for_approval'
                    ];
                    ptln(' | <a href="'.wl($INFO['id'], $urlParameters).'">');
                    ptln($this->getLang('approve_ready'));
                    ptln('</a>');
                }

                if (auth_quickaclcheck($INFO['id']) >= AUTH_DELETE) {

                    $urlParameters = [
                        'rev' => $last_approved_rev,
                        'do' => 'diff',
                        'approve' => 'approve'
                    ];
                    ptln(' | <a href="'.wl($INFO['id'], $urlParameters).'">');
                    ptln($this->getLang('approve'));
                    ptln('</a>');
                }

            }
		}
		ptln('</div>');
	}

    /**
     * @return bool|string
     */
    protected function prev_rev($id) {
        $res = $this->sqlite()->query('SELECT rev FROM revision
                                        WHERE page=?
                                          AND current=1
                                          AND approved IS NULL
                                          AND ready_for_approval IS NULL', $id);

        return $this->sqlite()->res2single($res);
    }

    /**
     *
     * @param Doku_Event $event  event object by reference
     * @return void
     */
    public function handle_pagesave_after(Doku_Event $event) {
        //no content was changed
        if (!$event->data['contentChanged']) return;

        $changeType = $event->data['changeType'];
        if ($changeType == DOKU_CHANGE_TYPE_REVERT) {
            if ($event->data['oldContent'] == '') {
                $changeType = DOKU_CHANGE_TYPE_CREATE;
            } else {
                $changeType = DOKU_CHANGE_TYPE_EDIT;
            }
        }

        $id = $event->data['id'];
        switch ($changeType) {
            case DOKU_CHANGE_TYPE_EDIT:
            case DOKU_CHANGE_TYPE_REVERT:
            case DOKU_CHANGE_TYPE_MINOR_EDIT:
                $last_change_date = $event->data['newRevision'];

                //if the current page has approved or ready_for_approval -- keep it
                $prev_rev = $this->prev_rev($id);
                if ($prev_rev) {
                    $this->sqlite()->query('UPDATE revision SET rev=? WHERE page=? AND rev=?',
                        $last_change_date, $id, $prev_rev);

                } else {
                    //keep previous record
                    $this->sqlite()->query('UPDATE revision SET current=0
                                            WHERE page=?
                                            AND current=1', $id);

                    $this->sqlite()->storeEntry('revision', [
                        'page' => $id,
                        'rev' => $last_change_date,
                        'current' => 1
                    ]);
                }
                break;
            case DOKU_CHANGE_TYPE_DELETE:
                //delete information about availability of a page but keep the history
                $this->sqlite()->query('DELETE FROM page WHERE page=?', $id);

                //delete revision if no information about approvals
                $prev_rev = $this->prev_rev($id);
                if ($prev_rev) {
                    $this->sqlite()->query('DELETE FROM revision WHERE page=? AND rev=?',
                        $id, $prev_rev);
                } else {
                    $this->sqlite()->query('UPDATE revision SET current=0 WHERE page=? AND rev=?',
                        $id, $prev_rev);
                }

                break;
            case DOKU_CHANGE_TYPE_CREATE:
                $last_change_date = $event->data['newRevision'];
                $hidden = $this->helper()->in_hidden_namespace($id);

                $this->sqlite()->storeEntry('page', [
                    'page' => $id,
                    'hidden' => $hidden
                ]);

                $this->sqlite()->storeEntry('revision', [
                    'page' => $id,
                    'rev' => $last_change_date,
                    'current' => 1
                ]);
                break;
        }
    }
}
