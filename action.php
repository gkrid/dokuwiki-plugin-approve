<?php

if(!defined('DOKU_INC')) die();

class action_plugin_approve extends DokuWiki_Action_Plugin {

    function register(&$controller) {
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, handle_approve, array());
        $controller->register_hook('TPL_ACT_RENDER', 'AFTER', $this, handle_diff_accept, array());
        $controller->register_hook('TPL_ACT_RENDER', 'BEFORE', $this, handle_display_banner, array());
    }
	
	function handle_diff_accept(&$event, $param) {
		if ($event->data == 'diff' && isset($_GET['approve'])) {
			ptln('<a href="'.DOKU_URL.'doku.php?id='.$_GET['id'].'&approve=approve">'.$this->getLang('approve').'</a>');
		}
	}

	function handle_approve(&$event, $param) {
		global $ID;
		if ($event->data == 'show' && isset($_GET['approve'])) {
			//Add or remove the new line from the end of the page. Silly but needed.
			$content = rawWiki($ID, '');
			if (substr($content, -1) == "\n") {
				$content = substr($content, 0, -1);
			} else {
				$content .= "\n";
			}
			saveWikiText($ID, $content, 'Approved');

			header('Location: ?id='.$ID);
		}
	}

    function handle_display_banner(&$event, $param) {
		global $ID;
		global $REV;

        if($event->data != 'show') return true;


		$m = p_get_metadata($ID);
		$changelog = new PageChangeLog($ID);

		//sprawdź status aktualnej strony
		if ($REV != 0) {
			$ch = $changelog->getRevisionInfo($REV);
			$sum = $ch['sum'];
		} else {
			$sum = $m['last_change']['sum'];
		}

		if ($sum == 'Approved') {
			$class = 'approved_yes';
		} else {
			$class = 'approved_no';
			//wyszukaj najnowszej zatwierdzonej

			//najnowsza jest zatwierdzona
			if ($m['last_change']['sum'] == 'Approved') {
				$last_approved_rev = 0;
			} else {
				//poszukaj w dół
				$chs = $changelog->getRevisions(0, 10000);
				foreach ($chs as $rev) {
					$ch = $changelog->getRevisionInfo($rev);
					if ($ch['sum'] == 'Approved') {
						$last_approved_rev = $rev;
						break;
					}
				}
			}
		}

		
		ptln('<div class="approval '.$class.'">');
		tpl_pageinfo();
		ptln(' | ');
		if ($sum == 'Approved') {
			ptln('<span>'.$this->getLang('approved').'</span>');
			$lastest_sum = $m['last_change']['sum'];
			if ($REV != 0) {
				ptln('<a href="'.wl($ID).'">');
				if ($lastest_sum != 'Approved')
					ptln($this->getLang('newest_draft'));
				else 
					ptln($this->getLang('newest_approved'));
				ptln('</a>');
			}
		} else {
			ptln('<span>'.$this->getLang('draft').'</span>');

			if (isset($last_approved_rev)) {
				if ($last_approved_rev != 0)
					ptln('<a href="'.wl($ID, array('rev' => $last_approved_rev)).'">');
				else
					ptln('<a href="'.wl($ID).'">');

					ptln($this->getLang('newest_approved'));
				ptln('</a>');
			} else {
				ptln('<a href="'.wl($ID).'">');
					ptln($this->getLang('newest_draft'));
				ptln('</a>');
			}

			//można zatwierdzać tylko najnowsze strony
			if ($REV == 0) {
				ptln('<a href="'.wl($ID, array('rev' => $last_approved_rev, 'do' => 'diff',
				'approve' => 'approve')).'">');
					ptln($this->getLang('approve'));
				ptln('</a>');
			}
		}
		ptln('</div>');
	}

}
