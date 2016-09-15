<?php

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();
class helper_plugin_approve extends DokuWiki_Plugin { 
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
}
