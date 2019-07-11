<?php


namespace dokuwiki\plugin\approve\meta;


class PageSearch
{

    /** @var \helper_plugin_approve */
    protected $helper;

    /**
     * PageSearch constructor.
     */
    public function __construct()
    {
        $this->helper = plugin_load('helper', 'approve');
    }

    /**
     * @param $data
     * @param $base
     * @param $file
     * @param $type
     * @param $lvl
     * @param $opts
     * @return bool
     */
    public function search(&$data, $base, $file, $type, $lvl, $opts)
    {
        global $lang;

        $ns = $opts[0];
        $invalid_ns = $opts[1];
        $page_regex = $opts[2];
        $states = $opts[3];

        if ($type == 'd') {
            return true;
        }

        if (!preg_match('#\.txt$#', $file)) {
            return false;
        }

        $id = pathID($ns . $file);
        if (!empty($invalid_ns) && $this->helper->in_namespace($invalid_ns, $id)) {
            return false;
        }

        //check page_regex
        if ($page_regex && !preg_match($page_regex, $id)) {
            return false;
        }

        //check states
        $meta = p_get_metadata($id);

        //check states
        $sum = $meta['last_change']['sum'];
        if ($sum == $this->helper->getConf('sum approved') && !in_array($this->helper->getConf('sum approved'), $states)) {
            return false;
        }

        if ($sum == $this->helper->getConf('sum ready for approval') &&
            !in_array($this->helper->getConf('sum ready for approval'), $states)) {
            return false;
        }

        if ($sum != $this->helper->getConf('sum approved') &&
            $sum != $this->helper->getConf('sum ready for approval') &&
            !in_array($this->helper->getConf('sum draft'), $states)) {
            return false;
        }

        $date = $meta['date']['modified'];
        if (isset($meta['last_change']) && $meta['last_change']['sum'] === $this->helper->getConf('sum approved')) {
            $approved = 'approved';
        } elseif (isset($meta['last_change']) && $meta['last_change']['sum'] === $this->helper->getConf('sum ready for approval')) {
            $approved = 'ready for approval';
        } else {
            $approved = 'not approved';
        }

        if (isset($meta['last_change'])) {
            $user = $meta['last_change']['user'];

            if (isset($meta['contributor'][$user])) {
                $full_name = $meta['contributor'][$user];
            } else {
                $full_name = $meta['creator'];
            }
        } else {
            $user = '';
            $full_name = '('.$lang['external_edit'].')';
        }


        $data[] = array($id, $approved, $date, $user, $full_name);

        return false;
    }

    /**
     * @param $namespace
     * @param bool $page_regex
     * @param array $states
     * @return array
     */
    public function getPagesFromNamespace($namespace, $page_regex=false, $states=[]) {
        global $conf;

        $dir = $conf['datadir'] . '/' . str_replace(':', '/', $namespace);
        $pages = array();
        search($pages, $dir, array($this,'search'),
            array($namespace, $this->helper->getConf('no_apr_namespaces'), $page_regex, $states));

        return $pages;
    }


    /**
     * Custom sort callback
     * @param $a
     * @param $b
     * @return int
     */
    public static function pageSorter($a, $b){
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