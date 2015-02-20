<?php

if(!defined('DOKU_INC')) die();
define(APPROVED, 'Approved');

class action_plugin_approve extends DokuWiki_Action_Plugin {

    function register(&$controller) {
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, handle_approve, array());
        $controller->register_hook('TPL_ACT_RENDER', 'AFTER', $this, handle_diff_accept, array());
        $controller->register_hook('TPL_ACT_RENDER', 'BEFORE', $this, handle_display_banner, array());
        $controller->register_hook('HTML_SHOWREV_OUTPUT', 'BEFORE', $this, handle_showrev, array());
    }
	
	function handle_diff_accept(&$event, $param) {
		if ($event->data == 'diff' && isset($_GET['approve'])) {
			ptln('<a href="'.DOKU_URL.'doku.php?id='.$_GET['id'].'&approve=approve">'.$this->getLang('approve').'</a>');
		}
	}

	function handle_showrev(&$event, $param) {
		global $ID, $REV;

		$last = $this->find_lastest_approved();
		if ($last == $REV)
			$event->preventDefault();
	}

	function can_approve() {
		global $ID;
		return auth_quickaclcheck($ID) > AUTH_DELETE;
	}

	function handle_approve(&$event, $param) {
		global $ID, $REV;
		if ( ! $this->can_approve()) return;
		if ($event->data == 'show' && isset($_GET['approve'])) {
			//Add or remove the new line from the end of the page. Silly but needed.
			$content = rawWiki($ID, '');
			if (substr($content, -1) == "\n") {
				$content = substr($content, 0, -1);
			} else {
				$content .= "\n";
			}
			saveWikiText($ID, $content, APPROVED);

			header('Location: ?id='.$ID);
		}

		/*czytacze wydzą najnowszą zatwierdzaną*/
		$last = $this->find_lastest_approved();
		/*użytkownik może tylko czytać i jednocześnie istnieje jakaś zatwierdzona strona*/
		if (auth_quickaclcheck($ID) <= AUTH_READ && $last != -1)
			/*najnowsza zatwierdzona nie jest najnowszą*/
			/*i jednocześnie znajdujemy się w stronach nowszych niż aktualna zatwierdzona*/
			if ($last != 0 && ($REV > $last || $REV == 0))
				$REV = $last;
	}
	function find_lastest_approved() {
		global $ID;
		$m = p_get_metadata($ID);
		$sum = $m['last_change']['sum'];
		if ($sum == APPROVED)
			return 0;

		$changelog = new PageChangeLog($ID);
		//wyszukaj najnowszej zatwierdzonej
		//poszukaj w dół
		$chs = $changelog->getRevisions(0, 10000);
		foreach ($chs as $rev) {
			$ch = $changelog->getRevisionInfo($rev);
			if ($ch['sum'] == APPROVED)
				return $rev;
		}
		return -1;
	}

    function handle_display_banner(&$event, $param) {
		global $ID, $REV, $INFO;

        if($event->data != 'show') return;
		if (!$INFO['exists']) return;

		$m = p_get_metadata($ID);
		$changelog = new PageChangeLog($ID);

		//sprawdź status aktualnej strony
		if ($REV != 0) {
			$ch = $changelog->getRevisionInfo($REV);
			$sum = $ch['sum'];
		} else {
			$sum = $m['last_change']['sum'];
		}

		if ($sum != APPRVOED) {
			$class = 'approved_no';
			$last_approved_rev = $this->find_lastest_approved();
		}

		
		ptln('<div class="approval '.($sum == APPROVED ? 'approved_yes' : 'approved_no').'">');

		tpl_pageinfo();
		ptln(' | ');
		if ($sum == APPROVED) {
			ptln('<span>'.$this->getLang('approved').'</span>');
			if ($REV != 0 && auth_quickaclcheck($ID) > AUTH_READ) {
				ptln('<a href="'.wl($ID).'">');
				ptln($this->getLang($m['last_change']['sum'] == APPROVED ? 'newest_approved' : 'newest_draft'));
				ptln('</a>');
			} else if ($REV != 0 && $REV != $last_approved_rev) {
				ptln('<a href="'.wl($ID).'">');
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
			if ($REV == 0 && $this->can_approve()) {
				ptln('<a href="'.wl($ID, array('rev' => $last_approved_rev, 'do' => 'diff',
				'approve' => 'approve')).'">');
					ptln($this->getLang('approve'));
				ptln('</a>');
			}
		}
		ptln('</div>');
	}

}
