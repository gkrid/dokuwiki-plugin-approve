<?php

if(!defined('DOKU_INC')) die();
define(APPROVED, 'Approved');

class action_plugin_approve_approve extends DokuWiki_Action_Plugin {

    function register(Doku_Event_Handler $controller) {
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, handle_approve, array());
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, handle_viewer, array());
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
		return auth_quickaclcheck($ID) >= AUTH_DELETE;
	}

	function handle_approve(&$event, $param) {
		global $ID, $REV, $INFO;
		if ($event->data == 'show' && isset($_GET['approve'])) {
		    if ( ! $this->can_approve()) return;
		    
			//change last commit comment to Approved
			$meta = p_read_metadata($ID);
			$meta[current][last_change][sum] = $meta[persistent][last_change][sum] = APPROVED;
			$meta[current][last_change][user] = $meta[persistent][last_change][user] = $INFO[client];
			if (!array_key_exists($INFO[client], $meta[current][contributor])) {
			    $meta[current][contributor][$INFO[client]] = $INFO[userinfo][name];
			    $meta[persistent][contributor][$INFO[client]] = $INFO[userinfo][name];
			}
			p_save_metadata($ID, $meta);
			//update changelog
			//remove last line from file
			$changelog_file = metaFN($ID, '.changes');
			$changes = file($changelog_file, FILE_SKIP_EMPTY_LINES);
			$lastLogLine = array_pop($changes);
			$info = parseChangelogLine($lastLogLine);
			
			$info[user] = $INFO[client];
			$info[sum] = APPROVED;
			
			$logline = implode("\t", $info)."\n";
			array_push($changes, $logline);
			
			io_saveFile($changelog_file, implode('', $changes));
			
			header('Location: ?id='.$ID);
		}
	}
    function handle_viewer(&$event, $param) {
        global $REV, $ID;
        if ($event->data != 'show') return;
        if (auth_quickaclcheck($ID) > AUTH_READ) return;
        
	    $last = $this->find_lastest_approved();
	    //no page is approved
		if ($last == -1) return;
		//approved page is the newest page
		if ($last == 0) return;
		
		//if we are viewing lastest revision, show last approved
		if ($REV == 0) header("Location: ?id=$ID&rev=$last");
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

		ptln('<div class="approval '.($sum == APPROVED ? 'approved_yes' : 'approved_no').'">');

		tpl_pageinfo();
		ptln(' | ');
		$last_approved_rev = $this->find_lastest_approved();
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

			if ($last_approved_rev == -1) {
			    if ($REV != 0) {
				    ptln('<a href="'.wl($ID).'">');
				    	ptln($this->getLang('newest_draft'));
				    ptln('</a>');
				}
			} else {
				if ($last_approved_rev != 0)
					ptln('<a href="'.wl($ID, array('rev' => $last_approved_rev)).'">');
				else
					ptln('<a href="'.wl($ID).'">');

					ptln($this->getLang('newest_approved'));
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
