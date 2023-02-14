<?php

use dokuwiki\plugin\approve\meta\ViewModeEdit;
use dokuwiki\plugin\approve\meta\ViewModeSiteTools;

/**
 * Approve Plugin: places a link in usermenue and allows for change between modes
 * Copied and adapted from userpage plugin
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Michael Kirchner
 * @author     Blake Martin
 * @author     Andreas Gohr <andi@splitbrain.org>
 * @author     Anika Henke <anika@selfthinker.org>
 */

class action_plugin_approve_viewmode extends DokuWiki_Action_Plugin
{
    /** @inheritdoc */
    function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handleAct');
        $controller->register_hook('MENU_ITEMS_ASSEMBLY', 'AFTER', $this, 'addSiteTools');
        $controller->register_hook('MENU_ITEMS_ASSEMBLY', 'AFTER', $this, 'addPageTools');
    }

    public function handleAct(Doku_Event $event)
    {
        if (!$this->getConf('viewmode')) return;
        if ($event->data != 'viewmodesitetools' && $event->data != 'viewmodeedit') return;
        $viewmode = get_doku_pref('approve_viewmode', false);
        set_doku_pref('approve_viewmode', !$viewmode);  // toggle status
        $event->data = 'redirect';
    }

    /**
     * Add Link for mode change to the site tools
     *
     * @param Doku_Event $event
     * @return bool
     */
    public function addSiteTools(Doku_Event $event)
    {
        global $INPUT;
        if (!$this->getConf('viewmode')) return false;
        if (!$INPUT->server->str('REMOTE_USER')) return false;
        if ($event->data['view'] != 'user') return false;

        array_splice($event->data['items'], 1, 0, [new ViewModeSiteTools()]);

        return true;
    }

    public function addPageTools(Doku_Event $event)
    {
        global $INPUT;
        if (!$this->getConf('viewmode')) return false;
        if (!$INPUT->server->str('REMOTE_USER')) return false;
        if ($event->data['view'] != 'page') return false;

        $viewmode = get_doku_pref('approve_viewmode', false);
        if ($viewmode) {
            array_splice($event->data['items'], 0, 1, [new ViewModeEdit()]);
        }
        return true;
    }

}