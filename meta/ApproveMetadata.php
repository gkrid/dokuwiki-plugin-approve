<?php

namespace dokuwiki\plugin\approve\meta;

use dokuwiki\ChangeLog\MediaChangeLog;
use dokuwiki\plugin\sqlite\SQLiteDB;
use dokuwiki\Extension\AuthPlugin;

class ApproveMetadata
{

    protected $db;
    protected $media_approve;

    public function __construct($media_approve)
    {
        $this->db = new SQLiteDB('approve', DOKU_PLUGIN . 'sqlite/db/');
        $this->media_approve = $media_approve;
    }

    public function getPages($user=null, $states=['approved', 'draft', 'ready_for_approval'], $namespace='', $filter=''): array
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
        $pages = array_map([$this, 'applyMediaApprove'], $pages);

        if ($user) {
            $user_data = $auth->getUserData($user);
            $user_groups = $user_data['grps'];
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

    public function getPageRevisions($page): array {
        $sql = 'SELECT page AS id, rev, approved, approved_by, ready_for_approval, ready_for_approval_by
                    FROM revision WHERE page=?';
        $revisions = $this->db->queryAll($sql, $page);
        // add status to the page
        $revisions = array_map([$this, 'setPageStatus'], $revisions);
        $revisions = array_map([$this, 'applyMediaApprove'], $revisions);

        return $revisions;
    }

    protected function setPageStatus($page)
    {
        if ($page['approved'] !== null) {
            $page['status'] = 'approved';
        } elseif ($page['ready_for_approval'] !== null) {
            $page['status'] = 'ready_for_approval';
        } else {
            $page['status'] = 'draft';
        }
        // we keep original 'page_status' because applyMediaApprove can modify 'status'
        $page['page_status'] = $page['status'];
        return $page;
    }

    protected function applyMediaApprove($page): ?array
    {
        $id = $page['id'];
        $rev = $page['rev'];
        $last_change_date = @filemtime(wikiFN($id));
        // if media approve is turned off, page is a draft, or it is not last revision, no additional media check is needed
        if (!$this->media_approve || $page['status'] == 'draft' || $rev != $last_change_date) {
            return $page;
        }

        $media = p_get_metadata($id, 'relation media');
        if (!is_array($media)) {
            return $page;
        }

        $sql = 'SELECT media_id, MAX(media_rev) AS media_rev, ready_for_approval, ready_for_approval_by, approved, approved_by
                                FROM media_revision
                                WHERE page=? AND rev=?
                                GROUP BY media_id';
        $media_revisions = $this->db->queryAll($sql, $id, $rev);
        $media_revisions = array_combine(array_column($media_revisions, 'media_id'), $media_revisions);

        $media_page_revisions = [];
        foreach ($media as $media_id => $exists) {
            if ($exists) {
                $changelog = new MediaChangeLog($media_id);
                $media_rev = $changelog->currentRevision();
                if ($media_rev > $rev) {
                    if (!isset($media_revisions[$media_id])) {
                        $media_page_revisions[] = [
                            'media_id' => $media_id,
                            'media_rev' => $changelog->getRelativeRevision($rev, -1),
                            'status' => 'draft'
                        ];
                    } elseif ($media_revisions[$media_id]['media_rev'] < $media_rev) {
                        $media_page_revisions[] = [
                            'media_id' => $media_id,
                            'media_rev' => $media_revisions[$media_id]['media_rev'],
                            'status' => 'draft'
                        ];
                    } else {
                        $media_page_revision = $media_revisions[$media_id];
                        if ($media_page_revision['approved'] !== null) {
                            $media_page_revision['status'] = 'approved';
                        } elseif ($media_page_revision['ready_for_approval'] !== null) {
                            $media_page_revision['status'] = 'ready_for_approval';
                        }
                        $media_page_revisions[] = $media_page_revision;
                    }
                }
            }
        }
        $drafts = array_filter($media_page_revisions, function ($media) {
           return $media['status'] == 'draft';
        });
        $page['media_drafts'] = $drafts;
        $ready_for_approval = array_filter($media_page_revisions, function ($media) {
            return $media['status'] == 'ready_for_approval';
        });
        $page['media_ready_for_approval'] = $ready_for_approval;
        if ($drafts) {
             // get the oldest outdated media
//            usort($drafts, function ($a ,$b) {
//                return $a['media_rev'] <=> $b['media_rev'];
//            });
//            $oldest_media = $drafts[0];
//            $page['outdated_media'] = $oldest_media;
            $page['status'] = 'draft';
        // at least one of media files is in rfa state
        } elseif ($ready_for_approval) {
            // update rfa metadata
//            usort($ready_for_approval, function ($a ,$b) {
//                return $a['ready_for_approval'] <=> $b['ready_for_approval'];
//            });
//            $latest_rfa_media = $ready_for_approval[count($ready_for_approval)-1];
            $ready_for_approval_column = array_column($ready_for_approval,'ready_for_approval');
            $max_ready_for_approval = max($ready_for_approval_column);
            $media_with_max_ready_for_approval_id = array_search($max_ready_for_approval, $ready_for_approval_column);
            $media_with_max_ready_for_approval = $ready_for_approval[$media_with_max_ready_for_approval_id];

            // get the oldest outdated media
//            usort($ready_for_approval, function ($a ,$b) {
//                return $a['media_rev'] <=> $b['media_rev'];
//            });
//            $page['rfa_media'] = $ready_for_approval[0];

            $page['ready_for_approval'] = $media_with_max_ready_for_approval['ready_for_approval'];
            $page['ready_for_approval_by'] = $media_with_max_ready_for_approval['ready_for_approval_by'];
            $page['status'] = 'ready_for_approval';
        } elseif ($media_page_revisions) { // all media in $media_page_revisions are approved
            // if all media are approved page is also approved
//            uasort($media_page_revisions, function ($a ,$b) {
//                return $a['ready_for_approval'] <=> $b['ready_for_approval'];
//            });
//            $media_with_latest_approve = $media_page_revisions[count($media_page_revisions)-1];
            $approved_column = array_column($media_page_revisions,'approved');
            $max_approved = max($approved_column);
            $media_with_max_approved_id = array_search($max_approved, $approved_column);
            $media_with_max_approved = $media_page_revisions[$media_with_max_approved_id];

            $page['approved'] = $media_with_max_approved['approved'];
            $page['approved_by'] = $media_with_max_approved['approved_by'];
            $page['status'] = 'approved';
        }
        return $page;
    }

    public function getPageRevision($id, $rev): ?array
    {
        $sql = 'SELECT ready_for_approval, ready_for_approval_by, approved, approved_by
                                FROM revision
                                WHERE page=? AND rev=?';
        $page = $this->db->queryRecord($sql, $id, $rev);
        if ($page == null) {
            $page = [
                'ready_for_approval' => null,
                'ready_for_approval_by' => null,
                'approved' => null,
                'approved_by' => null
            ];
        }
        $page['id'] = $id;
        $page['rev'] = $rev;
        $page = $this->setPageStatus($page);
        // check if we don't have outdated media files - makes sens only for current page revision
        $page = $this->applyMediaApprove($page);
        return $page;
    }

    public function getMediaRevisions($page_id, $page_rev)
    {
        $sql = 'SELECT ready_for_approval, ready_for_approval_by
                    FROM media_revision
                    WHERE page=? AND rev=? AND ready_for_approval IS NOT NULL
                    GROUP BY ready_for_approval, ready_for_approval_by';
        $ready_for_approval = $this->db->queryAll($sql, $page_id, $page_rev);
        $ready_for_approval = array_map(function ($v) use ($page_id) {
            return [
                'status' => 'ready_for_approval',
                'date' => strtotime($v['ready_for_approval']),
                'id' => $page_id,
                'user' => $v['ready_for_approval_by']
            ];
        }, $ready_for_approval);

        $sql = 'SELECT approved, approved_by, version
                    FROM media_revision
                    WHERE page=? AND rev=? AND approved IS NOT NULL
                    GROUP BY approved, approved_by, version';
        $approved = $this->db->queryAll($sql, $page_id, $page_rev);
        $approved = array_map(function ($v) use ($page_id) {
            return [
                'status' => 'approved',
                'date' => strtotime($v['approved']),
                'id' => $page_id,
                'user' => $v['approved_by'],
                'version' => $v['version']
            ];
        }, $approved);

        $revisions = array_merge($ready_for_approval, $approved);
        usort($revisions, function ($a, $b) {
            if ($a['date'] == $b['date']) return 0;
            return ($a['date'] < $b['date']) ? 1 : -1;
        });

        return $revisions;
    }

    public function getPageVersion($id)
    {
        $sql = 'SELECT MAX(version) FROM revision WHERE page=?';
        $max_page_version = $this->db->queryValue($sql, $id);

        $sql = 'SELECT MAX(version) FROM media_revision WHERE page=?';
        $max_media_version = $this->db->queryValue($sql, $id);

        return max($max_page_version, $max_media_version, 0);
    }

    public function setMediaReadyForApprovalStatus($page_id, $client, $media_ids)
    {
        $timestamp = date('c');
        $last_page_change_date = @filemtime(wikiFN($page_id));

        foreach ($media_ids as $media_id) {
            $last_media_change_date = @filemtime(mediaFN($media_id));
            $data = [
                'page' => $page_id,
                'rev' => $last_page_change_date,
                'media_id' => $media_id,
                'media_rev' => $last_media_change_date,
                'ready_for_approval' => $timestamp,
                'ready_for_approval_by' => $client
            ];
            $this->db->saveRecord('media_revision', $data);
        }
    }

    public function setMediaApprovedStatus($page_id, $client, $media_ids)
    {
        $timestamp = date('c');
        $last_page_change_date = @filemtime(wikiFN($page_id));
        $version = $this->getPageVersion($page_id)+1;

        foreach ($media_ids as $media_id) {
            $last_media_change_date = @filemtime(mediaFN($media_id));

            // check if the current revision of media file already exists
            $sql = 'SELECT * FROM media_revision WHERE page=? AND rev=? AND media_id=? AND media_rev=?';
            $media_revision = $this->db->queryRecord($sql, $page_id, $last_page_change_date, $media_id, $last_media_change_date);
            if ($media_revision) {
                $this->db->query('UPDATE media_revision
                            SET approved=?, approved_by=?, version=?
                            WHERE page=? AND rev=? AND media_id=? AND media_rev=? AND approved IS NULL',
                    $timestamp, $client, $version,
                    $page_id, $last_page_change_date, $media_id, $last_media_change_date);
            } else {
                $data = [
                    'page' => $page_id,
                    'rev' => $last_page_change_date,
                    'media_id' => $media_id,
                    'media_rev' => $last_media_change_date,
                    'approved' => $timestamp,
                    'approved_by' => $client,
                    'version' => $version
                ];
                $this->db->saveRecord('media_revision', $data);
            }
        }
    }
}