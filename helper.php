<?php

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();
class helper_plugin_approve extends DokuWiki_Plugin {

    /** @var helper_plugin_sqlite */
    protected $sqlite;

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
     * @param $id
     * @return bool
     */
    public function use_approve_here($id) {
        $res = $this->sqlite()->query('SELECT page FROM page WHERE page=? AND hidden=0', $id);
        if ($this->sqlite()->res2single($res)) {
            return true;
        }
        return false;
    }

    /**
     * @param $id
     * @return bool|string
     */
    public function find_last_approved($id) {
        $res = $this->sqlite()->query('SELECT rev FROM revision
                                WHERE page=? AND approved IS NOT NULL
                                ORDER BY rev DESC LIMIT 1', $id);
        return $this->sqlite()->res2single($res);
    }

    public function get_hidden_namespaces_list($no_apr_namespaces=null) {
        if (!$no_apr_namespaces) {
            $res = $this->sqlite()->query('SELECT value FROM config WHERE key=?', 'no_apr_namespaces');
            $no_apr_namespaces = $this->sqlite()->res2single($res);
        }

        $no_apr_namespaces_list = preg_split('/\s+/', $no_apr_namespaces,-1,
            PREG_SPLIT_NO_EMPTY);
        $no_apr_namespaces_list = array_map(function ($namespace) {
            return ltrim($namespace, ':');
        }, $no_apr_namespaces_list);

        return $no_apr_namespaces_list;
    }

    /**
     * @param $id
     * @return bool|string
     */
    public function in_hidden_namespace($id, $no_apr_namespaces=null) {
        $no_apr_namespaces_list = $this->get_hidden_namespaces_list($no_apr_namespaces);
        $id = ltrim($id, ':');
        foreach ($no_apr_namespaces_list as $namespace) {
            if (substr($id, 0, strlen($namespace)) == $namespace) {
                return true;
            }
        }
        return false;
    }

//    /**
//     * Check if we should use approve in page
//     *
//     * @param string $id
//     *
//     * @return bool
//     */
//    function use_approve_here($id) {
//        $apr_namespaces = $this->getConf('apr_namespaces');
//        $no_apr_namespaces = $this->getConf('no_apr_namespaces');
//
//        if ($this->in_namespace($no_apr_namespaces, $id)) {
//            return false;
//        //use apr_namespaces
//        } elseif (trim($apr_namespaces) != '') {
//            if ($this->in_namespace($apr_namespaces, $id)) {
//                return true;
//            }
//            return false;
//        }
//
//        return true;
//    }
     /**
     * checks if an id is within one of the namespaces in $namespace_list
     *
     * @param string $namespace_list
     * @param string $id
     *
     * @return bool
     */
    function in_namespace($namespace_list, $id) {
        // PHP apparantly does not have closures -
        // so we will parse $valid ourselves. Wasteful.
        $namespace_list = preg_split('/\s+/', $namespace_list);

        //if(count($valid) == 0) { return true; }//whole wiki matches
        if(count($namespace_list) == 1 && $namespace_list[0] == "") { return false; }//whole wiki matches

        $id = trim($id, ':');
        $id = explode(':', $id);

        // Check against all possible namespaces
        foreach($namespace_list as $namespace) {
            $namespace = explode(':', $namespace);
            $current_ns_depth = 0;
            $total_ns_depth = count($namespace);
            $matching = true;

            // Check each element, untill all elements of $v satisfied
            while($current_ns_depth < $total_ns_depth) {
                if($namespace[$current_ns_depth] != $id[$current_ns_depth]) {
                    // not a match
                    $matching = false;
                    break;
                }
                $current_ns_depth += 1;
            }
            if($matching) { return true; } // a match
        }
        return false;
    }

    function page_sum($ID, $REV) {
		$m = p_get_metadata($ID);
		$changelog = new PageChangeLog($ID);

		//sprawdź status aktualnej strony
		if ($REV != 0) {
			$ch = $changelog->getRevisionInfo($REV);
			$sum = $ch['sum'];
		} else {
			$sum = $m['last_change']['sum'];
		}
		return $sum;
	}
}
