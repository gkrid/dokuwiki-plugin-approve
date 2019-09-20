<?php

if(!defined('DOKU_INC')) die();

class action_plugin_approve_cache extends DokuWiki_Action_Plugin
{
    /**
     * Registers a callback function for a given event
     *
     * @param Doku_Event_Handler $controller DokuWiki's event controller object
     *
     * @return void
     */
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('PARSER_CACHE_USE', 'BEFORE', $this, 'handle_parser_cache_use');
    }
    /**
     * @param Doku_Event $event event object by reference
     * @param mixed $param [the parameters passed as fifth argument to register_hook() when this
     *                           handler was registered]
     *
     * @return void
     */
    public function handle_parser_cache_use(Doku_Event $event, $param)
    {
        /** @var cache_renderer $cache */
        $cache = $event->data;

        if(!$cache->page) return;
        //purge only xhtml cache
        if($cache->mode != 'xhtml') return;

        //Check if it is plugins
        $approve = p_get_metadata($cache->page, 'plugin approve');
        if(!$approve) return;

        if ($approve['dynamic_approver']) {
            $cache->_nocache = true;
        } elseif ($approve['approve_table']) {
            $db_helper = plugin_load('helper', 'approve_db');
            $cache->depends['files'][] = $db_helper->getDB()->getAdapter()->getDbFile();
        }
    }
}
