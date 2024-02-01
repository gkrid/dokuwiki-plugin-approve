<?php

use dokuwiki\Extension\Event;
use dokuwiki\Form\Form;
use dokuwiki\Form\TagCloseElement;
use dokuwiki\Form\TagOpenElement;
use dokuwiki\Form\CheckableElement;
use dokuwiki\plugin\approve\meta\ApproveMetadata;
use dokuwiki\plugin\approve\meta\ApproveRevisionInfo;

if(!defined('DOKU_INC')) die();


class action_plugin_approve_revisions extends DokuWiki_Action_Plugin {

    function register(Doku_Event_Handler $controller) {
		$controller->register_hook('FORM_REVISIONS_OUTPUT', 'BEFORE', $this, 'handle_revisions', array());
        $controller->register_hook('FORM_REVISIONS_OUTPUT', 'BEFORE', $this, 'add_media_revisions', array());
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

//        $res = $sqlite->query('SELECT rev, approved, ready_for_approval
//                                FROM revision
//                                WHERE page=?', $INFO['id']);
//        $approve_revisions = $sqlite->res2arr($res);
//        $last_approved_rev = null;
//        if (count($approve_revisions) > 1) {
//            $last_approved_rev = max(array_column(array_filter($approve_revisions, function ($v) {
//                return $v['approved'] != null;
//            }), 'rev'));
//        }
//
//        $approve_revisions = array_combine(array_column($approve_revisions, 'rev'), $approve_revisions);

        try {
            $approve_metadata = new ApproveMetadata($this->getConf('media_approve'));
        } catch (Exception $e) {
            msg($e->getMessage(), -1);
            return false;
        }
        $approve_revisions = $approve_metadata->getPageRevisions($INFO['id']);
        $last_approved_rev = max(array_column(array_filter($approve_revisions, function ($v) {
            return $v['approved'] != null;
        }), 'rev'));
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
                if (!isset($approve_revisions[$revision]) || $approve_revisions[$revision]['status'] == 'draft') {
                    $class =  'plugin__approve_draft';
                } elseif ($approve_revisions[$revision]['status'] == 'approved' && $revision == $last_approved_rev) {
                    $class =  'plugin__approve_approved';
                } elseif ($approve_revisions[$revision]['status'] == 'approved') {
                    $class =  'plugin__approve_old_approved';
                } elseif ($this->getConf('ready_for_approval') && $approve_revisions[$revision]['status'] == 'ready_for_approval') {
                    $class =  'plugin__approve_ready';
                } else {
                    $class =  'plugin__approve_draft';
                }

                $parent_div = $event->data->getElementAt($parent_div_position);
                $parent_div->addClass($class);
                $parent_div_position = -1;
            }
		}
        return true;
	}

    function add_media_revisions(Event $event, $param) {
        global $INFO;

        if (!$this->getConf('media_approve')) return;
        try {
            /** @var \helper_plugin_approve_db $db_helper */
            $db_helper = plugin_load('helper', 'approve_db');
            $sqlite = $db_helper->getDB();
        } catch (Exception $e) {
            msg($e->getMessage(), -1);
            return false;
        }
        /** @var helper_plugin_approve $helper */
        $helper = plugin_load('helper', 'approve');

        if (!$helper->use_approve_here($sqlite, $INFO['id'])) return;

        try {
            $approve_metadata = new ApproveMetadata($this->getConf('media_approve'));
        } catch (Exception $e) {
            msg($e->getMessage(), -1);
            return false;
        }

        /**
         * @var Form $form
         */
        $form = $event->data;
        $last_revision = $INFO['meta']['date']['modified'];
        $parent_div_closing = -1;
        $current_revision_found = false;
        for ($i = 0; $i < $form->elementCount(); $i++) {
            $element = $form->getElementAt($i);
//            if ($element instanceof TagOpenElement && $element->val() == 'li') {
//                $parent_li_position = $i;
//                break;
//            }
            if ($element instanceof CheckableElement && $element->attr('name') == 'rev2[]') {
                $revision = $element->attr('value');
                if ($revision == $last_revision) {
                    $current_revision_found = true;
                }
            } elseif ($current_revision_found && $element instanceof TagCloseElement && $element->val() == 'div') {
                $parent_div_closing = $i;
                break;
            }
        }

        $media_revisions = $approve_metadata->getMediaRevisions($INFO['id'], $last_revision);
        $pos = $parent_div_closing + 1;
        $form->addTagOpen('ul', $pos++);
        foreach ($media_revisions as $revision) {
            $form->addTagOpen('li', $pos++);

            if ($revision['status'] == 'approved') {
                $class =  'plugin__approve_old_approved';
            } elseif ($this->getConf('ready_for_approval') && $revision['status'] == 'ready_for_approval') {
                $class = 'plugin__approve_ready';
            }

            $form->addTagOpen('div', $pos++)->addClass('li')->addClass($class);
            $ApproveRevInfo = new ApproveRevisionInfo($revision);
            $html = implode(' ', [
                $ApproveRevInfo->showEditDate(),      // edit date and time
                $ApproveRevInfo->showFileName(),      // name of page
                $ApproveRevInfo->showEditor(),        // editor info
            ]);
            $form->addHTML($html, $pos++);
            $form->addTagClose('div', $pos++);
            $form->addTagClose('li', $pos++);
        }
        $form->addTagClose('ul', $pos);
        return true;
    }
}
