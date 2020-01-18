<?php

// must be run within Dokuwiki

if(!defined('DOKU_INC')) die();

class action_plugin_approve_move extends DokuWiki_Action_Plugin {

    /**
     * Registers a callback function for a given event
     *
     * @param Doku_Event_Handler $controller DokuWiki's event controller object
     * @return void
     */
    public function register(Doku_Event_Handler $controller) {
        $controller->register_hook('PLUGIN_MOVE_PAGE_RENAME', 'AFTER', $this, 'handle_move', true);
    }

    /**
     * Renames all occurances of a page ID in the database
     *
     * @param Doku_Event $event event object by reference
     * @param bool $ispage is this a page move operation?
     * @return void
     */
    public function handle_move(Doku_Event $event, $ispage) {
        try {
            /** @var \helper_plugin_approve_db $db_helper */
            $db_helper = plugin_load('helper', 'approve_db');
            $sqlite = $db_helper->getDB();
        } catch (Exception $e) {
            msg($e->getMessage(), -1);
            return;
        }

        $old = $event->data['src_id'];
        $new = $event->data['dst_id'];

        //move revision history
        $sqlite->query('UPDATE revision SET page=? WHERE page=?', $new, $old);
    }

}
