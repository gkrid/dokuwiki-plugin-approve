<?php

use dokuwiki\plugin\approve\meta\ApproveConst;
use dokuwiki\plugin\approve\meta\PageSearch;

// must be run within DokuWiki
if(!defined('DOKU_INC')) die();


class syntax_plugin_approve_table extends DokuWiki_Syntax_Plugin {

    protected $states = [];

    public function __construct() {
        $this->states = [$this->getConf('sum approved'),
                         $this->getConf('sum ready for approval'),
                         $this->getConf('sum draft')];
    }

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

        $params = [];
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
            } elseif($key == 'filter' && preg_match($value, null) === false) {
                msg('approve plugin: invalid filter regex', -1);
                return false;
            } elseif ($key == 'summarize') {
                $value = $value == '0' ? false : true;
            }
            $params[$key] = $value;
        }
        return $params;
    }

    function render($mode, Doku_Renderer $renderer, $params) {
        global $conf;

        if ($mode != 'xhtml') return false;
        if ($params === false) return false;

        $pageSearch = new PageSearch();

        $defaults = [
            'namespace' => '',
            'filter' => false,
            'states' => $this->states,
            'summarize' => true,
        ];

        $params = array_replace($defaults, $params);

        $namespace = cleanID(getNS($params['namespace'] . ":*"));

        $pages = $pageSearch->getPagesFromNamespace($namespace, $params['filter'], $params['states']);

        usort($pages, array($pageSearch, 'pageSorter'));

        // Output Table
        $renderer->doc .= '<table><tr>';
        $renderer->doc .= '<th>' . $this->getLang('hdr_page') . '</th>';
        $renderer->doc .= '<th>' . $this->getLang('hdr_state') . '</th>';
        $renderer->doc .= '<th>' . $this->getLang('hdr_updated') . '</th>';
        $renderer->doc .= '</tr>';


        $all_approved = 0;
        $all_approved_ready = 0;
        $all = 0;

        $working_ns = null;
        foreach($pages as $page) {
            // $page: 0 -> pagename, 1 -> true -> approved else false, 2 -> last changed date
            $this_ns = getNS($page[0]);

            if($this_ns != $working_ns) {
                $name_ns = $this_ns;
                if($this_ns == '') { $name_ns = 'root'; }
                $renderer->doc .= '<tr><td colspan="3"><a href="';
                $renderer->doc .= wl($this_ns . ':' . $this->getConf('start'));
                $renderer->doc .= '">';
                $renderer->doc .= $name_ns;
                $renderer->doc .= '</a> ';
                $renderer->doc .= '</td></tr>';
                $working_ns = $this_ns;
            }

            $updated = '<a href="' . wl($page[0]) . '">' . dformat($page[2]) . '</a>';

            $class = 'plugin__approve_red';
            $state = $this->getLang('draft');
            $all += 1;

            if ($page[1] === 'approved') {
                $class = 'plugin__approve_green';
                $state = $this->getLang('approved');
                $all_approved += 1;
            } elseif ($page[1] === 'ready for approval' && $this->getConf('ready_for_approval') === 1) {
                $class = 'plugin__approve_ready';
                $state = $this->getLang('marked_approve_ready');
                $all_approved_ready += 1;
            }

            $renderer->doc .= '<tr class="'.$class.'">';
            $renderer->doc .= '<td><a href="';
            $renderer->doc .= wl($page[0]);
            $renderer->doc .= '">';
            if ($conf['useheading'] === '1') {
                $heading = p_get_first_heading($page[0]);
                if ($heading != '') {
                    $renderer->doc .= $heading;
                } else {
                    $renderer->doc .= $page[0];
                }

            } else {
                $renderer->doc .= $page[0];
            }

            $renderer->doc .= '</a></td><td>';
            $renderer->doc .= '<strong>'.$state. '</strong> '. $this->getLang('by'). ' ' . $page[4];
            $renderer->doc .= '</td><td>';
            $renderer->doc .= $updated;
            $renderer->doc .= '</td></tr>';
        }

        if ($params['summarize']) {
            if($this->getConf('ready_for_approval') === 1) {
                $renderer->doc .= '<tr><td><strong>';
                $renderer->doc .= $this->getLang('all_approved_ready');
                $renderer->doc .= '</strong></td>';

                $renderer->doc .= '<td colspan="2">';
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

            $renderer->doc .= '<td colspan="2">';
            $percent       = 0;
            if($all > 0) {
                $percent = $all_approved * 100 / $all;
            }
            $renderer->doc .= $all_approved . ' / ' . $all . sprintf(" (%.0f%%)", $percent);
            $renderer->doc .= '</td></tr>';
        }

        $renderer->doc .= '</table>';
        return true;
    }
}
