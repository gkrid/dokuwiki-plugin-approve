<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\plugin\approve\meta\ApproveTrait;

class action_plugin_approve_approve extends ActionPlugin {
    use ApproveTrait;

    public function register(EventHandler $controller) {
        $controller->register_hook('TPL_ACT_RENDER', 'AFTER', $this, 'handle_diff_accept');
        $controller->register_hook('HTML_SHOWREV_OUTPUT', 'BEFORE', $this, 'handle_showrev');
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handle_approve');
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handle_mark_ready_for_approval');
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handle_viewer');
        $controller->register_hook('COMMON_WIKIPAGE_SAVE', 'AFTER', $this, 'handle_pagesave_after');
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

        $this->init();

        $approved = date('c');
        //approved IS NULL prevents from overriding already approved page
        $sqlite->query('UPDATE revision
                        SET approved=?, approved_by=?, version=?
                        WHERE page=? AND current=1 AND approved IS NULL',
            $approved, $INFO['client'], $this->approve_metadata->getPageVersion($INFO['id'])+1, $INFO['id']);

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
                    'current' => 1
                ]);
                break;
        }
    }
}
