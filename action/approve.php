<?php

use dokuwiki\plugin\approve\meta\ApproveConst;

if(!defined('DOKU_INC')) die();

class action_plugin_approve_approve extends DokuWiki_Action_Plugin {

    /** @var DokuWiki_PluginInterface */
    protected $hlp;

    function __construct(){
        $this->hlp = plugin_load('helper', 'approve');
    }

    function register(Doku_Event_Handler $controller) {
		
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handle_approve', array());
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handle_viewer', array());
        $controller->register_hook('TPL_ACT_RENDER', 'AFTER', $this, 'handle_diff_accept', array());
        $controller->register_hook('TPL_ACT_RENDER', 'BEFORE', $this, 'handle_display_banner', array());
        $controller->register_hook('HTML_SHOWREV_OUTPUT', 'BEFORE', $this, 'handle_showrev', array());
        // ensure a page revision is created when summary changes:
        $controller->register_hook('COMMON_WIKIPAGE_SAVE', 'BEFORE', $this, 'handle_pagesave_before');
        $controller->register_hook('COMMON_WIKIPAGE_SAVE', 'AFTER', $this, 'handle_pagesave_after');
    }
	
	function handle_diff_accept(Doku_Event $event, $param) {
		global $ID;
		
		if ($this->hlp->in_namespace($this->getConf('no_apr_namespaces'), $ID)) return;
		
		if ($event->data == 'diff' && isset($_GET['approve'])) {
			ptln('<a href="'.DOKU_URL.'doku.php?id='.$_GET['id'].'&approve=approve">'.$this->getLang('approve').'</a>');
		}

        if ($event->data == 'diff' && isset($_GET['ready_for_approval']) && $this->getConf('ready_for_approval') === 1) {
			ptln('<a href="'.DOKU_URL.'doku.php?id='.$_GET['id'].'&ready_for_approval=ready_for_approval">'.$this->getLang('approve_ready').'</a>');
		}
	}

	function handle_showrev(Doku_Event $event, $param) {
		global $REV;

		$last = $this->find_lastest_approved();
		if ($last == $REV)
			$event->preventDefault();
	}

	function can_approve() {
		global $ID;
		return auth_quickaclcheck($ID) >= AUTH_DELETE;
	}

    function can_edit() {
		global $ID;
		return auth_quickaclcheck($ID) >= AUTH_EDIT;
	}

	function handle_approve(Doku_Event $event, $param) {
		global $ID;
		
		if ($this->hlp->in_namespace($this->getConf('no_apr_namespaces'), $ID)) return;
		
		if ($event->data == 'show' && isset($_GET['approve'])) {
		    if ( ! $this->can_approve()) return;

		    //create new page revison
            saveWikiText($ID, rawWiki($ID), $this->getConf('sum approved'));

			header('Location: ?id='.$ID);
		} elseif ($event->data == 'show' && isset($_GET['ready_for_approval'])) {
		    if ( ! $this->can_edit()) return;

            //create new page revison
            saveWikiText($ID, rawWiki($ID), $this->getConf('sum ready for approval'));

            header('Location: ?id='.$ID);
		}		
	}

    function handle_viewer(Doku_Event $event, $param) {
        global $REV, $ID;
        if ($event->data != 'show') return;
        if (auth_quickaclcheck($ID) > AUTH_READ || ($this->hlp->in_namespace($this->getConf('no_apr_namespaces'), $ID))) return;
        
	    $last = $this->find_lastest_approved();
	    //no page is approved
		if ($last == -1) return;
		//approved page is the newest page
		if ($last == 0) return;
		
		//if we are viewing lastest revision, show last approved
		if ($REV == 0) header("Location: ?id=$ID&rev=$last");
	}

	function find_lastest_approved() {
		global $ID;
		$m = p_get_metadata($ID);
		$sum = $m['last_change']['sum'];
		if ($sum == $this->getConf('sum approved'))
			return 0;

		$changelog = new PageChangeLog($ID);

		$chs = $changelog->getRevisions(0, 10000);
		foreach ($chs as $rev) {
			$ch = $changelog->getRevisionInfo($rev);
			if ($ch['sum'] == $this->getConf('sum approved'))
				return $rev;
		}
		return -1;
	}

    function handle_display_banner(Doku_Event $event, $param) {
		global $ID, $REV, $INFO;
		
		if ($this->hlp->in_namespace($this->getConf('no_apr_namespaces'), $ID)) return;
        if ($event->data != 'show') return;
		if (!$INFO['exists']) return;
		
		$sum = $this->hlp->page_sum($ID, $REV);


		$classes = array();
		if ($this->getConf('prettyprint')) {
		    $classes[] = 'plugin__approve_noprint';
        }

        if ($sum == $this->getConf('sum approved')) {
		    $classes[] = 'plugin__approve_green';
		} elseif ($sum == $this->getConf('sum ready for approval') && $this->getConf('ready_for_approval')) {
		    $classes[] = 'plugin__approve_ready';
        } else {
            $classes[] = 'plugin__approve_red';
        }

		ptln('<div id="plugin__approve" class="' . implode(' ', $classes) . '">');

		tpl_pageinfo();
		ptln(' | ');
		$last_approved_rev = $this->find_lastest_approved();
		if ($sum == $this->getConf('sum approved')) {
		    $versions = p_get_metadata($ID, ApproveConst::METADATA_VERSIONS_KEY);
		    if (!$versions) {
                $versions = $this->render_metadata_for_approved_page($ID);
            }
            if (empty($REV)) {
                $version = $versions[0];
            } else {
                $version = $versions[$REV];
            }

			ptln('<strong>'.$this->getLang('approved').'</strong> (' . $this->getLang('version') .  ': ' . $version
                 . ')');
			if ($REV != 0 && auth_quickaclcheck($ID) > AUTH_READ) {
				ptln('<a href="'.wl($ID).'">');
				ptln($this->getLang(p_get_metadata($ID, 'last_change sum') == $this->getConf('sum approved') ? 'newest_approved' : 'newest_draft'));
				ptln('</a>');
			} else if ($REV != 0 && $REV != $last_approved_rev) {
				ptln('<a href="'.wl($ID).'">');
				ptln($this->getLang('newest_approved'));
				ptln('</a>');
			}
		} else {
			ptln('<span>'.$this->getLang('draft').'</span>');

			if ($sum == $this->getConf('sum ready for approval') && $this->getConf('ready_for_approval') === 1) {
				ptln('<span>| '.$this->getLang('marked_approve_ready').'</span>');
			}


			if ($last_approved_rev == -1) {
			    if ($REV != 0) {
				    ptln('<a href="'.wl($ID).'">');
				    	ptln($this->getLang('newest_draft'));
				    ptln('</a>');
				}
			} else {
				if ($last_approved_rev != 0)
					ptln('<a href="'.wl($ID, array('rev' => $last_approved_rev)).'">');
				else
					ptln('<a href="'.wl($ID).'">');

					ptln($this->getLang('newest_approved'));
				ptln('</a>');
			}

			if ($REV == 0 && $this->can_edit() && $sum != $this->getConf('sum ready for approval') && $this->getConf('ready_for_approval') === 1) {
				ptln(' | <a href="'.wl($ID, array('rev' => $last_approved_rev, 'do' => 'diff',
				'ready_for_approval' => 'ready_for_approval')).'">');
					ptln($this->getLang('approve_ready'));
				ptln('</a>');
			}

			if ($REV == 0 && $this->can_approve()) {
				ptln(' | <a href="'.wl($ID, array('rev' => $last_approved_rev, 'do' => 'diff',
				'approve' => 'approve')).'">');
					ptln($this->getLang('approve'));
				ptln('</a>');
			}


		}
		ptln('</div>');
	}

    /**
     * Check if the page has to be changed
     *
     * @param Doku_Event $event  event object by reference
     * @param mixed      $param  [the parameters passed as fifth argument to register_hook() when this
     *                           handler was registered]
     * @return void
     */
    public function handle_pagesave_before(Doku_Event $event, $param) {
        global $REV;
        $id = $event->data['id'];
        if ($this->hlp->in_namespace($this->getConf('no_apr_namespaces'), $id)) return;

        //save page if summary is provided
        if($event->data['summary'] == $this->getConf('sum approved')) {
            $event->data['contentChanged'] = true;
        }
    }

    /**
     * @param Doku_Event $event
     * @param            $param
     */
    public function handle_pagesave_after(Doku_Event $event, $param) {
        global $REV;
        $id = $event->data['id'];
        if ($this->hlp->in_namespace($this->getConf('no_apr_namespaces'), $id)) return;

        //save page if summary is provided
        if($event->data['summary'] == $this->getConf('sum approved')) {

            $versions = p_get_metadata($id, ApproveConst::METADATA_VERSIONS_KEY);
            //calculate versions
            if (!$versions) {
                $this->render_metadata_for_approved_page($id, $event->data['newRevision']);
            } else {
                $curver = $versions[0] + 1;
                $versions[0] = $curver;
                $versions[$event->data['newRevision']] = $curver;
                p_set_metadata($id, array(ApproveConst::METADATA_VERSIONS_KEY => $versions));
            }
        }
    }


    /**
     * Calculate current version
     *
     * @param $id
     * @return array
     */
    protected function render_metadata_for_approved_page($id, $currev=false) {
        if (!$currev) $currev = @filemtime(wikiFN($id));

        $version = $this->approved($id);
        //version for current page
        $curver = $version + 1;
        $versions = array(0 => $curver, $currev => $curver);

        $changelog = new PageChangeLog($id);
        $first = 0;
        $num = 100;
        while (count($revs = $changelog->getRevisions($first, $num)) > 0) {
            foreach ($revs as $rev) {
                $revInfo = $changelog->getRevisionInfo($rev);
                if ($revInfo['sum'] == $this->getConf('sum approved')) {
                    $versions[$rev] = $version;
                    $version -= 1;
                }
            }
            $first += $num;
        }

        p_set_metadata($id, array(ApproveConst::METADATA_VERSIONS_KEY => $versions));

        return $versions;
    }

    /**
     * Get the number of approved pages
     * @param $id
     * @return int
     */
    protected function approved($id) {
        $count = 0;

        $changelog = new PageChangeLog($id);
        $first = 0;
        $num = 100;
        while (count($revs = $changelog->getRevisions($first, $num)) > 0) {
            foreach ($revs as $rev) {
                $revInfo = $changelog->getRevisionInfo($rev);
                if ($revInfo['sum'] == $this->getConf('sum approved')) {
                    $count += 1;
                }
            }
            $first += $num;
        }

        return $count;
    }
}
