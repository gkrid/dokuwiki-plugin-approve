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
        if ($this->media_approve) {
            $pages = array_map([$this, 'applyMediaApprove'], $pages);
        }

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
        // if page is a draft, no additional media check is needed
        if ($page['status'] == 'draft') {
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
        $page['media_rfas'] = $ready_for_approval;
        if ($drafts) {
             // get the oldest outdated media
            usort($drafts, function ($a ,$b) {
                return $a['media_rev'] <=> $b['media_rev'];
            });
            $oldest_media = $drafts[0];
            $page['outdated_media'] = $oldest_media;
            $page['status'] = 'draft';
        // at least one of media files is in rfa state
        } elseif ($ready_for_approval) {
            // update rfa metadata
            usort($ready_for_approval, function ($a ,$b) {
                return $a['ready_for_approval'] <=> $b['ready_for_approval'];
            });
            $media_with_latest_rfa = $ready_for_approval[count($ready_for_approval)-1];

            // get the oldest outdated media
            usort($ready_for_approval, function ($a ,$b) {
                return $a['media_rev'] <=> $b['media_rev'];
            });
            $page['rfa_media'] = $ready_for_approval[0];

            $page['ready_for_approval'] = $media_with_latest_rfa['ready_for_approval'];
            $page['ready_for_approval_by'] = $media_with_latest_rfa['ready_for_approval_by'];
            $page['status'] = 'ready_for_approval';
        } elseif ($media_page_revisions) {
            // if all media are approved page is also approved
            uasort($media_page_revisions, function ($a ,$b) {
                return $a['ready_for_approval'] <=> $b['ready_for_approval'];
            });
            $media_with_latest_approve = $media_page_revisions[count($media_page_revisions)-1];
            $page['approved'] = $media_with_latest_approve['approved'];
            $page['approved_by'] = $media_with_latest_approve['approved_by'];
            $page['status'] = 'approved';
        }
        return $page;
    }

    public function getPageStatus($id, $last_change_date, $rev): ?array
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
        if ($this->media_approve && $last_change_date == $rev) {
            $page = $this->applyMediaApprove($page);
        }
        return $page;
    }

    public function getPageVersion($id)
    {
        $sql = 'SELECT MAX(version) FROM revision WHERE page=?';
        $max_page_version = $this->db->queryValue($sql, $id);

        $sql = 'SELECT MAX(version) FROM media_revision WHERE page=?';
        $max_media_version = $this->db->queryValue($sql, $id);

        return max($max_page_version, $max_media_version, 0);
    }
}