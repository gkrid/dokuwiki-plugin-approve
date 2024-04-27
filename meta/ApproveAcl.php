<?php

namespace dokuwiki\plugin\approve\meta;

class ApproveAcl
{
    public function __construct(string          $page_id,
                                ApproveMetadata $approve_metadata,
                                bool            $strict_approver,
                                bool            $hide_drafts_for_viewers,
                                bool            $viewmode,
                                string          $ready_for_approval_acl)
    {
        $this->page_id = $page_id;
        $this->approve_metadata = $approve_metadata;
        $this->page_metadata = $approve_metadata->getPageMetadata($page_id);
        $this->strict_approver = $strict_approver;
        $this->hide_drafts_for_viewers = $hide_drafts_for_viewers;
        $this->viewmode = $viewmode;
        $this->ready_for_approval_acl = preg_split('/\s+/', $ready_for_approval_acl, -1, PREG_SPLIT_NO_EMPTY);
    }

    public function useApproveHere() {
        $page_metadata = $this->approve_metadata->getPageMetadata($this->page_id);
        if ($page_metadata === null) { // do not use approve plugin here
            return false;
        }
        return true;
    }

    public function clientCanApprove(): bool
    {
        global $INFO;

        // user not log in
        if (!isset($INFO['userinfo'])) return false;

        // user is approver
        if ($this->page_metadata['approver'] == $INFO['client']) {
            return true;
        // user is in approvers group
        } elseif (strncmp($this->page_metadata['approver'], "@", 1) === 0 &&
            in_array(substr($this->page_metadata['approver'], 1), $INFO['userinfo']['grps'])) {
            return true;
        // if the user has AUTH_DELETE permission and the approver is not defined or strict_approver is turn off
        // user can approve the page
        } elseif (auth_quickaclcheck($this->page_id) >= AUTH_DELETE &&
            ($this->page_metadata['approver'] === '' || !$this->strict_approver)) {
            return true;
        }
        return false;
    }

    public function clientCanMarkReadyForApproval(): bool {
        global $INFO;

        if (count($this->ready_for_approval_acl) == 0) {
            return auth_quickaclcheck($this->page_id) >= AUTH_EDIT;
        }
        foreach ($this->ready_for_approval_acl as $user_or_group) {
            if ($user_or_group[0] == '@' && in_array(substr($user_or_group, 1), $INFO['userinfo']['grps'])) {
                return true;
            } elseif ($user_or_group == $INFO['client']) {
                return true;
            }
        }
        return false;
    }


    public function clientCanSeeDrafts(): bool {

        // in view mode no one can see drafts
        if ($this->viewmode && get_doku_pref('approve_viewmode', false)) return false;

        if (!$this->hide_drafts_for_viewers) return true;

        if (auth_quickaclcheck($this->page_id) >= AUTH_EDIT) return true;
        if ($this->clientCanApprove()) return true;

        return false;
    }
}