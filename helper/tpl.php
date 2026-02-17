<?php

use dokuwiki\Extension\Plugin;

class helper_plugin_approve_tpl extends Plugin
{
    /**
     * Check if banner should be displayed
     *
     * @return bool
     */
    public function shouldDisplay() {
        global $ACT;
        global $INFO;

        if ($ACT != 'show' || !$INFO['exists']) return false;

        /* Return false if banner should not be displayed for users with or below read only permission. */
        if (auth_quickaclcheck($INFO['id']) <= AUTH_READ && !$this->getConf('display_banner_for_readonly')) {
            return false;
        }

        /** @var helper_plugin_approve_acl $acl */
        $acl = $this->loadHelper('approve_acl');
        if (!$acl->useApproveHere($INFO['id'])) return false;

        return true;
    }

    /**
     * Do all checks and return banner html
     *
     * @param string $action
     * @return string
     */
    public function banner($action) {
        global $INFO;

        $html = '';

        if (!$this->shouldDisplay($action)) return $html;

        /** @var helper_plugin_approve_acl $acl */
        $acl = $this->loadHelper('approve_acl');

        $last_change_date = @filemtime(wikiFN($INFO['id']));
        $rev = !$INFO['rev'] ? $last_change_date : $INFO['rev'];


        /** @var helper_plugin_approve_db $db */
        $db = $this->loadHelper('approve_db');

        $page_revision = $db->getPageRevision($INFO['id'], $rev);
        $last_approved_rev = $db->getLastDbRev($INFO['id'], 'approved');

        $classes = $this->getStatusClasses($page_revision['status'], $rev, $last_approved_rev);

        $html .= '<div id="plugin__approve" class="' . implode(' ', $classes) . '">';


        if ($page_revision['status'] == 'approved') {
            $html .=  '<strong>'.$this->getLang('approved').'</strong>';
            $html .=  ' ' . dformat(strtotime($page_revision['approved']));

            if ($this->getConf('banner_long')) {
                $html .=  ' ' . $this->getLang('by') . ' ' . userlink($page_revision['approved_by'], true);
                $html .=  ' (' . $this->getLang('version') .  ': ' . $page_revision['version'] . ')';
            }

            //not the newest page
            $noprintContent = '';
            if ($rev != $last_change_date) {
                // we can see drafts
                if ($acl->clientCanSeeDrafts($INFO['id'])) {
                    $noprintContent .= ' <a href="' . wl($INFO['id']) . '">';
                    $noprintContent .= $this->getLang($last_approved_rev == $last_change_date ? 'newest_approved' : 'newest_draft');
                    $noprintContent .= '</a>';
                    // we cannot see link to draft but there is some newer approved version
                } elseif ($last_approved_rev != $rev) {
                    $urlParameters = [];
                    if ($last_approved_rev != $last_change_date) {
                        $urlParameters['rev'] = $last_approved_rev;
                    }
                    $noprintContent .= ' <a href="' . wl($INFO['id'], $urlParameters) . '">';
                    $noprintContent .= $this->getLang('newest_approved');
                    $noprintContent .= '</a>';
                }
            }

            $html .= $this->noprint($noprintContent);

        } else {
            if ($this->getConf('ready_for_approval') && $page_revision['status'] == 'ready_for_approval') {
                // alternative print status (only approved or otherwise draft)
                $html .= '<span class="plugin__approve_printonly"><strong>' . $this->getLang('draft').'</strong></span>';
                $noprintContent = '<strong>'.$this->getLang('marked_approve_ready').'</strong>';
                $noprintContent .= ' ' . dformat(strtotime($page_revision['ready_for_approval']));
                $noprintContent .= ' ' . $this->getLang('by') . ' ' . userlink($page_revision['ready_for_approval_by'], true);
                $html .= $this->noprint($noprintContent);
            } else {
                $html .= '<strong>'.$this->getLang('draft').'</strong>';
            }

            // not exists approve for current page
            $noprintContent = '';
            if ($last_approved_rev == null) {
                // not the newest page
                if ($rev != $last_change_date) {
                    $noprintContent .= ' <a href="'.wl($INFO['id']).'">';
                    $noprintContent .= $this->getLang('newest_draft');
                    $noprintContent .= '</a>';
                }
            } else {
                $urlParameters = [];
                if ($last_approved_rev != $last_change_date) {
                    $urlParameters['rev'] = $last_approved_rev;
                }
                $noprintContent .= ' <a href="' . wl($INFO['id'], $urlParameters) . '">';
                $noprintContent .= $this->getLang('newest_approved');
                $noprintContent .= '</a>';
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
                    $noprintContent .= ' | <a href="'.wl($INFO['id'], $urlParameters).'">';
                    $noprintContent .= $this->getLang('approve_ready');
                    $noprintContent .= '</a>';
                }

                if ($acl->clientCanApprove($INFO['id'])) {
                    $urlParameters = [
                        'rev' => $last_approved_rev,
                        'do' => 'diff',
                        'approve' => 'approve'
                    ];
                    $noprintContent .= ' | <a href="'.wl($INFO['id'], $urlParameters).'">';
                    $noprintContent .= $this->getLang('approve');
                    $noprintContent .= '</a>';
                }
            }

            $html .= $this->noprint($noprintContent);
        }


        if ($this->getConf('banner_long')) {
            $page_metadata = $db->getPageMetadata($INFO['id']);
            if (isset($page_metadata['approver'])) {
                $html .= $this->noprint(
                    ' | ' . $this->getLang('approver') . ': ' . userlink($page_metadata['approver'], true)
                );
            }
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * @param $status
     * @param $rev
     * @param int|null $last_approved_rev
     * @return array
     */
    protected function getStatusClasses($status, $rev, ?int $last_approved_rev): array
    {
        $classes = [];
        if ($status == 'approved' && $rev == $last_approved_rev) {
            $classes[] = 'plugin__approve_approved';
        } elseif ($status == 'approved') {
            $classes[] = 'plugin__approve_old_approved';
        } elseif ($this->getConf('ready_for_approval') && $status == 'ready_for_approval') {
            $classes[] = 'plugin__approve_ready';
        } else {
            $classes[] = 'plugin__approve_draft';
        }
        return $classes;
    }

    /**
     * Wrap string in noprint span
     * @param $content
     * @return string
     */
    protected function noprint($content)
    {
        return '<span class="plugin__approve_noprint">' . $content . '</span>';
    }
}
