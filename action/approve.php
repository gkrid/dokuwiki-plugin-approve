<?php

use \dokuwiki\ChangeLog\MediaChangeLog;
use dokuwiki\plugin\approve\meta\ApproveMetadata;
use dokuwiki\Ui\MediaDiff;

if(!defined('DOKU_INC')) die();

class action_plugin_approve_approve extends DokuWiki_Action_Plugin {
    /**
     * @param Doku_Event_Handler $controller
     */
    public function register(Doku_Event_Handler $controller) {
        $controller->register_hook('TPL_ACT_RENDER', 'AFTER', $this, 'handle_diff_accept');
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handle_media_diff_accept');
        $controller->register_hook('TPL_ACT_RENDER', 'BEFORE', $this, 'display_media_diff_accept');
        $controller->register_hook('HTML_SHOWREV_OUTPUT', 'BEFORE', $this, 'handle_showrev');
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handle_approve');
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handle_mark_ready_for_approval');
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handle_viewer');
        $controller->register_hook('TPL_ACT_RENDER', 'BEFORE', $this, 'handle_display_banner');
        $controller->register_hook('COMMON_WIKIPAGE_SAVE', 'AFTER', $this, 'handle_pagesave_after');
        $controller->register_hook('MEDIA_UPLOAD_FINISH', 'AFTER', $this, 'handle_media_upload_finish_after');
    }

    /**
     * @param Doku_Event $event
     */
    public function handle_diff_accept(Doku_Event $event) {
        global $INFO;

        try {
            /** @var \helper_plugin_approve_db $db_helper */
            $db_helper = plugin_load('helper', 'approve_db');
            $sqlite = $db_helper->getDB();
        } catch (Exception $e) {
            msg($e->getMessage(), -1);
            return;
        }
        /** @var helper_plugin_approve $helper */
        $helper = plugin_load('helper', 'approve');

        if (!$helper->use_approve_here($sqlite, $INFO['id'])) return;

        if ($event->data == 'diff' && isset($_GET['approve'])) {
            $href = wl($INFO['id'], ['approve' => 'approve']);
            ptln('<a href="' . $href . '">'.$this->getLang('approve').'</a>');
        }

        if ($this->getConf('ready_for_approval') && $event->data == 'diff' && isset($_GET['ready_for_approval'])) {
            $href = wl($INFO['id'], ['ready_for_approval' => 'ready_for_approval']);
            ptln('<a href="' . $href . '">'.$this->getLang('approve_ready').'</a>');
        }
    }

    public function handle_media_diff_accept(Doku_Event $event) {
        global $ID, $INFO, $INPUT;
        if ($event->data == 'approve_media_diff') {
            $event->preventDefault();
        } elseif ($event->data == 'approve_media') {
            $event->preventDefault();
            try {
                /** @var \helper_plugin_approve_db $db_helper */
                $db_helper = plugin_load('helper', 'approve_db');
                $sqlite = $db_helper->getDB();
            } catch (Exception $e) {
                msg($e->getMessage(), -1);
                return;
            }

            // TODO: check for ACL Here || Should we change version?
            $approved = date('c');
            $res = $sqlite->query('SELECT MAX(version)+1 FROM revision
                                        WHERE page=?', $ID);
            $next_version = $sqlite->res2single($res);
            if (!$next_version) {
                $next_version = 1;
            }
            $media_id = $INPUT->str('media_id');
            $last_media_change_date = @filemtime(mediaFN($media_id));
            $sqlite->query('UPDATE revision
                        SET approved=?, approved_by=?, version=?, media_rev=?
                        WHERE page=? AND current=1',
                $approved, $INFO['client'], $next_version, $last_media_change_date, $INFO['id']);

            header('Location: ' . wl($INFO['id']));

        }
        return true;
    }
    public function display_media_diff_accept(Doku_Event $event) {
        global $ID, $REV, $INPUT;
        if ($event->data == 'approve_media_diff') {
            $event->preventDefault();
            $media_id = $INPUT->str('media_id');
            $media_diff = new MediaDiff($media_id);
            $media_diff->show();

            $href = wl($ID, ['do' => 'approve_media',
                'media_id' => $media_id,
                'rev' => $REV]);
            ptln('<a href="' . $href . '">'.$this->getLang('approve').'</a>');

        }
        return true;
    }

    /**
     * @param Doku_Event $event
     */
    public function handle_showrev(Doku_Event $event) {
        global $INFO;

        try {
            /** @var \helper_plugin_approve_db $db_helper */
            $db_helper = plugin_load('helper', 'approve_db');
            $sqlite = $db_helper->getDB();
        } catch (Exception $e) {
            msg($e->getMessage(), -1);
            return;
        }
        /** @var helper_plugin_approve $helper */
        $helper = plugin_load('helper', 'approve');

        if (!$helper->use_approve_here($sqlite, $INFO['id'])) return;

        $last_approved_rev = $helper->find_last_approved($sqlite, $INFO['id']);
        if ($last_approved_rev == $INFO['rev']) {
            $event->preventDefault();
        }
    }

    /**
     * @param Doku_Event $event
     */
    public function handle_approve(Doku_Event $event) {
        global $INFO;

        try {
            /** @var \helper_plugin_approve_db $db_helper */
            $db_helper = plugin_load('helper', 'approve_db');
            $sqlite = $db_helper->getDB();
        } catch (Exception $e) {
            msg($e->getMessage(), -1);
            return;
        }
        /** @var helper_plugin_approve $helper */
        $helper = plugin_load('helper', 'approve');

        if ($event->data != 'show') return;
        if (!isset($_GET['approve'])) return;
        if (!$helper->use_approve_here($sqlite, $INFO['id'], $approver)) return;
        if (!$helper->client_can_approve($INFO['id'], $approver)) return;

        $res = $sqlite->query('SELECT MAX(version)+1 FROM revision
                                        WHERE page=?', $INFO['id']);
        $next_version = $sqlite->res2single($res);
        if (!$next_version) {
            $next_version = 1;
        }
        $approved = date('c');
        //approved IS NULL prevents from overriding already approved page
        $sqlite->query('UPDATE revision
                        SET approved=?, approved_by=?, version=?
                        WHERE page=? AND current=1 AND approved IS NULL',
            $approved, $INFO['client'], $next_version, $INFO['id']);

        header('Location: ' . wl($INFO['id']));
    }

    /**
     * @param Doku_Event $event
     */
    public function handle_mark_ready_for_approval(Doku_Event $event) {
        global $INFO;

        try {
            /** @var \helper_plugin_approve_db $db_helper */
            $db_helper = plugin_load('helper', 'approve_db');
            $sqlite = $db_helper->getDB();
        } catch (Exception $e) {
            msg($e->getMessage(), -1);
            return;
        }
        /** @var helper_plugin_approve $helper */
        $helper = plugin_load('helper', 'approve');

        if ($event->data != 'show') return;
        if (!isset($_GET['ready_for_approval'])) return;
        if (!$helper->use_approve_here($sqlite, $INFO['id'])) return;
        if (!$helper->client_can_mark_ready_for_approval($INFO['id'])) return;

        $sqlite->query('UPDATE revision SET ready_for_approval=?, ready_for_approval_by=?
                                WHERE page=? AND current=1 AND ready_for_approval IS NULL',
        date('c'), $INFO['client'], $INFO['id']);

        header('Location: ' . wl($INFO['id']));
    }

    /**
     * Redirect to newest approved page for user that don't have EDIT permission.
     *
     * @param Doku_Event $event
     */
    public function handle_viewer(Doku_Event $event) {
        global $INFO;

        try {
            /** @var \helper_plugin_approve_db $db_helper */
            $db_helper = plugin_load('helper', 'approve_db');
            $sqlite = $db_helper->getDB();
        } catch (Exception $e) {
            msg($e->getMessage(), -1);
            return;
        }
        /** @var helper_plugin_approve $helper */
        $helper = plugin_load('helper', 'approve');

        if ($event->data != 'show') return;
        //apply only to current page
        if ($INFO['rev'] != 0) return;
        if (!$helper->use_approve_here($sqlite, $INFO['id'], $approver)) return;
        if ($helper->client_can_see_drafts($INFO['id'], $approver)) return;

        $last_approved_rev = $helper->find_last_approved($sqlite, $INFO['id']);
        //no page is approved
        if (!$last_approved_rev) return;

        $last_change_date = @filemtime(wikiFN($INFO['id']));
        //current page is approved
        if ($last_approved_rev == $last_change_date) return;

        header("Location: " . wl($INFO['id'], ['rev' => $last_approved_rev], false, '&'));
    }

    /**
     * @param Doku_Event $event
     */
    public function handle_display_banner(Doku_Event $event) {
        global $INFO, $ID;

        /* Return true if banner should not be displayed for users with or below read only permission. */
        if (auth_quickaclcheck($ID) <= AUTH_READ && !$this->getConf('display_banner_for_readonly')) {
            return true;
        }

        /* Not returned - rendering the banner */
        try {
            /** @var \helper_plugin_approve_db $db_helper */
            $db_helper = plugin_load('helper', 'approve_db');
            $sqlite = $db_helper->getDB();
        } catch (Exception $e) {
            msg($e->getMessage(), -1);
            return;
        }
        /** @var helper_plugin_approve $helper */
        $helper = plugin_load('helper', 'approve');

        try {
            $approve_metadata = new ApproveMetadata();
        } catch (Exception $e) {
            msg($e->getMessage(), -1);
            return false;
        }

        if ($event->data != 'show') return;
        if (!$INFO['exists']) return;
        if (!$helper->use_approve_here($sqlite, $INFO['id'], $approver)) return;

//        $last_change_date = p_get_metadata($INFO['id'], 'last_change date');
        $last_change_date = @filemtime(wikiFN($INFO['id']));
        $rev = !$INFO['rev'] ? $last_change_date : $INFO['rev'];

        $approve = $approve_metadata->getPageStatus($INFO['id'], $last_change_date, $rev, $this->getConf('media_approve'));
        $last_approved_rev = $helper->find_last_approved($sqlite, $INFO['id']);

        $classes = [];
        if ($this->getConf('prettyprint')) {
            $classes[] = 'plugin__approve_noprint';
        }

        if ($approve['approved'] && $rev == $last_approved_rev) {
            $classes[] = 'plugin__approve_approved';
        } elseif ($approve['approved']) {
                $classes[] = 'plugin__approve_old_approved';
        } elseif ($this->getConf('ready_for_approval') && $approve['ready_for_approval']) {
            $classes[] = 'plugin__approve_ready';
        } else {
            $classes[] = 'plugin__approve_draft';
        }

        ptln('<div id="plugin__approve" class="' . implode(' ', $classes) . '">');

//		tpl_pageinfo();
//		ptln(' | ');

        if ($approve['approved']) {
            ptln('<strong>'.$this->getLang('approved').'</strong>');
            if (isset($approve['outdated_media_id'])) {
                if ($helper->client_can_approve($INFO['id'], $approver)) {
                    $urlParameters = [
                        'media_id' => $approve['outdated_media_id'],
                        'rev' => $approve['outdated_media_rev'],
                        'do' => 'approve_media_diff',
                    ];
                    ptln('<a href="' . wl($INFO['id'], $urlParameters) . '">');
                    ptln('(media outdated)' . $approve['outdated_media_id']);
                    ptln('</a>');
                } else {
                    ptln('(media outdated)' . $approve['outdated_media_id']);
                }
            }
            ptln(' ' . dformat(strtotime($approve['approved'])));

            if($this->getConf('banner_long')) {
                ptln(' ' . $this->getLang('by') . ' ' . userlink($approve['approved_by'], true));
                ptln(' (' . $this->getLang('version') .  ': ' . $approve['version'] . ')');
            }

            //not the newest page
            if ($rev != $last_change_date) {
                $res = $sqlite->query('SELECT rev, current FROM revision
                                WHERE page=? AND approved IS NOT NULL
                                ORDER BY rev DESC LIMIT 1', $INFO['id']);

                $last_approve = $sqlite->res_fetch_assoc($res);

                //we can see drafts
                if ($helper->client_can_see_drafts($INFO['id'], $approver)) {
                    ptln('<a href="' . wl($INFO['id']) . '">');
                    ptln($this->getLang($last_approve['current'] ? 'newest_approved' : 'newest_draft'));
                    ptln('</a>');
                //we cannot see link to draft but there is some newer approved version
                } elseif ($last_approve['rev'] != $rev) {
                    $urlParameters = [];
                    if (!$last_approve['current']) {
                        $urlParameters['rev'] = $last_approve['rev'];
                    }
                    ptln('<a href="' . wl($INFO['id'], $urlParameters) . '">');
                    ptln($this->getLang('newest_approved'));
                    ptln('</a>');
                }
            }

        } else {
            if ($this->getConf('ready_for_approval') && $approve['ready_for_approval']) {
                ptln('<strong>'.$this->getLang('marked_approve_ready').'</strong>');
                ptln(' ' . dformat(strtotime($approve['ready_for_approval'])));
                ptln(' ' . $this->getLang('by') . ' ' . userlink($approve['ready_for_approval_by'], true));
            } else {
                ptln('<strong>'.$this->getLang('draft').'</strong>');
            }


            $res = $sqlite->query('SELECT rev, current FROM revision
                            WHERE page=? AND approved IS NOT NULL
                            ORDER BY rev DESC LIMIT 1', $INFO['id']);

            $last_approve = $sqlite->res_fetch_assoc($res);


            //not exists approve for current page
            if (!$last_approve) {
                //not the newest page
                if ($rev != $last_change_date) {
                    ptln('<a href="'.wl($INFO['id']).'">');
                    ptln($this->getLang('newest_draft'));
                    ptln('</a>');
                }
            } else {
                $urlParameters = [];
                if (!$last_approve['current']) {
                    $urlParameters['rev'] = $last_approve['rev'];
                }
                ptln('<a href="' . wl($INFO['id'], $urlParameters) . '">');
                ptln($this->getLang('newest_approved'));
                ptln('</a>');
            }

            //we are in current page
            if ($rev == $last_change_date) {

                //compare with the last approved page or 0 if there is no approved versions
                $last_approved_rev = 0;
                if (isset($last_approve['rev'])) {
                    $last_approved_rev = $last_approve['rev'];
                }

                if ($this->getConf('ready_for_approval') &&
                    $helper->client_can_mark_ready_for_approval($INFO['id']) &&
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

                if ($helper->client_can_approve($INFO['id'], $approver)) {

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

        if ($approver && $this->getConf('banner_long')) {
            ptln(' | ' . $this->getLang('approver') . ': ' . userlink($approver, true));
        }

        ptln('</div>');
    }

    /**
     * @return bool|string|void
     */
    protected function lastRevisionHasntApprovalData($id) {

        try {
            /** @var \helper_plugin_approve_db $db_helper */
            $db_helper = plugin_load('helper', 'approve_db');
            $sqlite = $db_helper->getDB();
        } catch (Exception $e) {
            msg($e->getMessage(), -1);
            return;
        }

        $res = $sqlite->query('SELECT rev FROM revision
                                        WHERE page=?
                                          AND current=1
                                          AND approved IS NULL
                                          AND ready_for_approval IS NULL', $id);

        return $sqlite->res2single($res);
    }

    /**
     *
     * @param Doku_Event $event  event object by reference
     * @return void
     */
    public function handle_pagesave_after(Doku_Event $event) {
        try {
            /** @var \helper_plugin_approve_db $db_helper */
            $db_helper = plugin_load('helper', 'approve_db');
            $sqlite = $db_helper->getDB();
        } catch (Exception $e) {
            msg($e->getMessage(), -1);
            return;
        }
        /** @var helper_plugin_approve $helper */
        $helper = plugin_load('helper', 'approve');

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
                $rev = $this->lastRevisionHasntApprovalData($id);
                if ($rev) {
                    $sqlite->query('UPDATE revision SET rev=? AND media_rev=? WHERE page=? AND rev=?',
                        $last_change_date, $last_change_date, $id, $rev);
                } else {
                    //keep previous record
                    $sqlite->query('UPDATE revision SET current=0
                                            WHERE page=?
                                            AND current=1', $id);

                    $sqlite->storeEntry('revision', [
                        'page' => $id,
                        'rev' => $last_change_date,
                        'media_rev' => $last_change_date,
                        'current' => 1
                    ]);
                }
                break;
            case DOKU_CHANGE_TYPE_DELETE:
                //delete information about availability of a page but keep the history
                $sqlite->query('DELETE FROM page WHERE page=?', $id);

                //delete revision if no information about approvals
                $rev = $this->lastRevisionHasntApprovalData($id);
                if ($rev) {
                    $sqlite->query('DELETE FROM revision WHERE page=? AND rev=?', $id, $rev);
                } else {
                    $sqlite->query('UPDATE revision SET current=0 WHERE page=? AND current=1', $id);
                }
                break;
            case DOKU_CHANGE_TYPE_CREATE:
                if ($helper->isPageAssigned($sqlite, $id, $newApprover)) {
                    $data = [
                        'page' => $id,
                        'hidden' => $helper->in_hidden_namespace($sqlite, $id) ? '1' : '0'
                    ];
                    if (!blank($newApprover)) {
                        $data['approver'] = $newApprover;
                    }
                    $sqlite->storeEntry('page', $data);
                }

                //store revision
                $last_change_date = $event->data['newRevision'];
                $sqlite->storeEntry('revision', [
                    'page' => $id,
                    'rev' => $last_change_date,
                    'media_rev' => $last_change_date,
                    'current' => 1
                ]);
                break;
        }
    }

    public function handle_media_upload_finish_after(Doku_Event $event) {
        return;
        try {
            /** @var \helper_plugin_approve_db $db_helper */
            $db_helper = plugin_load('helper', 'approve_db');
            $sqlite = $db_helper->getDB();
        } catch (Exception $e) {
            msg($e->getMessage(), -1);
            return false;
        }

        $media_id = $event->data[2];
        $exists = $event->data[4];
        // upload new file - nothing to do
        if (!$exists) return true;

        $res = $sqlite->query('SELECT revision.page, revision.rev FROM revision JOIN media_revision
                                    ON revision.page=media_revision.page AND revision.rev=media_revision.rev
                                        WHERE media=?
                                          AND current=1
                                          AND (revision.approved IS NOT NULL OR
                                            revision.ready_for_approval IS NOT NULL)', $media_id);
        $pages = $sqlite->res2arr($res);
        foreach ($pages as $page) {
            $changelog = new MediaChangeLog($media_id);
            $media_rev = $changelog->currentRevision();
            $sqlite->storeEntry('media_revision', [
                'page' => $page['page'],
                'rev' => $page['rev'],
                'media' => $media_id,
                'media_rev' => $media_rev
            ]);
        }
        return true;
    }
}
