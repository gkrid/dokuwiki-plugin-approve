<?php

use dokuwiki\Extension\Plugin;

class helper_plugin_approve_tpl extends Plugin
{
    public function banner($action) {
        global $INFO;

        if ($action != 'show' || !$INFO['exists']) return;

        /* Return true if banner should not be displayed for users with or below read only permission. */
        if (auth_quickaclcheck($INFO['id']) <= AUTH_READ && !$this->getConf('display_banner_for_readonly')) {
            return;
        }

        /** @var helper_plugin_approve_acl $acl */
        $acl = $this->loadHelper('approve_acl');
        if (!$acl->useApproveHere($INFO['id'])) return;

        $last_change_date = @filemtime(wikiFN($INFO['id']));
        $rev = !$INFO['rev'] ? $last_change_date : $INFO['rev'];


        /** @var helper_plugin_approve_db $db */
        $db = $this->loadHelper('approve_db');

        $page_revision = $db->getPageRevision($INFO['id'], $rev);
        $last_approved_rev = $db->getLastDbRev($INFO['id'], 'approved');

        $classes = [];
        if ($page_revision['status'] == 'approved' && $rev == $last_approved_rev) {
            $classes[] = 'plugin__approve_approved';
        } elseif ($page_revision['status'] == 'approved') {
            $classes[] = 'plugin__approve_old_approved';
        } elseif ($this->getConf('ready_for_approval') && $page_revision['status'] == 'ready_for_approval') {
            $classes[] = 'plugin__approve_ready';
        } else {
            $classes[] = 'plugin__approve_draft';
        }

        echo '<div id="plugin__approve" class="' . implode(' ', $classes) . '">';


        if ($page_revision['status'] == 'approved') {
            echo '<strong>'.$this->getLang('approved').'</strong>';
            echo ' ' . dformat(strtotime($page_revision['approved']));

            if($this->getConf('banner_long')) {
                echo ' ' . $this->getLang('by') . ' ' . userlink($page_revision['approved_by'], true);
                echo ' (' . $this->getLang('version') .  ': ' . $page_revision['version'] . ')';
            }

            //not the newest page
            if ($rev != $last_change_date) {
                // we can see drafts
                if ($acl->clientCanSeeDrafts($INFO['id'])) {
                    echo ' <a href="' . wl($INFO['id']) . '">';
                    echo $this->getLang($last_approved_rev == $last_change_date ? 'newest_approved' : 'newest_draft');
                    echo '</a>';
                    // we cannot see link to draft but there is some newer approved version
                } elseif ($last_approved_rev != $rev) {
                    $urlParameters = [];
                    if ($last_approved_rev != $last_change_date) {
                        $urlParameters['rev'] = $last_approved_rev;
                    }
                    echo ' <a href="' . wl($INFO['id'], $urlParameters) . '">';
                    echo $this->getLang('newest_approved');
                    echo '</a>';
                }
            }

        } else {
            if ($this->getConf('ready_for_approval') && $page_revision['status'] == 'ready_for_approval') {
                echo '<strong>'.$this->getLang('marked_approve_ready').'</strong>';
                echo ' ' . dformat(strtotime($page_revision['ready_for_approval']));
                echo ' ' . $this->getLang('by') . ' ' . userlink($page_revision['ready_for_approval_by'], true);
            } else {
                echo '<strong>'.$this->getLang('draft').'</strong>';
            }

            // not exists approve for current page
            if ($last_approved_rev == null) {
                // not the newest page
                if ($rev != $last_change_date) {
                    echo ' <a href="'.wl($INFO['id']).'">';
                    echo $this->getLang('newest_draft');
                    echo '</a>';
                }
            } else {
                $urlParameters = [];
                if ($last_approved_rev != $last_change_date) {
                    $urlParameters['rev'] = $last_approved_rev;
                }
                echo ' <a href="' . wl($INFO['id'], $urlParameters) . '">';
                echo $this->getLang('newest_approved');
                echo '</a>';
            }

            //we are in current page
            if ($rev == $last_change_date) {
                if ($this->getConf('ready_for_approval') &&
                    $acl->clientCanMarkReadyForApproval($INFO['id']) &&
                    $page_revision['status'] != 'ready_for_approval') {

                    $urlParameters = [
                        'rev' => $last_approved_rev,
                        'do' => 'diff',
                        'ready_for_approval' => 'ready_for_approval'
                    ];
                    echo ' | <a href="'.wl($INFO['id'], $urlParameters).'">';
                    echo $this->getLang('approve_ready');
                    echo '</a>';
                }

                if ($acl->clientCanApprove($INFO['id'])) {
                    $urlParameters = [
                        'rev' => $last_approved_rev,
                        'do' => 'diff',
                        'approve' => 'approve'
                    ];
                    echo ' | <a href="'.wl($INFO['id'], $urlParameters).'">';
                    echo $this->getLang('approve');
                    echo '</a>';
                }
            }
        }


        if ($this->getConf('banner_long')) {
            $page_metadata = $db->getPageMetadata($INFO['id']);
            if (isset($page_metadata['approver'])) {
                echo ' | ' . $this->getLang('approver') . ': ' . userlink($page_metadata['approver'], true);
            }
        }

        echo '</div>';
    }
}
