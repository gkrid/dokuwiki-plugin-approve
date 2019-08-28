<?php

// must be run within DokuWiki
if(!defined('DOKU_INC')) die();


class syntax_plugin_approve_table extends DokuWiki_Syntax_Plugin {

    protected $states = ['approved', 'draft', 'ready for approval'];

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
            'states' => $this->states,
            'summarize' => true,
            'maintainer' => null
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
                $value = array_map('ucfirst', $value);
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

        if ($params['maintainer'] == '$USER$') {
            $renderer->meta['plugin'][$plugin_name]['dynamic_maintainer'] = true;
        }

        $renderer->meta['plugin'][$plugin_name]['approve_table'] = true;
    }

    public function renderXhtml(Doku_Renderer $renderer, $params)
    {
        global $INFO;

        global $conf;
        /** @var DokuWiki_Auth_Plugin $auth */
        global $auth;

        /** @var \helper_plugin_ireadit_db $db_helper */
        $db_helper = plugin_load('helper', 'approve_db');
        $sqlite = $db_helper->getDB();

        if ($params['maintainer'] == '$USER$') {
            $params['maintainer'] = $INFO['client'];
        }

        $maintainer_query = '';
        $query_args = [$params['namespace'].'%'];
        if ($params['maintainer']) {
            $maintainer_query .= " AND page.maintainer LIKE ?";
            $query_args[] = $params['maintainer'];
        }
        if ($params['filter']) {
            $maintainer_query .= " AND page.page REGEXP ?";
            $query_args[] = $params['filter'];
        }

        $q = "SELECT page.page, page.maintainer, revision.rev, revision.approved, revision.approved_by,
                    revision.ready_for_approval, revision.ready_for_approval_by,
                    LENGTH(page.page) - LENGTH(REPLACE(page.page, ':', '')) AS colons
                    FROM page INNER JOIN revision ON page.page = revision.page
                    WHERE page.hidden = 0 AND revision.current=1 AND page.page LIKE ? ESCAPE '_'
                            $maintainer_query
                    ORDER BY colons, page.page";

        $res = $sqlite->query($q, $query_args);
        $pages = $sqlite->res2arr($res);

        // Output Table
        $renderer->doc .= '<table><tr>';
        $renderer->doc .= '<th>' . $this->getLang('hdr_page') . '</th>';
        $renderer->doc .= '<th>' . $this->getLang('hdr_state') . '</th>';
        $renderer->doc .= '<th>' . $this->getLang('hdr_updated') . '</th>';
        $renderer->doc .= '<th>' . $this->getLang('hdr_maintainer') . '</th>';
        $renderer->doc .= '</tr>';


        $all_approved = 0;
        $all_approved_ready = 0;
        $all = 0;

        $curNS = '';
        foreach($pages as $page) {
            $id = $page['page'];
            $maintainer = $page['maintainer'];
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
            if ($maintainer) {
                $user = $auth->getUserData($maintainer);
                if ($user) {
                    $renderer->doc .= $user['name'];
                } else {
                    $renderer->doc .= $maintainer;
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
