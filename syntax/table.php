<?php

// must be run within DokuWiki
if(!defined('DOKU_INC')) die();


class syntax_plugin_approve_table extends DokuWiki_Syntax_Plugin {

    protected $states = ['approved', 'draft', 'ready_for_approval'];

    function getType() {
        return 'substition';
    }

    function getSort() {
        return 20;
    }

    function PType() {
        return 'block';
    }

    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('----+ *approve table *-+\n.*?----+', $mode,'plugin_approve_table');
    }

    function handle($match, $state, $pos, Doku_Handler $handler){
        $lines = explode("\n", $match);
        array_shift($lines);
        array_pop($lines);

        $params = [
            'namespace' => '',
            'filter' => false,
            'states' => [],
            'summarize' => true,
            'approver' => null
        ];

        foreach ($lines as $line) {
            $pair = explode(':', $line, 2);
            if (count($pair) < 2) {
                continue;
            }
            $key = trim($pair[0]);
            $value = trim($pair[1]);
            if ($key == 'states') {
                $value = array_map('trim', explode(',', $value));
                //normalize
                $value = array_map('strtolower', $value);
                foreach ($value as $state) {
                    if (!in_array($state, $this->states)) {
                        msg('approve plugin: unknown state "'.$state.'" should be: ' .
                            implode(', ', $this->states), -1);
                        return false;
                    }
                }
            } elseif($key == 'filter') {
                $value = trim($value, '/');
                if (preg_match('/' . $value . '/', null) === false) {
                    msg('approve plugin: invalid filter regex', -1);
                    return false;
                }
            } elseif ($key == 'summarize') {
                $value = $value == '0' ? false : true;
            } elseif ($key == 'namespace') {
                $value = trim(cleanID($value), ':');
            }
            $params[$key] = $value;
        }
        return $params;
    }

    /**
     * Render xhtml output or metadata
     *
     * @param string        $mode     Renderer mode (supported modes: xhtml)
     * @param Doku_Renderer $renderer The renderer
     * @param array         $data     The data from the handler() function
     *
     * @return bool If rendering was successful.
     */

    public function render($mode, Doku_Renderer $renderer, $data)
    {
        $method = 'render' . ucfirst($mode);
        if (method_exists($this, $method)) {
            call_user_func([$this, $method], $renderer, $data);
            return true;
        }
        return false;
    }

    /**
     * Render metadata
     *
     * @param Doku_Renderer $renderer The renderer
     * @param array         $data     The data from the handler() function
     */
    public function renderMetadata(Doku_Renderer $renderer, $params)
    {
        $plugin_name = $this->getPluginName();
        $renderer->meta['plugin'][$plugin_name] = [];

        if ($params['approver'] == '$USER$') {
            $renderer->meta['plugin'][$plugin_name]['dynamic_approver'] = true;
        }

        $renderer->meta['plugin'][$plugin_name]['approve_table'] = true;
    }

    protected function array_equal($a, $b) {
        return (
            is_array($a)
            && is_array($b)
            && count($a) == count($b)
            && array_diff($a, $b) === array_diff($b, $a)
        );
    }

    public function renderXhtml(Doku_Renderer $renderer, $params)
    {
        global $INFO;

        global $conf;
        /** @var DokuWiki_Auth_Plugin $auth */
        global $auth;

        try {
            /** @var \helper_plugin_approve_db $db_helper */
            $db_helper = plugin_load('helper', 'approve_db');
            $sqlite = $db_helper->getDB();
        } catch (Exception $e) {
            msg($e->getMessage(), -1);
            return;
        }

        if ($params['approver'] == '$USER$') {
            $params['approver'] = $INFO['client'];
        }

        $approver_query = '';
        $query_args = [$params['namespace'].'%'];
        if ($params['approver']) {
            $approver_query .= " AND page.approver LIKE ?";
            $query_args[] = $params['approver'];
        }

        if ($params['filter']) {
            $approver_query .= " AND page.page REGEXP ?";
            $query_args[] = $params['filter'];
        }

        //if all 3 states are enabled nothing is filtered
        if ($params['states'] && count($params['states']) < 3) {
            if ($this->array_equal(['draft'], $params['states'])) {
                $approver_query .= " AND revision.ready_for_approval IS NULL AND revision.approved IS NULL";
            } elseif ($this->array_equal(['ready_for_approval'], $params['states'])) {
                $approver_query .= " AND revision.ready_for_approval IS NOT NULL AND revision.approved IS NULL";
            } elseif ($this->array_equal(['approved'], $params['states'])) {
                $approver_query .= " AND revision.approved IS NOT NULL";
            } elseif ($this->array_equal(['draft', 'ready_for_approval'], $params['states'])) {
                $approver_query .= " AND revision.approved IS NULL";
            } elseif ($this->array_equal(['draft', 'approved'], $params['states'])) {
                $approver_query .= " AND (revision.approved IS NOT NULL OR (revision.approved IS NULL AND revision.ready_for_approval IS NULL))";
            } elseif ($this->array_equal(['ready_for_approval', 'approved'], $params['states'])) {
                $approver_query .= " AND (revision.ready_for_approval IS NOT NULL OR revision.approved IS NOT NULL)";
            }
        }

        $q = "SELECT page.page, page.approver, revision.rev, revision.approved, revision.approved_by,
                    revision.ready_for_approval, revision.ready_for_approval_by,
                    LENGTH(page.page) - LENGTH(REPLACE(page.page, ':', '')) AS colons
                    FROM page INNER JOIN revision ON page.page = revision.page
                    WHERE page.hidden = 0 AND revision.current=1 AND page.page LIKE ? ESCAPE '_'
                            $approver_query
                    ORDER BY colons, page.page";

        $res = $sqlite->query($q, $query_args);
        $pages = $sqlite->res2arr($res);

        // Output Table
        $renderer->doc .= '<table><tr>';
        $renderer->doc .= '<th>' . $this->getLang('hdr_page') . '</th>';
        $renderer->doc .= '<th>' . $this->getLang('hdr_state') . '</th>';
        $renderer->doc .= '<th>' . $this->getLang('hdr_updated') . '</th>';
        $renderer->doc .= '<th>' . $this->getLang('hdr_approver') . '</th>';
        $renderer->doc .= '</tr>';


        $all_approved = 0;
        $all_approved_ready = 0;
        $all = 0;

        $curNS = '';
        foreach($pages as $page) {
            $id = $page['page'];
            $approver = $page['approver'];
            $rev = $page['rev'];
            $approved = strtotime($page['approved']);
            $approved_by = $page['approved_by'];
            $ready_for_approval = strtotime($page['ready_for_approval']);
            $ready_for_approval_by = $page['ready_for_approval_by'];

            $pageNS = getNS($id);

            if($pageNS != '' && $pageNS != $curNS) {
                $curNS = $pageNS;

                $renderer->doc .= '<tr><td colspan="4"><a href="';
                $renderer->doc .= wl($curNS);
                $renderer->doc .= '">';
                $renderer->doc .= $curNS;
                $renderer->doc .= '</a> ';
                $renderer->doc .= '</td></tr>';
            }

            $all += 1;
            if ($approved) {
                $class = 'plugin__approve_green';
                $state = $this->getLang('approved');
                $date = $approved;
                $by = $approved_by;

                $all_approved += 1;
            } elseif ($this->getConf('ready_for_approval') && $ready_for_approval) {
                $class = 'plugin__approve_ready';
                $state = $this->getLang('marked_approve_ready');
                $date = $ready_for_approval;
                $by = $ready_for_approval_by;

                $all_approved_ready += 1;
            } else {
                $class = 'plugin__approve_red';
                $state = $this->getLang('draft');
                $date = $rev;
                $by = p_get_metadata($id, 'last_change user');
            }

            $renderer->doc .= '<tr class="'.$class.'">';
            $renderer->doc .= '<td><a href="';
            $renderer->doc .= wl($id);
            $renderer->doc .= '">';
            if ($conf['useheading'] == '1') {
                $heading = p_get_first_heading($id);
                if ($heading != '') {
                    $renderer->doc .= $heading;
                } else {
                    $renderer->doc .= $id;
                }
            } else {
                $renderer->doc .= $id;
            }

            $renderer->doc .= '</a></td><td>';
            $renderer->doc .= '<strong>'.$state. '</strong> ';

            $user = $auth->getUserData($by);
            if ($user) {
                $renderer->doc .= $this->getLang('by'). ' ' . $user['name'];
            }
            $renderer->doc .= '</td><td>';
            $renderer->doc .= '<a href="' . wl($id) . '">' . dformat($date) . '</a>';;
            $renderer->doc .= '</td><td>';
            if ($approver) {
                $user = $auth->getUserData($approver);
                if ($user) {
                    $renderer->doc .= $user['name'];
                } else {
                    $renderer->doc .= $approver;
                }
            } else {
                $renderer->doc .= '---';
            }
            $renderer->doc .= '</td></tr>';
        }

        if ($params['summarize']) {
            if($this->getConf('ready_for_approval')) {
                $renderer->doc .= '<tr><td><strong>';
                $renderer->doc .= $this->getLang('all_approved_ready');
                $renderer->doc .= '</strong></td>';

                $renderer->doc .= '<td colspan="3">';
                $percent       = 0;
                if($all > 0) {
                    $percent = $all_approved_ready * 100 / $all;
                }
                $renderer->doc .= $all_approved_ready . ' / ' . $all . sprintf(" (%.0f%%)", $percent);
                $renderer->doc .= '</td></tr>';
            }

            $renderer->doc .= '<tr><td><strong>';
            $renderer->doc .= $this->getLang('all_approved');
            $renderer->doc .= '</strong></td>';

            $renderer->doc .= '<td colspan="3">';
            $percent       = 0;
            if($all > 0) {
                $percent = $all_approved * 100 / $all;
            }
            $renderer->doc .= $all_approved . ' / ' . $all . sprintf(" (%.0f%%)", $percent);
            $renderer->doc .= '</td></tr>';
        }

        $renderer->doc .= '</table>';
    }
}
