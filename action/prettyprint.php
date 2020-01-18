<?php

if(!defined('DOKU_INC')) die();

class action_plugin_approve_prettyprint extends DokuWiki_Action_Plugin {

	function register(Doku_Event_Handler $controller) {
		$controller->register_hook('DOKUWIKI_STARTED', 'AFTER',  $this, '_printingInfo');
	}

	function _printingInfo(Doku_Event $event, $param) {
		global $JSINFO, $INFO;

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

		$JSINFO['approve'] = ['prettyprint' => false];

        if (!$this->getConf('prettyprint')) return;
        if (!$helper->use_approve_here($sqlite, $INFO['id'], $approver)) return;


        $JSINFO['approve']['prettyprint'] = true;
		$JSINFO['approve']['lang'] = array(
			'approved' => $this->getLang('approved'),
			'marked_approve_ready' => $this->getLang('marked_approve_ready'),
			'draft' => $this->getLang('draft'),
			'by' => $this->getLang('by'),
			'date' => $this->getLang('hdr_updated'),
            'version' => $this->getLang('version'),
            'approver' => $this->getLang('approver')
		);

        $last_change_date = @filemtime(wikiFN($INFO['id']));
        $rev = !$INFO['rev'] ? $last_change_date : $INFO['rev'];

        $res = $sqlite->query('SELECT ready_for_approval, ready_for_approval_by, 
                                        approved, approved_by, version
                                FROM revision
                                WHERE page=? AND rev=?', $INFO['id'], $rev);

        $approve = $sqlite->res_fetch_assoc($res);

        if ($approve['approved']) {
            $JSINFO['approve']['status'] = 'approved';
            $JSINFO['approve']['author'] = userlink($approve['approved_by'], true);
            $JSINFO['approve']['date'] = dformat(strtotime($approve['approved']));
            $JSINFO['approve']['version'] = $approve['version'];
        } elseif ($this->getConf('ready_for_approval') && $approve['ready_for_approval']) {
            $JSINFO['approve']['status'] = 'ready for approval';
            $JSINFO['approve']['author'] = userlink($approve['ready_for_approval_by'], true);
            $JSINFO['approve']['date'] = dformat(strtotime($approve['ready_for_approval']));
        } else {
            $JSINFO['approve']['status'] = 'draft';
            if ($INFO['editor']) {
                $JSINFO['approve']['author'] = userlink($INFO['editor'], true);
            } else {
                $JSINFO['approve']['author'] = '';
            }
            $JSINFO['approve']['date'] = dformat($rev);
        }
        $JSINFO['approve']['approver'] = userlink($approver, true);
	}

}
