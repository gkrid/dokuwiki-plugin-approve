<?php
// must be run within DokuWiki
if(!defined('DOKU_INC')) die();


class syntax_plugin_approve extends DokuWiki_Syntax_Plugin {

    /**
     * @var helper_plugin_publish
     */
    private $hlp;
    function __construct(){
        $this->hlp = plugin_load('helper','approve');
    }
    
    function pattern() {
        return '\[APPROVALS.*?\]';
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
        $this->Lexer->addSpecialPattern($this->pattern(),$mode,'plugin_approve');
    }

    function handle($match, $state, $pos, Doku_Handler $handler){
        $namespace = substr($match, 11, -1);
        return array($match, $state, $pos, $namespace);
    }

    function render($mode, Doku_Renderer $renderer, $data) {
        global $conf;

        if($mode != 'xhtml') {
            return false;
        }

        list($match, $state, $pos, $namespace) = $data;

        $namespace = cleanID(getNS($namespace . ":*"));

        $pages = $this->_getPagesFromNamespace($namespace);

        usort($pages, array($this,'_pagesorter'));

        // Output Table
        $renderer->doc .= '<table><tr>';
        $renderer->doc .= '<th>' . $this->getLang('hdr_page') . '</th>';
        $renderer->doc .= '<th>' . $this->getLang('hdr_state') . '</th>';
        $renderer->doc .= '<th>' . $this->getLang('hdr_updated') . '</th>';
        $renderer->doc .= '</tr>';


		$all_approved = 0;
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
            
            $class = 'approved_no';
            $state = $this->getLang('draft');
            $all += 1;
            if ($page[1] === true) {
				$class = 'approved_yes';
				$state = $this->getLang('approved');
				$all_approved += 1;
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
            $renderer->doc .= $state;
            $renderer->doc .= '</td><td>';
            $renderer->doc .= $updated;
            $renderer->doc .= '</td></tr>';
        }
        $renderer->doc .= '<tr><td><strong>';
        $renderer->doc .= $this->getLang('all_approved');
        $renderer->doc .= '</strong></td>';
        
        $renderer->doc .= '<td colspan="2">';
        $renderer->doc .= $all_approved.' / '.$all . sprintf(" (%.0f%%)", $all_approved*100/$all);
        $renderer->doc .= '</td>';
        
        $renderer->doc .= '</tr></table>';
        return true;
    }
    
    function _search_helper(&$data, $base, $file, $type, $lvl, $opts) {
		$ns = $opts[0];
        $invalid_ns = $opts[1];

        if ($type == 'd') {
            return true;
        }

        if (!preg_match('#\.txt$#', $file)) {
            return false;
        }

        $id = pathID($ns . $file);
        if (!empty($invalid_ns) && $this->hlp->in_namespace($invalid_ns, $id)) {
            return false;
        }

		$meta = p_get_metadata($id);
		$date = $meta['date']['modified'];
		if (isset($meta['last_change']) && $meta['last_change']['sum'] === 'Approved') {
			$approved = true;
		} else {
			$approved = false;
		}
		
        $data[] = array($id, $approved, $date);

        return false;
	}

    function _getPagesFromNamespace($namespace) {
        global $conf;
        $dir = $conf['datadir'] . '/' . str_replace(':', '/', $namespace);
        $pages = array();
        search($pages, $dir, array($this,'_search_helper'),
				array($namespace, $this->getConf('no_apr_namespaces')));
				
        return $pages;
    }



    /**
     * Custom sort callback
     */
    function _pagesorter($a, $b){
        $ac = explode(':',$a[0]);
        $bc = explode(':',$b[0]);
        $an = count($ac);
        $bn = count($bc);

        // Same number of elements, can just string sort
        if($an == $bn) { return strcmp($a[0], $b[0]); }

        // For each level:
        // If this is not the last element in either list:
        //   same -> continue
        //   otherwise strcmp
        // If this is the last element in either list, it wins
        $n = 0;
        while(true) {
            if($n + 1 == $an) { return -1; }
            if($n + 1 == $bn) { return 1; }
            $s = strcmp($ac[$n], $bc[$n]);
            if($s != 0) { return $s; }
            $n += 1;
        }
    }

}
