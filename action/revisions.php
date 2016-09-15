<?php

if(!defined('DOKU_INC')) die();

class action_plugin_approve_revisions extends DokuWiki_Action_Plugin {
	
    private $hlp;
    function __construct(){
        $this->hlp = plugin_load('helper', 'approve');
    }
    
	function register(Doku_Event_Handler $controller) {
		$controller->register_hook('HTML_REVISIONSFORM_OUTPUT', 'BEFORE', $this, 'handle_revisions', array());
		$controller->register_hook('HTML_RECENTFORM_OUTPUT', 'BEFORE', $this, 'handle_revisions', array());
	}

	function handle_revisions(Doku_Event &$event, $param) {
		global $ID;
		global $INFO;
		
		if ($this->hlp->in_namespace($this->getConf('no_apr_namespaces'), $ID)) return;

		$member = NULL;
		foreach ($event->data->_content as $key => $ref) {
			if(isset($ref['_elem']) && $ref['_elem'] == 'opentag' && $ref['_tag'] == 'div' && $ref['class'] == 'li')
				$member = $key;

			if (is_string($ref) && strstr($ref, 'Approved'))
				$event->data->_content[$member]['class'] = 'li approved_yes';
		}

		return true;
	}

}
