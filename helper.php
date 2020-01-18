<?php

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();
class helper_plugin_approve extends DokuWiki_Plugin {

    /**
     * @param helper_plugin_sqlite $sqlite
     * @return string
     */
    public function no_apr_namespace(helper_plugin_sqlite $sqlite) {
        //check for config update
        $key = 'no_apr_namespaces';
        $res = $sqlite->query('SELECT value FROM config WHERE key=?', $key);
        $no_apr_namespaces_db = $sqlite->res2single($res);
        $no_apr_namespaces_conf = $this->getConf($key);
        //update internal config
        if ($no_apr_namespaces_db != $no_apr_namespaces_conf) {
            $sqlite->query('UPDATE config SET value=? WHERE key=?', $no_apr_namespaces_conf, $key);

            $res = $sqlite->query('SELECT page, hidden FROM page');
            $pages = $sqlite->res2arr($res);
            foreach ($pages as $page) {
                $id = $page['page'];
                $hidden = $page['hidden'];
                $in_hidden_namespace = $this->in_hidden_namespace($sqlite, $id, $no_apr_namespaces_conf);
                $new_hidden = $in_hidden_namespace ? '1' : '0';

                if ($hidden != $new_hidden) {
                    $sqlite->query('UPDATE page SET hidden=? WHERE page=?', $new_hidden, $id);
                }
            }
        }

        return $no_apr_namespaces_conf;
    }

    /**
     * @param helper_plugin_sqlite $sqlite
     * @param $id
     * @param null $approver
     * @return bool
     */
    public function use_approve_here(helper_plugin_sqlite $sqlite, $id, &$approver=null) {

        //check if we should update no_apr_namespace
        $this->no_apr_namespace($sqlite);

        $res = $sqlite->query('SELECT page, approver FROM page WHERE page=? AND hidden=0', $id);
        $row = $sqlite->res2row($res);
        $approver = $row['approver'];
        if ($row) {
            return true;
        }
        return false;
    }

    /**
     * @param helper_plugin_sqlite $sqlite
     * @param $id
     * @return bool|string
     */
    public function find_last_approved(helper_plugin_sqlite $sqlite, $id) {
        $res = $sqlite->query('SELECT rev FROM revision
                                WHERE page=? AND approved IS NOT NULL
                                ORDER BY rev DESC LIMIT 1', $id);
        return $sqlite->res2single($res);
    }

    /**
     * @param helper_plugin_sqlite $sqlite
     * @param null $no_apr_namespaces
     * @return array|array[]|false|string[]
     */
    public function get_hidden_namespaces_list(helper_plugin_sqlite $sqlite, $no_apr_namespaces=null) {
        if (!$no_apr_namespaces) {
            $no_apr_namespaces = $this->no_apr_namespace($sqlite);
        }

        $no_apr_namespaces_list = preg_split('/\s+/', $no_apr_namespaces,-1,
            PREG_SPLIT_NO_EMPTY);
        $no_apr_namespaces_list = array_map(function ($namespace) {
            return ltrim($namespace, ':');
        }, $no_apr_namespaces_list);

        return $no_apr_namespaces_list;
    }

    /**
     * @param helper_plugin_sqlite $sqlite
     * @param $id
     * @param null $no_apr_namespaces
     * @return bool|string
     */
    public function in_hidden_namespace(helper_plugin_sqlite $sqlite, $id, $no_apr_namespaces=null) {
        $no_apr_namespaces_list = $this->get_hidden_namespaces_list($sqlite, $no_apr_namespaces);
        $id = ltrim($id, ':');
        foreach ($no_apr_namespaces_list as $namespace) {
            if (substr($id, 0, strlen($namespace)) == $namespace) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param helper_plugin_sqlite $sqlite
     * @return array
     */
    public function weighted_assignments(helper_plugin_sqlite $sqlite) {
        $res = $sqlite->query('SELECT id,namespace,approver FROM maintainer');
        $assignments = $sqlite->res2arr($res);

        $weighted_assignments = [];
        foreach ($assignments as $assignment) {
            $ns = $assignment['namespace'];
            //more general namespaces are overridden by more specific ones.
            if (substr($ns, -1) == '*') {
                $weight = substr_count($ns, ':');
            } else {
                $weight = PHP_INT_MAX;
            }

            $assignment['weight'] = $weight;
            $weighted_assignments[] = $assignment;
        }
        array_multisort(array_column($weighted_assignments, 'weight'), $weighted_assignments);

        return $weighted_assignments;
    }

    /**
     * @param helper_plugin_sqlite $sqlite
     * @param $id
     * @param null $pageApprover
     * @param null $weighted_assignments
     * @return bool
     */
    public function isPageAssigned(helper_plugin_sqlite $sqlite, $id, &$pageApprover=null, $weighted_assignments=null) {
        if (!$weighted_assignments) {
            $weighted_assignments = $this->weighted_assignments($sqlite);
        }
        foreach ($weighted_assignments as $assignment) {
            $ns = ltrim($assignment['namespace'], ':');
            $approver = $assignment['approver'];
            if (substr($ns, -2) == '**') {
                //remove '**'
                $ns = substr($ns, 0, -2);
                if (substr($id, 0, strlen($ns)) == $ns) {
                    $newAssignment = true;
                    $pageApprover = $approver;
                }
            } elseif (substr($ns, -1) == '*') {
                //remove '*'
                $ns = substr($ns, 0, -1);
                $noNS = substr($id, strlen($id));
                if (strpos($noNS, ':') === FALSE &&
                    substr($id, 0, strlen($ns)) == $ns) {
                    $newAssignment = true;
                    $pageApprover = $approver;
                }
            } elseif($id == $ns) {
                $newAssignment = true;
                $pageApprover = $approver;
            }
        }
        return $newAssignment;
    }

    /**
     * @param $id
     * @param string $pageApprover
     * @return bool
     */
    public function client_can_approve($id, $pageApprover) {
        global $INFO;
        //user not log in
        if (!isset($INFO['userinfo'])) return false;

        //no approver provided, check if approve plugin apply here
        if ($pageApprover == $INFO['client']) {
            return true;
        } elseif (auth_quickaclcheck($id) >= AUTH_DELETE &&
            (!$pageApprover || !$this->getConf('strict_approver'))) {
            return true;
        }

        return false;
    }

    /**
     * @param $id
     * @return bool
     */
    public function client_can_mark_ready_for_approval($id) {
        return auth_quickaclcheck($id) >= AUTH_EDIT;
    }

    /**
     * @param $id
     * @return bool
     */
    public function client_can_see_drafts($id, $pageApprover) {
        if (!$this->getConf('hide_drafts_for_viewers')) return true;

        if (auth_quickaclcheck($id) >= AUTH_EDIT) return true;
        if ($this->client_can_approve($id, $pageApprover)) return true;

        return false;
    }
}
