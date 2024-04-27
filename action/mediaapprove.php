<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\Event;
use dokuwiki\Extension\EventHandler;
use dokuwiki\plugin\approve\meta\ApproveTrait;

class action_plugin_approve_mediaapprove extends ActionPlugin {
    use ApproveTrait;
    public function register(EventHandler $controller): void {
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'acceptMediaDiff');
        $controller->register_hook('TPL_ACT_RENDER', 'BEFORE', $this, 'displayMediaDiff');
    }

    public function acceptMediaDiff(Event $event): void {
        global $INFO, $INPUT;
        if ($event->data == 'approve_media_diff') {
            $event->preventDefault();
        } elseif ($event->data == 'approve_media') {
            $event->preventDefault();

            if (!$this->init()) {
                return;
            }

            if (!$this->approve_acl->useApproveHere()) {
                return;
            }

            $last_change_date = @filemtime(wikiFN($INFO['id']));
            $last_at = $this->approve_metadata->getLastAt($INFO['id']);

            $page_revision = $this->approve_metadata->getPageRevision($INFO['id'], $last_change_date);
            $media_revision = $this->approve_metadata->getMediaRevision($INFO['id'], $last_change_date, $last_at);
            $page_revision = array_replace($page_revision, $media_revision);

            $media = $INPUT->str('media');
            $media = json_decode($media, true);
            $status = $INPUT->str('status');
            if ($this->approve_acl->clientCanMarkReadyForApproval() && $status == 'ready_for_approval') {
                $this->approve_metadata->setMediaReadyForApprovalStatus($INFO['id'], $INFO['client'], array_keys($media));
            } elseif ($this->approve_acl->clientCanApprove() &&
                // if page_status is ready_for_approval and page is draft because of media,
                // we must first mark all media as ready_for_approval
                !($page_revision['page_status'] == 'ready_for_approval' && !empty($page_revision['media_drafts'])) &&
                // if page is in approved state but at least one media is in RFA state and one in draft,
                // the media must be first marked as RFA before we can mark page as approved
                !($page_revision['page_status'] == 'approved' && !empty($page_revision['media_drafts'])
                    && !empty($page_revision['media_ready_for_approval']))
            ) {
                $this->approve_metadata->setMediaApprovedStatus($INFO['id'], $INFO['client'], array_keys($media));
            }
            header('Location: ' . wl($INFO['id']));
        }
    }

    public function displayMediaDiff(Event $event): void {
        global $INFO, $INPUT;
        if ($event->data == 'approve_media_diff') {
            $event->preventDefault();
            $status = $INPUT->str('status');
            // unknown status
            if ($status != 'approve' && $status != 'ready_for_approval') {
                return;
            }

            if (!$this->init()) {
                return;
            }

            // check ACL
            if ($status == 'approve' && !$this->approve_acl->clientCanApprove()) {
                return;
            } elseif ($status == 'ready_for_approval' && !$this->approve_acl->clientCanMarkReadyForApproval()) {
                return;
            }

            $media = $INPUT->str('media');
            $media_array = json_decode($media);


            foreach ($media_array as $media_id => $prev_media_rev) {
                $last_media_change_date = @filemtime(mediaFN($media_id));
                print "$media_id: current: $prev_media_rev, new: $last_media_change_date<br>";
            }

            $href = wl($INFO['id'], ['do' => 'approve_media',
                'status' => $status,
                'media' => $media
            ]);

            $button = $status == 'approve' ? 'approve' : 'approve_ready';
            ptln('<a href="' . $href . '">'.$this->getLang($button).'</a>');

        }
    }
}
