<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;

use dokuwiki\plugin\approve\meta\ApproveAcl;
use dokuwiki\plugin\approve\meta\ApproveMetadata;

class action_plugin_approve_banner extends ActionPlugin {
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
        } catch (Exception $e) {
            msg($e->getMessage(), -1);
            return false;
        }
        return true;
    }

    public function register(EventHandler $controller): void
    {
        $controller->register_hook('TPL_ACT_RENDER', 'BEFORE', $this, 'displayBanner');
    }

    public function displayBanner(Event $event): void {
        global $INFO;

        if ($event->data != 'show' || !$INFO['exists']) return;

        /* Return true if banner should not be displayed for users with or below read only permission. */
        if (auth_quickaclcheck($INFO['id']) <= AUTH_READ && !$this->getConf('display_banner_for_readonly')) {
            return;
        }

        if (!$this->init()) {
            return;
        }

        if ($this->getConf('media_approve')) {
            $this->displayBannerMediaApprove();
        } else {
            $this->displayBannerApprove();
        }
    }

    protected function displayBannerApprove(): void
    {

    }

    protected function displayBannerMediaApprove(): void
    {
        global $INFO, $DATE_AT;

        $page_metadata = $this->approve_metadata->getPageMetadata($INFO['id']);
        if ($page_metadata === null) { // do not use approve plugin here
            return;
        }

        $last_change_date = @filemtime(wikiFN($INFO['id']));
        $last_at = $this->approve_metadata->getLastAt($INFO['id']);
        $rev = !$INFO['rev'] ? $last_change_date : $INFO['rev'];
        $date_at = !$DATE_AT ? $last_at : $DATE_AT;

        $page_revision = $this->approve_metadata->getPageRevision($INFO['id'], $rev);
        $media_revision = $this->approve_metadata->getMediaRevision($INFO['id'], $rev, $DATE_AT);
        $page_revision = array_replace($page_revision, $media_revision);

        $last_approved_at = $this->approve_metadata->getLastAt($INFO['id'], 'approved');
        $last_approved_rev = $this->approve_metadata->getLastRev($INFO['id'], 'approved');

        $classes = [];
        if ($this->getConf('prettyprint')) {
            $classes[] = 'plugin__approve_noprint';
        }

        if ($page_revision['status'] == 'approved' && $date_at == $last_approved_at) {
            $classes[] = 'plugin__approve_approved';
        } elseif ($page_revision['status'] == 'approved') {
            $classes[] = 'plugin__approve_old_approved';
        } elseif ($this->getConf('ready_for_approval') && $page_revision['status'] == 'ready_for_approval') {
            $classes[] = 'plugin__approve_ready';
        } else {
            $classes[] = 'plugin__approve_draft';
        }

        ptln('<div id="plugin__approve" class="' . implode(' ', $classes) . '">');

        if ($page_revision['status'] == 'approved') {
            ptln('<strong>'.$this->getLang('approved').'</strong>');
            ptln(' ' . dformat(strtotime($page_revision['approved'])));

            if($this->getConf('banner_long')) {
                ptln(' ' . $this->getLang('by') . ' ' . userlink($page_revision['approved_by'], true));
                ptln(' (' . $this->getLang('version') .  ': ' . $page_revision['version']  . ')');
            }

            // not the newest page
            if ($date_at != $last_at) {
                // we can see drafts
                if ($this->approve_acl->clientCanSeeDrafts()) {
                    ptln('<a href="' . wl($INFO['id']) . '">');
                    ptln($this->getLang($last_at == $last_approved_at ? 'newest_approved' : 'newest_draft'));
                    ptln('</a>');
                    // we cannot see link to draft but there is some newer approved version
                } elseif ($last_approved_at != $date_at) {
                    $urlParameters = [];
                    if ($last_approved_at != $last_at) {
                        $urlParameters['at'] = $last_approved_at;
                    }
                    ptln('<a href="' . wl($INFO['id'], $urlParameters) . '">');
                    ptln($this->getLang('newest_approved'));
                    ptln('</a>');
                }
            }
        } else {
            if ($this->getConf('ready_for_approval') && $page_revision['status'] == 'ready_for_approval') {
                ptln('<strong>'.$this->getLang('marked_approve_ready').'</strong>');
                ptln(' ' . dformat(strtotime($page_revision['ready_for_approval'])));
                ptln(' ' . $this->getLang('by') . ' ' . userlink($page_revision['ready_for_approval_by'], true));
            } else {
                ptln('<strong>'.$this->getLang('draft').'</strong>');
            }

            // no page revision is approved
            if ($last_approved_at == null) {
                // not the newest page
                if ($date_at != $last_at) {
                    ptln('<a href="'.wl($INFO['id']).'">');
                    ptln($this->getLang('newest_draft'));
                    ptln('</a>');
                }
            } else {
                $urlParameters = [];
                if ($last_approved_at != $last_at) {
                    $urlParameters['at'] = $last_approved_at;
                }
                ptln('<a href="' . wl($INFO['id'], $urlParameters) . '">');
                ptln($this->getLang('newest_approved'));
                ptln('</a>');
            }

            // we are in current page
            if ($date_at == $last_at) {
                if ($this->getConf('ready_for_approval') &&
                    $this->approve_acl->clientCanMarkReadyForApproval() &&
                    $page_revision['status'] != 'ready_for_approval') {

                    if (isset($page_revision['media_ready_for_approval'])) { // we are in draft because of media
                        $media_drafts_ids = array_column($page_revision['media_drafts'], 'media_id');
                        $media_drafts_revs = array_column($page_revision['media_drafts'], 'media_rev');
                        $media_drafts = array_combine($media_drafts_ids, $media_drafts_revs);
                        $urlParameters = [
                            'media' => json_encode($media_drafts),
                            'do' => 'approve_media_diff',
                            'status' => 'ready_for_approval'
                        ];
                    } else {
                        $urlParameters = [
                            'rev' => $last_approved_rev,
                            'do' => 'diff',
                            'ready_for_approval' => 'ready_for_approval'
                        ];
                    }

                    ptln(' | <a href="'.wl($INFO['id'], $urlParameters).'">');
                    ptln($this->getLang('approve_ready'));
                    ptln('</a>');
                }

                // if page_status is ready_for_approval and page is draft because of media,
                // we must first mark all media as ready_for_approval
                if ($this->approve_acl->clientCanApprove() &&
                    !($page_revision['page_status'] == 'ready_for_approval' && !empty($page_revision['media_drafts'])) &&
                    // if page is in approved state but at least one media is in RFA state and one in draft,
                    // the media must be first marked as RFA before we can mark page as approved
                    !($page_revision['page_status'] == 'approved' && !empty($page_revision['media_drafts'])
                        && !empty($page_revision['media_ready_for_approval']))) {
                    // the page is approved, but we have some draft media
                    if ($page_revision['page_status'] == 'approved' && !empty($page_revision['media_drafts'])) {
                        $media_drafts_ids = array_column($page_revision['media_drafts'], 'media_id');
                        $media_drafts_revs = array_column($page_revision['media_drafts'], 'media_rev');
                        $media_drafts = array_combine($media_drafts_ids, $media_drafts_revs);
                        $urlParameters = [
                            'media' => json_encode($media_drafts),
                            'do' => 'approve_media_diff',
                            'status' => 'approve'
                        ];
                        // the page is approved, but we have some rfa media
                    } elseif ($page_revision['page_status'] == 'approved' && !empty($page_revision['media_ready_for_approval'])) {
                        $media_ready_for_approval_ids = array_column($page_revision['media_ready_for_approval'], 'media_id');
                        $media_ready_for_approval_revs = array_column($page_revision['media_ready_for_approval'], 'media_rev');
                        $media_ready_for_approval = array_combine($media_ready_for_approval_ids, $media_ready_for_approval_revs);
                        $urlParameters = [
                            'media' => json_encode($media_ready_for_approval),
                            'do' => 'approve_media_diff',
                            'status' => 'approve'
                        ];
                        // the page is not approved
                    } else {
                        $urlParameters = [
                            'rev' => $last_approved_rev,
                            'do' => 'diff',
                            'approve' => 'approve'
                        ];
                    }
                    ptln(' | <a href="'.wl($INFO['id'], $urlParameters).'">');
                    ptln($this->getLang('approve'));
                    ptln('</a>');
                }
            }
        }

        if (isset($page_metadata['approver']) && $this->getConf('banner_long')) {
            ptln(' | ' . $this->getLang('approver') . ': ' . userlink($page_metadata['approver'], true));
        }

        ptln('</div>');
    }
}