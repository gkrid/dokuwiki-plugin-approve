<?php

if(!defined('DOKU_INC')) die();

class action_plugin_approve_prettyprint extends DokuWiki_Action_Plugin {
	
    private $hlp;
    function __construct(){
        $this->hlp = plugin_load('helper', 'approve');
    }
    
	function register(Doku_Event_Handler $controller) {
		$controller->register_hook('DOKUWIKI_STARTED', 'AFTER',  $this, '_printingInfo');
	}
	function _printingInfo(Doku_Event $event, $param) {
		global $JSINFO, $ID, $REV, $INFO;
		$JSINFO['approve'] = array();
		
		$JSINFO['approve']['lang'] = array(
			'approved' => $this->getLang('approved'),
			'draft' => $this->getLang('draft'),
			'by' => $this->getLang('by'),
			'date' => $this->getLang('hdr_updated'),
            'version' => $this->getLang('version')
		);
		
		if ($this->getConf('prettyprint')) {
			$JSINFO['approve']['prettyprint'] = true;

			$versions = p_get_metadata($ID, 'plugin_approve_versions');
			if (empty($REV)) {
			    $version = $versions[0];
            } else {
			    $version = $versions[$REV];
            }
			$JSINFO['approve']['status'] = $this->hlp->page_sum($ID, $REV);
			$JSINFO['approve']['version'] = $version;
			$JSINFO['approve']['date'] = dformat($INFO['lastmod']);
			$JSINFO['approve']['author'] = editorinfo($INFO['editor']);
		} else {
			$JSINFO['approve']['prettyprint'] = false;
		}
	}

}
