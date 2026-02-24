<?php

use dokuwiki\Extension\AuthPlugin;
use dokuwiki\Extension\Plugin;
use dokuwiki\plugin\sqlite\SQLiteDB;

class helper_plugin_approve_db extends Plugin
{
    protected $db;

    protected $no_apr_namespaces_array;

    public function __construct()
    {
        $this->db = new SQLiteDB('approve', DOKU_PLUGIN . 'approve/db/');
        $no_apr_namespaces = $this->getConf('no_apr_namespaces');
        $this->no_apr_namespaces_array  = array_map(function ($namespace) {
            return ltrim($namespace, ':');
        }, preg_split('/\s+/', $no_apr_namespaces,-1,PREG_SPLIT_NO_EMPTY));
        $this->initNoApproveNamespaces();
    }

    public function getDbFile(): string
    {
        return $this->db->getDbFile();
    }

    protected function initNoApproveNamespaces(): void
    {
        $config_key = 'no_apr_namespaces';
        $db_value = $this->getDbConf($config_key);
        $config_value = $this->getConf('no_apr_namespaces');
        if ($db_value !== $config_value) { // $db_value might be null. In this case run the commit anyway.
            $this->db->getPdo()->beginTransaction();
            $this->setDbConf($config_key, $config_value);
            $pages_meta = $this->getPagesMetadata();
            foreach ($pages_meta as $page_meta) {
                $page_id = $page_meta['page'];
                $hidden = (int) $this->pageInHiddenNamespace($page_id);
                $this->setPageHiddenStatus($page_id, $hidden);
            }
            $this->db->getPdo()->commit();
        }
    }

    public function getPagesMetadata(): array
    {
        $sql = 'SELECT page, approver, hidden FROM page';
        return $this->db->queryAll($sql);
    }

    public function getPageMetadata(string $page_id): ?array
    {
        $sql = 'SELECT approver FROM page WHERE page=? AND hidden != 1';
        return $this->db->queryRecord($sql, $page_id);
    }

    public function getDbConf(string $key): ?string
    {
        $sql = 'SELECT value FROM config WHERE key=?';
        return $this->db->queryValue($sql, $key);
    }

    public function setDbConf(string $key, string $value): void
    {
        $this->db->saveRecord('config', ['key' => $key, 'value' => $value]);
    }

    /**
     * @param string $page_id
     * @param int $hidden Must be int since SQLite doesn't suport bool type.
     * @return void
     */
    public function setPageHiddenStatus(string $page_id, int $hidden): void
    {
        $sql = 'UPDATE page SET hidden=? WHERE page=?';
        $this->db->query($sql, $hidden, $page_id);
    }

    public function updatePagesAssignments(): void
    {
        $this->db->getPdo()->beginTransaction();

        // clean current settings
        $this->db->query('DELETE FROM page');

        $wikiPages = $this->getWikiPages();
        foreach ($wikiPages as $id) {
            // update revision information
            $this->updatePage($id);
        }
        $this->db->getPdo()->commit();
    }

    public function getWikiPages(): array
    {
        global $conf;

        $datadir = realpath($conf['datadir']);  // path without ending "/"
        $directory = new RecursiveDirectoryIterator($datadir, FilesystemIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator($directory);

        $pages = [];
        /** @var SplFileInfo $fileinfo */
        foreach ($iterator as $fileinfo) {
            if (!$fileinfo->isFile()) continue;

            $path = $fileinfo->getRealPath(); // it should return "/" both on windows and linux
            // remove dir part
            $path = substr($path, strlen($datadir));
            // make file a dokuwiki path
            $id = pathID($path);
            $pages[] = $id;
        }

        return $pages;
    }

    public function weightedAssignments(): array
    {
        $assignments = $this->db->queryAll('SELECT id, namespace, approver FROM maintainer');

        $weighted_assignments = [];
        foreach ($assignments as $assignment) {
            $ns = $assignment['namespace'];
            // more general namespaces are overridden by more specific ones.
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
     * Returns approver or null if page is not in $weighted_assignments.
     * Approver can be empty string.
     *
     * @param string $page_id
     * @param array $weighted_assignments
     * @return string
     */
    public function getPageAssignment(string $page_id, array $weighted_assignments): ?string
    {
        $page_approver = null;
        foreach ($weighted_assignments as $assignment) {
            $ns = ltrim($assignment['namespace'], ':');
            $approver = $assignment['approver'];
            if (substr($ns, -2) == '**') {
                //remove '**'
                $ns = substr($ns, 0, -2);
                if (substr($page_id, 0, strlen($ns)) == $ns) {
                    $page_approver = $approver;
                }
            } elseif (substr($ns, -1) == '*') {
                //remove '*'
                $ns = substr($ns, 0, -1);
                $noNS = substr($page_id, strlen($ns));
                if (strpos($noNS, ':') === FALSE &&
                    substr($page_id, 0, strlen($ns)) == $ns) {
                    $page_approver = $approver;
                }
            } elseif($page_id == $ns) {
                $page_approver = $approver;
            }
        }
        return $page_approver;
    }

    public function pageInHiddenNamespace(string $page_id): bool
    {
        $page_id = ltrim($page_id, ':');
        foreach ($this->no_apr_namespaces_array as $namespace) {
            if (substr($page_id, 0, strlen($namespace)) == $namespace) {
                return true;
            }
        }
        return false;
    }

    public function getPages(string $user='', array $states=['approved', 'draft', 'ready_for_approval'],
                             string $namespace='', string $filter=''): array
    {
        /* @var AuthPlugin $auth */
        global $auth;

        $sql = 'SELECT page.page AS id, page.approver, revision.rev, revision.approved, revision.approved_by,
                    revision.ready_for_approval, revision.ready_for_approval_by,
                    LENGTH(page.page) - LENGTH(REPLACE(page.page, \':\', \'\')) AS colons
                    FROM page INNER JOIN revision ON page.page = revision.page
                    WHERE page.hidden = 0 AND revision.current=1 AND page.page GLOB ? AND page.page REGEXP ?
                    ORDER BY colons, page.page';
        $pages = $this->db->queryAll($sql, $namespace.'*', $filter);

        // add status to the page
        $pages = array_map([$this, 'setPageStatus'], $pages);

        if ($user !== '') {
            $user_data = $auth->getUserData($user);
            // If export_pdf template contains @APPROVER@ prevent Error: Call to undefined method helper_plugin_approve_db::getDB()
            $user_groups = isset($user_data['grps']) && is_array($user_data['grps']) ? $user_data['grps'] : [];
            $pages = array_filter($pages, function ($page) use ($user, $user_groups) {
                return $page['approver'][0] == '@' && in_array(substr($page['approver'], 1), $user_groups) ||
                    $page['approver'] == $user;
            });
        }

        // filter by status
        $pages = array_filter($pages, function ($page) use ($states) {
            return in_array($page['status'], $states);
        });

        return $pages;
    }

    public function getPageRevisions(string $page_id): array {
        $sql = 'SELECT page AS id, rev, approved, approved_by, ready_for_approval, ready_for_approval_by
                        FROM revision WHERE page=?';
        $revisions = $this->db->queryAll($sql, $page_id);
        // add status to the page
        $revisions = array_map([$this, 'setPageStatus'], $revisions);

        return $revisions;
    }

    public function getPageRevision(string $page_id, int $rev): ?array
    {
        $sql = 'SELECT ready_for_approval, ready_for_approval_by, approved, approved_by, version
                                FROM revision WHERE page=? AND rev=?';
        $page = $this->db->queryRecord($sql, $page_id, $rev);

        if ($page == null) {
            $page = [
                'ready_for_approval' => null,
                'ready_for_approval_by' => null,
                'approved' => null,
                'approved_by' => null
            ];
        }
        $page['id'] = $page_id;
        $page['rev'] = $rev;
        $page = $this->setPageStatus($page);

        return $page;
    }

    protected function setPageStatus(array $page): array
    {
        if ($page['approved'] !== null) {
            $page['status'] = 'approved';
        } elseif ($page['ready_for_approval'] !== null) {
            $page['status'] = 'ready_for_approval';
        } else {
            $page['status'] = 'draft';
        }
        return $page;
    }

    public function moveRevisionHistory(string $old_page_id, string $new_page_id): void
    {
        $this->db->exec('UPDATE revision SET page=? WHERE page=?', $new_page_id, $old_page_id);
    }

    public function getLastDbRev(string $page_id, ?string $status=null): ?int
    {
        if ($status == 'approved') {
            $sql = 'SELECT rev FROM revision WHERE page=? AND approved IS NOT NULL ORDER BY rev DESC LIMIT 1';
            return $this->db->queryValue($sql, $page_id);
        } elseif ($status == 'ready_for_approval') {
            $sql = 'SELECT rev FROM revision WHERE page=? AND ready_for_approval IS NOT NULL ORDER BY rev DESC LIMIT 1';
            return $this->db->queryValue($sql, $page_id);
        }
        $sql = 'SELECT rev FROM revision WHERE page=? AND current=1';
        return $this->db->queryValue($sql, $page_id);
    }

    public function setApprovedStatus(string $page_id): void
    {
        global $INFO;

        // approved IS NULL prevents from overriding already approved page
        $sql = 'UPDATE revision SET approved=?, approved_by=?,
                    version=(SELECT IFNULL(MAX(version), 0) FROM revision WHERE page=?) + 1
                WHERE page=? AND current=1 AND approved IS NULL';
        $this->db->exec($sql, date('c'), $INFO['client'], $page_id, $page_id);
    }

    public function setReadyForApprovalStatus(string $page_id): void
    {
        global $INFO;

        // approved IS NULL prevents from overriding already approved page
        $sql = 'UPDATE revision SET ready_for_approval=?, ready_for_approval_by=?
                WHERE page=? AND current=1 AND ready_for_approval IS NULL';
        $this->db->exec($sql, date('c'), $INFO['client'], $page_id);
    }

    protected function deletePage($page_id): void
    {
        // delete information about availability of a page but keep the history
        $this->db->exec('DELETE FROM page WHERE page=?', $page_id);
        $this->db->exec('DELETE FROM revision WHERE page=? AND approved IS NULL AND ready_for_approval IS NULL'
            , $page_id);
        $this->db->exec('UPDATE revision SET current=0 WHERE page=? AND current=1', $page_id);
    }

    public function handlePageDelete(string $page_id): void
    {
        $this->db->getPdo()->beginTransaction();
        $this->deletePage($page_id);
        $this->db->getPdo()->commit();
    }

    protected function updatePage(string $page_id): void
    {
        // delete all unimportant revisions
        $this->db->exec('DELETE FROM revision WHERE page=? AND approved IS NULL AND ready_for_approval IS NULL'
            , $page_id);

        $weighted_assignments = $this->weightedAssignments();
        $approver = $this->getPageAssignment($page_id, $weighted_assignments);
        if ($approver !== null) {
            $data = [
                'page' => $page_id,
                'hidden' => (int) $this->pageInHiddenNamespace($page_id),
                'approver' => $approver
            ];
            $this->db->saveRecord('page', $data);  // insert or replace
        }

        $last_change_date = @filemtime(wikiFN($page_id));
        // record for current revision exists
        $sql = 'SELECT 1 FROM revision WHERE page=? AND rev=?';
        $exists = $this->db->queryValue($sql, $page_id, $last_change_date);
        if ($exists === null) {
            // mark previous revision as old. this may be already deleted by DELETE
            $this->db->exec('UPDATE revision SET current=0 WHERE page=? AND current=1', $page_id);
            // create new record
            $this->db->saveRecord('revision', [
                'page' => $page_id,
                'rev' => $last_change_date,
                'current' => 1
            ]);
        }

    }

    public function handlePageEdit(string $page_id): void
    {
        $this->db->getPdo()->beginTransaction();
        $this->updatePage($page_id);
        $this->db->getPdo()->commit();
    }

    public function deleteMaintainer(int $maintainer_id): void
    {
        $this->db->getPdo()->beginTransaction();
        $this->db->exec('DELETE FROM maintainer WHERE id=?', $maintainer_id);
        $this->db->getPdo()->commit();
    }

    public function addMaintainer(string $namespace, string $approver): void
    {
        $this->db->getPdo()->beginTransaction();
        $this->db->saveRecord('maintainer', [
            'namespace' => $namespace,
            'approver' => $approver
        ]);
        $this->db->getPdo()->commit();
    }

    public function getMaintainers(): ?array
    {
        $sql = 'SELECT id, namespace, approver FROM maintainer ORDER BY namespace';
        return $this->db->queryAll($sql);
    }
}
