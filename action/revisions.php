<?php

use dokuwiki\Form\TagOpenElement;
use dokuwiki\Form\CheckableElement;

if(!defined('DOKU_INC')) die();


class action_plugin_approve_revisions extends DokuWiki_Action_Plugin {

    function register(Doku_Event_Handler $controller) {
		$controller->register_hook('FORM_REVISIONS_OUTPUT', 'BEFORE', $this, 'handle_revisions', array());
	}

	function handle_revisions(Doku_Event $event, $param) {
		global $INFO;

        try {
            /** @var \helper_plugin_approve_db $db_helper */
            $db_helper = plugin_load('helper', 'approve_db');
            $sqlite = $db_helper->getDB();
        } catch (Exception $e) {
            msg($e->getMessage(), -1);
            return;
        }
        /** @var helper_plugin_approve $helper */
        $helper = plugin_load('helper', 'approve');

        if (!$helper->use_approve_here($sqlite, $INFO['id'])) return;

        $res = $sqlite->query('SELECT rev, approved, ready_for_approval
                                FROM revision
                                WHERE page=?', $INFO['id']);
        $approve_revisions = $sqlite->res2arr($res);
        $approve_revisions = array_combine(array_column($approve_revisions, 'rev'), $approve_revisions);

		$parent_div_position = -1;
		for ($i = 0; $i < $event->data->elementCount(); $i++) {
            $element = $event->data->getElementAt($i);
            if ($element instanceof TagOpenElement && $element->val() == 'div'
                && $element->attr('class') == 'li') {
                $parent_div_position = $i;
            } elseif ($parent_div_position > 0 && $element instanceof CheckableElement &&
                $element->attr('name') == 'rev2[]') {
                $revision = $element->attr('value');
                if ($revision == 'current') {
                    $revision = $INFO['meta']['date']['modified'];
                }
                if (!isset($approve_revisions[$revision])) {
                    $class =  'plugin__approve_red';
                } elseif ($approve_revisions[$revision]['approved']) {
                    $class =  'plugin__approve_green';
                } elseif ($this->getConf('ready_for_approval') && $approve_revisions[$revision]['ready_for_approval']) {
                    $class =  'plugin__approve_ready';
                } else {
                    $class =  'plugin__approve_red';
                }

                $parent_div = $event->data->getElementAt($parent_div_position);
                $parent_div->addClass($class);
                $parent_div_position = -1;
            }
		}
	}

}
