<?php

namespace dokuwiki\plugin\approve\meta;

use dokuwiki\plugin\sqlite\SQLiteDB;
use dokuwiki\Extension\AuthPlugin;

class ApproveMetadata
{

    protected $db;

    public function __construct()
    {
        $this->db = new SQLiteDB('approve', DOKU_PLUGIN . 'sqlite/db/');
    }

    public function getPages($user=null, $states=['approved', 'draft', 'ready_for_approval'], $namespace='', $filter=''): array
    {
        /* @var AuthPlugin $auth */
        global $auth;

        $sql = 'SELECT page.page, page.approver, revision.rev, revision.approved, revision.approved_by,
                    revision.ready_for_approval, revision.ready_for_approval_by,
                    LENGTH(page.page) - LENGTH(REPLACE(page.page, \':\', \'\')) AS colons
                    FROM page INNER JOIN revision ON page.page = revision.page
                    WHERE page.hidden = 0 AND revision.current=1 AND page.page GLOB ? AND page.page REGEXP ?
                    ORDER BY colons, page.page';
        $pages = $this->db->queryAll($sql, $namespace.'*', $filter);

        if ($user) {
            $user_data = $auth->getUserData($user);
            $user_groups = $user_data['grps'];
            $pages = array_filter($pages, function ($page) use ($user, $user_groups) {
                return $page['approver'][0] == '@' && in_array(substr($page['approver'], 1), $user_groups) ||
                    $page['approver'] == $user;
            });
        }

        // add status to the page
        $pages = array_map(function ($page) {
            if ($page['approved'] !== null) {
                $page['status'] = 'approved';
            } elseif ($page['ready_for_approval'] !== null) {
                $page['status'] = 'ready_for_approval';
            } else {
                $page['status'] = 'draft';
            }
            return $page;
        }, $pages);

        // filter by status
        $pages = array_filter($pages, function ($page) use ($states) {
            return in_array($page['status'], $states);
        });

        return $pages;
    }

    public function getPageStatus($id, $rev, $media_approve=false) {
        $sql = 'SELECT ready_for_approval, ready_for_approval_by,
                                        approved, approved_by, version
                                FROM revision
                                WHERE page=? AND rev=?';
        $status = $this->db->queryRecord($sql, $id, $rev);
        if ($media_approve) {
            $sql = 'SELECT ready_for_approval, approved
                                FROM media_revision
                                WHERE page=? AND rev=?';
            $media_status = $this->db->queryRecord($sql, $id, $rev);

        }
        return $status;
    }
}