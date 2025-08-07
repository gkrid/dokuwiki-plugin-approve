<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;

class action_plugin_approve_approve extends ActionPlugin {
    /**
     * @inheritDoc
     */
    public function register(EventHandler $controller) {
        $controller->register_hook('TPL_ACT_RENDER', 'AFTER', $this, 'handle_diff_accept');
        $controller->register_hook('HTML_SHOWREV_OUTPUT', 'BEFORE', $this, 'handle_showrev');
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handle_approve');
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handle_mark_ready_for_approval');
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handle_viewer');
        $controller->register_hook('TPL_ACT_RENDER', 'BEFORE', $this, 'handle_display_banner');
        $controller->register_hook('COMMON_WIKIPAGE_SAVE', 'AFTER', $this, 'handle_pagesave_after');
    }

    /**
     * @param Event $event
     */
    public function handle_diff_accept(Event $event) {
        global $INFO;

        /** @var helper_plugin_approve_acl $acl */
        $acl = $this->loadHelper('approve_acl');

        if (!$acl->useApproveHere($INFO['id'])) return;

        if ($event->data == 'diff' && isset($_GET['approve'])) {
            $href = wl($INFO['id'], ['approve' => 'approve']);
            echo '<a href="' . $href . '">'.$this->getLang('approve').'</a>';
        }

        if ($this->getConf('ready_for_approval') && $event->data == 'diff' && isset($_GET['ready_for_approval'])) {
            $href = wl($INFO['id'], ['ready_for_approval' => 'ready_for_approval']);
            echo '<a href="' . $href . '">'.$this->getLang('approve_ready').'</a>';
        }
    }

    /**
     * @param Event $event
     */
    public function handle_showrev(Event $event) {
        global $INFO;

        /** @var helper_plugin_approve_db $db */
        $db = $this->loadHelper('approve_db');
        /** @var helper_plugin_approve_acl $acl */
        $acl = $this->loadHelper('approve_acl');

        if (!$acl->useApproveHere($INFO['id'])) return;

        $last_approved_rev = $db->getLastDbRev($INFO['id'], 'approved');
        if ($last_approved_rev == $INFO['rev']) {
            $event->preventDefault();
        }
    }

    /**
     * @param Event $event
     */
    public function handle_approve(Event $event) {
        global $INFO;

        /** @var helper_plugin_approve_acl $acl */
        $acl = $this->loadHelper('approve_acl');

        if ($event->data != 'show') return;
        if (!isset($_GET['approve'])) return;
        if (!$acl->useApproveHere($INFO['id'])) return;
        if (!$acl->clientCanApprove($INFO['id'])) return;

        /** @var helper_plugin_approve_db $db */
        $db = $this->loadHelper('approve_db');
        $db->setApprovedStatus($INFO['id']);

        header('Location: ' . wl($INFO['id']));
    }

    /**
     * @param Event $event
     */
    public function handle_mark_ready_for_approval(Event $event) {
        global $INFO;

        /** @var helper_plugin_approve_acl $acl */
        $acl = $this->loadHelper('approve_acl');

        if ($event->data != 'show') return;
        if (!isset($_GET['ready_for_approval'])) return;
        if (!$acl->useApproveHere($INFO['id'])) return;
        if (!$acl->clientCanMarkReadyForApproval($INFO['id'])) return;

        /** @var helper_plugin_approve_db $db */
        $db = $this->loadHelper('approve_db');
        $db->setReadyForApprovalStatus($INFO['id']);

        header('Location: ' . wl($INFO['id']));
    }

    /**
     * Redirect to latest approved page for user that don't have EDIT permission.
     *
     * @param Event $event
     */
    public function handle_viewer(Event $event) {
        global $INFO;

        /** @var helper_plugin_approve_acl $acl */
        $acl = $this->loadHelper('approve_acl');

        if ($event->data != 'show') return;
        //apply only to current page
        if ($INFO['rev'] != 0) return;
        if (!$acl->useApproveHere($INFO['id'])) return;
        if ($acl->clientCanSeeDrafts($INFO['id'])) return;

        /** @var helper_plugin_approve_db $db */
        $db = $this->loadHelper('approve_db');
        $last_approved_rev = $db->getLastDbRev($INFO['id'], 'approved');

        //no page is approved
        if (!$last_approved_rev) return;

        $last_change_date = @filemtime(wikiFN($INFO['id']));
        // current page is approved
        if ($last_approved_rev == $last_change_date) return;

        header("Location: " . wl($INFO['id'], ['rev' => $last_approved_rev], false, '&'));
    }

    /**
     * @param Event $event
     */
    public function handle_display_banner(Event $event) {
        global $conf;

        // for the sprintdoc template we have special integrations
        if ($conf['template'] == 'sprintdoc') return;

        /** @var helper_plugin_approve_tpl $tpl */
        $tpl = $this->loadHelper('approve_tpl');
        $tpl->banner($event->data);
    }

    /**
     *
     * @param Event $event
     */
    public function handle_pagesave_after(Event $event) {
        //no content was changed
        if (!$event->data['contentChanged']) return;

        /** @var helper_plugin_approve_db $db */
        $db = $this->loadHelper('approve_db');

        $id = $event->data['id'];
        switch ($event->data['changeType']) {
            case DOKU_CHANGE_TYPE_CREATE:
            case DOKU_CHANGE_TYPE_EDIT:
            case DOKU_CHANGE_TYPE_MINOR_EDIT:
            case DOKU_CHANGE_TYPE_REVERT:
                $db->handlePageEdit($id);
                break;
            case DOKU_CHANGE_TYPE_DELETE:
                $db->handlePageDelete($id);
                break;
        }
    }
}
