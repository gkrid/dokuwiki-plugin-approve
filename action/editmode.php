<?php
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

class action_plugin_approve_editmode extends DokuWiki_Action_Plugin
{
    /** @inheritdoc */
    function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('DOKUWIKI_STARTED', 'AFTER', $this, 'session_update');
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, '_handle_act');
        $controller->register_hook('MENU_ITEMS_ASSEMBLY', 'AFTER', $this, 'addMenuLink');
        $controller->register_hook('MENU_ITEMS_ASSEMBLY', 'AFTER', $this, 'changeEditButton');

    }



    public function session_update(Doku_Event $event, $param) {
        $_SESSION["approve_mode"] = get_doku_pref("approve_mode","edit");
    }


    public function _handle_act($event, $param)
    {
         if ($event->data != 'approvemodechange') return;

         if ($_SESSION["approve_mode"] == 'view') {
             $_SESSION["approve_mode"] = "edit";
             set_doku_pref('approve_mode', 'edit');
         } ELSE {
             $_SESSION["approve_mode"] = "view";
             set_doku_pref('approve_mode', 'view');
             }

         $event->data = 'redirect';

    }



    /**
     * Add Link for mode change to the menu system
     *
     * @param Doku_Event $event
     * @return bool
     */
    public function addMenuLink(Doku_Event $event)
    {
        if (empty($_SERVER['REMOTE_USER'])) return false;
        if ($event->data['view'] !== 'user') return false;

        array_splice($event->data['items'], 1, 0, [new \dokuwiki\plugin\approve\MenuItem()]);

        return true;
    }


    public function changeEditButton(Doku_Event $event) {
        if (empty($_SERVER['REMOTE_USER'])) return false;
        if($event->data['view'] != 'page' ) return false;

        # In viewmode the editbutton must be changed
        if ($_SESSION["approve_mode"] !== 'edit') {
            array_splice($event->data['items'], 0, 1, [new \dokuwiki\plugin\approve\Editmodus($_SESSION["approve_mode"])]);
        } else {
            array_splice($event->data['items'], -1, 0, [new \dokuwiki\plugin\approve\Editmodus($_SESSION["approve_mode"])]);
        }

    } 

}
