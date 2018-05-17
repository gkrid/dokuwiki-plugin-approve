<?php


if(!defined('DOKU_INC')) die();

class action_plugin_approve extends DokuWiki_Action_Plugin {

    const APPROVED = 'Approved';
    const METADATA_VERSIONS_KEY = 'plugin_approve_versions';

    /** @var DokuWiki_PluginInterface|null */
    protected $hlp;

    function __construct(){
        $this->hlp = plugin_load('helper', 'approve');
    }

    function register(Doku_Event_Handler $controller) {
    }
}