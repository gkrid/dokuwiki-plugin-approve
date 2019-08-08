<?php

if(!defined('DOKU_INC')) die();

class action_plugin_approve_revisions extends DokuWiki_Action_Plugin {

    /** @var helper_plugin_sqlite */
    protected $sqlite;

    /** @var helper_plugin_approve */
    protected $helper;

    /**
     * @return helper_plugin_sqlite
     */
    protected function sqlite() {
        if (!$this->sqlite) {
            /** @var helper_plugin_approve_db $db_helper */
            $db_helper = plugin_load('helper', 'approve_db');
            $this->sqlite = $db_helper->getDB();
        }
        return $this->sqlite;
    }

    /**
     * @return helper_plugin_approve
     */
    protected function helper() {
        if (!$this->helper) {
            $helper = plugin_load('helper', 'approve');
            $this->helper = $helper;
        }
        return $this->helper;
    }

    function register(Doku_Event_Handler $controller) {
		$controller->register_hook('HTML_REVISIONSFORM_OUTPUT', 'BEFORE', $this, 'handle_revisions', array());
	}

	function handle_revisions(Doku_Event $event, $param) {
		global $INFO;

        if (!$this->helper()->use_approve_here($INFO['id'])) return;

        $res = $this->sqlite()->query('SELECT rev, approved, ready_for_approval
                                FROM revision
                                WHERE page=?', $INFO['id']);
        $approveRevisions = $this->sqlite()->res2arr($res);
        $approveRevisions = array_combine(array_column($approveRevisions, 'rev'), $approveRevisions);

		$member = null;
		foreach ($event->data->_content as $key => $ref) {
            if(isset($ref['_elem']) && $ref['_elem'] == 'opentag' && $ref['_tag'] == 'div' && $ref['class'] == 'li') {
                $member = $key;
            }

            if ($member && $ref['_elem'] == 'tag' &&
                $ref['_tag'] == 'input' && $ref['name'] == 'rev2[]'){
                $revision = $ref['value'];
                if ($revision == 'current') {
                    $revision = $INFO['meta']['date']['modified'];
                }
                if (!isset($approveRevisions[$revision])) {
                    $class =  'plugin__approve_red';
                } elseif ($approveRevisions[$revision]['approved']) {
                    $class =  'plugin__approve_green';
                } elseif ($this->getConf('ready_for_approval') && $approveRevisions[$revision]['ready_for_approval']) {
                    $class =  'plugin__approve_ready';
                } else {
                    $class =  'plugin__approve_red';
                }

                $event->data->_content[$member]['class'] = "li $class";

                $member = null;
            }
		}

		return true;
	}

}
