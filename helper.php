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
     * @return string
     */
    public function no_apr_namespace() {
        //check for config update
        $key = 'no_apr_namespaces';
        $res = $this->sqlite()->query('SELECT value FROM config WHERE key=?', $key);
        $no_apr_namespaces_db = $this->sqlite()->res2single($res);
        $no_apr_namespaces_conf = $this->getConf($key);
        //update internal config
        if ($no_apr_namespaces_db != $no_apr_namespaces_conf) {
            $this->sqlite()->query('UPDATE config SET value=? WHERE key=?', $no_apr_namespaces_conf, $key);

            $res = $this->sqlite()->query('SELECT page, hidden FROM page');
            $pages = $this->sqlite()->res2arr($res);
            foreach ($pages as $page) {
                $id = $page['page'];
                $hidden = $page['hidden'];
                $in_hidden_namespace = $this->in_hidden_namespace($id, $no_apr_namespaces_conf);
                $new_hidden = $in_hidden_namespace ? '1' : '0';

                if ($hidden != $new_hidden) {
                    $this->sqlite()->query('UPDATE page SET hidden=? WHERE page=?', $new_hidden, $id);
                }
            }
        }

        return $no_apr_namespaces_conf;
    }

    /**
     * @param $id
     * @param null $maintainer
     * @return bool
     */
    public function use_approve_here($id, &$maintainer=null) {

        //check if we should update no_apr_namespace
        $this->no_apr_namespace();

        $res = $this->sqlite()->query('SELECT page, maintainer FROM page WHERE page=? AND hidden=0', $id);
        $row = $this->sqlite()->res2row($res);
        $maintainer = $row['maintainer'];
        if ($row) {
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
            $no_apr_namespaces = $this->no_apr_namespace();
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
     * @param null $no_apr_namespaces
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
}
