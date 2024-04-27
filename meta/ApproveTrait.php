<?php

namespace dokuwiki\plugin\approve\meta;

trait ApproveTrait
{
    protected $approve_metadata;
    protected $approve_acl;

    protected function init(): bool {
        global $INFO;
        try {
            $this->approve_metadata = new ApproveMetadata(
                $this->getConf('no_apr_namespaces'),
                $this->getConf('media_approve')
            );
            $this->approve_acl = new ApproveAcl(
                $INFO['id'],
                $this->approve_metadata,
                $this->getConf('strict_approver'),
                $this->getConf('hide_drafts_for_viewers'),
                $this->getConf('viewmode'),
                $this->getConf('ready_for_approval_acl')
            );
        } catch (\Exception $e) {
            msg($e->getMessage(), -1);
            return false;
        }
        return true;
    }
}