<?php
/**
 * DokuWiki Plugin bez (Action Component)
 *
 */

// must be run within Dokuwiki

if (!defined('DOKU_INC')) die();

/**
 * Class action_plugin_bez_migration
 *
 * Handle migrations that need more than just SQL
 */
class action_plugin_approve_migration extends DokuWiki_Action_Plugin
{
    /**
     * @inheritDoc
     */
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('PLUGIN_SQLITE_DATABASE_UPGRADE', 'AFTER', $this, 'handle_migrations');
    }

    /**
     * Call our custom migrations when defined
     *
     * @param Doku_Event $event
     * @param $param
     */
    public function handle_migrations(Doku_Event $event, $param)
    {
        if ($event->data['sqlite']->getAdapter()->getDbname() !== 'approve') {
            return;
        }
        $to = $event->data['to'];

        if (is_callable([$this, "migration$to"])) {
            $event->result = call_user_func([$this, "migration$to"], $event->data);
        }
    }

    /**
     * Convenience function to run an INSERT ... ON CONFLICT IGNORE operation
     *
     * The function takes a key-value array with the column names in the key and the actual value in the value,
     * build the appropriate query and executes it.
     *
     * @param string $table the table the entry should be saved to (will not be escaped)
     * @param array $entry A simple key-value pair array (only values will be escaped)
     * @return bool|SQLiteResult
     */
    protected function insertOrIgnore(helper_plugin_sqlite $sqlite, $table, $entry) {
        $keys = join(',', array_keys($entry));
        $vals = join(',', array_fill(0,count($entry),'?'));

        $sql = "INSERT OR IGNORE INTO $table ($keys) VALUES ($vals)";
        return $sqlite->query($sql, array_values($entry));
    }

    protected function migration1($data)
    {
        global $conf;

        /** @var helper_plugin_sqlite $sqlite */
        $sqlite = $data['sqlite'];
        $db = $sqlite->getAdapter()->getDb();


        $datadir = $conf['datadir'];
        if (substr($datadir, -1) != '/') {
            $datadir .= '/';
        }

        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($datadir));
        $pages = [];
        foreach ($rii as $file) {
            if ($file->isDir()){
                continue;
            }

            //remove start path and extension
            $page = substr($file->getPathname(), strlen($datadir), -4);
            $pages[] = str_replace('/', ':', $page);
        }

        $db->beginTransaction();

        $apr_namespaces = preg_split('/\s+/', $this->getConf('apr_namespaces', ''),
            -1,PREG_SPLIT_NO_EMPTY);

        if (!$apr_namespaces) {
            $sqlite->storeEntry('maintainer',[
                'namespace' => '**'
            ]);
        } else {
            foreach ($apr_namespaces as $namespace) {
                $namespace = rtrim($namespace, ':');
                $namespace .= ':**';
                $sqlite->storeEntry('maintainer',[
                    'namespace' => $namespace
                ]);
            }
        }

        //store config
        $no_apr_namespaces = $this->getConf('no_apr_namespaces', '');
        $sqlite->storeEntry('config',[
            'key' => 'no_apr_namespaces',
            'value' => $no_apr_namespaces
        ]);

        $no_apr_namespaces_list = preg_split('/\s+/', $no_apr_namespaces,-1,
            PREG_SPLIT_NO_EMPTY);
        $no_apr_namespaces_list = array_map(function ($namespace) {
            return trim($namespace, ':');
        }, $no_apr_namespaces_list);


        foreach ($pages as $page) {
            //import historic data
            $versions = p_get_metadata($page, 'plugin_approve_versions');
            if (!$versions) {
                $versions = $this->render_metadata_for_approved_page($page);
            }

//            $last_change_date = p_get_metadata($page, 'last_change date');
            $last_change_date = @filemtime(wikiFN($page));
            $last_version = $versions[0];

            //remove current versions to not process it here
            unset($versions[0]);
            unset($versions[$last_change_date]);

            $revision_editors = $this->revision_editors($page);
            foreach ($versions as $rev => $version) {
                $data = [
                    'page' => $page,
                    'rev' => $rev,
                    'approved' => date('c', $rev),
                    'approved_by' => $revision_editors[$rev],
                    'version' => $version
                ];
                $sqlite->storeEntry('revision', $data);
            }

            //process current data
            $summary = p_get_metadata($page, 'last_change sum');
            $user = p_get_metadata($page, 'last_change user');
            $data = [
                'page' => $page,
                'rev' => $last_change_date,
                'current' => 1
            ];
            if ($this->getConf('ready_for_approval') &&
                $summary == $this->getConf('sum ready for approval')) {
                $data['ready_for_approval'] = date('c', $last_change_date);
                $data['ready_for_approval_by'] = $user;
            } elseif($summary == $this->getConf('sum approved')) {
                $data['approved'] = date('c', $last_change_date);
                $data['approved_by'] = $user;
                $data['version'] = $last_version;
            }
            $sqlite->storeEntry('revision', $data);


            //empty apr_namespaces - all match
            if (!$apr_namespaces) {
                $in_apr_namespace = true;
            } else {
                $in_apr_namespace = false;
                foreach ($apr_namespaces as $namespace) {
                    if (substr($page, 0, strlen($namespace)) == $namespace) {
                        $in_apr_namespace = true;
                        break;
                    }
                }
            }

            if ($in_apr_namespace) {
                $hidden = '0';
                foreach ($no_apr_namespaces_list as $namespace) {
                    if (substr($page, 0, strlen($namespace)) == $namespace) {
                        $hidden = '1';
                        break;
                    }
                }
                $sqlite->storeEntry('page', [
                    'page' => $page,
                    'hidden' => $hidden
                ]);
            }
        }


        $db->commit();

        return true;
    }

    /**
     * Calculate current version
     *
     * @param $id
     * @return array
     */
    protected function render_metadata_for_approved_page($id, $currev=false) {
        if (!$currev) $currev = @filemtime(wikiFN($id));

        $version = $this->approved($id);
        //version for current page
        $curver = $version + 1;
        $versions = array(0 => $curver, $currev => $curver);

        $changelog = new PageChangeLog($id);
        $first = 0;
        $num = 100;
        while (count($revs = $changelog->getRevisions($first, $num)) > 0) {
            foreach ($revs as $rev) {
                $revInfo = $changelog->getRevisionInfo($rev);
                if ($revInfo['sum'] == $this->getConf('sum approved')) {
                    $versions[$rev] = $version;
                    $version -= 1;
                }
            }
            $first += $num;
        }

//        p_set_metadata($id, array(ApproveConst::METADATA_VERSIONS_KEY => $versions));

        return $versions;
    }

    /**
     * Get the number of approved pages
     * @param $id
     * @return int
     */
    protected function approved($id) {
        $count = 0;

        $changelog = new PageChangeLog($id);
        $first = 0;
        $num = 100;
        while (count($revs = $changelog->getRevisions($first, $num)) > 0) {
            foreach ($revs as $rev) {
                $revInfo = $changelog->getRevisionInfo($rev);
                if ($revInfo['sum'] == $this->getConf('sum approved')) {
                    $count += 1;
                }
            }
            $first += $num;
        }

        return $count;
    }

    /**
     * Calculate current version
     *
     * @param $id
     * @return array
     */
    protected function revision_editors($id)
    {
        $currev = @filemtime(wikiFN($id));
        $user = p_get_metadata($id, 'last_change user');

        $revision_editors = array($currev => $user);

        $changelog = new PageChangeLog($id);
        $first = 0;
        $num = 100;
        while (count($revs = $changelog->getRevisions($first, $num)) > 0) {
            foreach ($revs as $rev) {
                $revInfo = $changelog->getRevisionInfo($rev);
                $revision_editors[$rev] = $revInfo['user'];
            }
            $first += $num;
        }

        return $revision_editors;
    }
}
