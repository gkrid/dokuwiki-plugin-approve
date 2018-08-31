<?php

if(!defined('DOKU_INC')) die();

class action_plugin_approve_revisions extends DokuWiki_Action_Plugin {

    /** @var DokuWiki_PluginInterface */
    protected $hlp;

    function __construct(){
        $this->hlp = plugin_load('helper', 'approve');
    }


    function register(Doku_Event_Handler $controller) {
		$controller->register_hook('HTML_REVISIONSFORM_OUTPUT', 'BEFORE', $this, 'handle_revisions', array());
		$controller->register_hook('HTML_RECENTFORM_OUTPUT', 'BEFORE', $this, 'handle_revisions', array());
	}

	function handle_revisions(Doku_Event $event, $param) {
		global $ID;

		if ($this->hlp->in_namespace($this->getConf('no_apr_namespaces'), $ID)) return;

		$member = NULL;
		foreach ($event->data->_content as $key => $ref) {

			if (is_array($ref) && isset($ref['_elem']) && $ref['_elem'] == 'opentag' && $ref['_tag'] == 'div' && $ref['class'] == 'li') {
			    $member = $key;
            } elseif (is_string($ref) && strstr($ref, $this->getConf('sum approved'))) {
                $event->data->_content[$member]['class'] .= ' plugin__approve_green';
            }

		}

		return true;
	}

}
