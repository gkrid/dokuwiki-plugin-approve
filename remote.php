<?php

use dokuwiki\Extension\RemotePlugin;
use dokuwiki\plugin\approve\PageRemoteResponse;
use dokuwiki\Remote\RemoteException;

/**
 * DokuWiki Plugin approve (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author Andreas Gohr <dokuwiki@cosmocode.de>
 */
class remote_plugin_approve extends RemotePlugin
{

    /**
     * Get wiki pages and their approval status
     *
     * Return all pages that are under control of the approval plugin, optionally filtered.
     *
     * @param string[] $states Return only pages matchin this state [approved, draft, ready_for_approval]
     * @param string $namespace Only return pages within this namespace, empty for all
     * @param string $filter Only return pages matching this regex, empty for all
     * @param string $approver Only return pages to be approved by this user or group, empty for all
     * @return PageRemoteResponse[]
     * @throws RemoteException
     */
    public function getPages(
        $states = ['approved', 'draft', 'ready_for_approval'], $namespace = '', $filter = '', $approver = ''
    )
    {
        global $auth;

        if (array_diff($states, ['approved', 'draft', 'ready_for_approval'])) {
            throw new RemoteException('Invalid state(s) provided', 122);
        }

        $namespace = cleanID($namespace);

        if (@preg_match('/' . $filter . '/', null) === false) {
            throw new RemoteException('Invalid filter regex', 123);
        }

        $approver = $auth->cleanUser($approver);

        /** @var helper_plugin_approve_db $db */
        $db = plugin_load('helper', 'approve_db');
        $pages = $db->getPages($approver, $states, $namespace, $filter);

        return array_map(function ($data) {
            return new PageRemoteResponse($data);
        }, $pages);
    }

    /**
     * Set the approval status of a page
     *
     * Mark a given page as approved or ready for approval
     *
     * @param string $page The page id
     * @param string $status The new status [approved, ready_for_approval]
     * @return true
     * @throws RemoteException
     */
    public function setStatus($page, $status)
    {
        /** @var helper_plugin_approve_acl $acl */
        $acl = plugin_load('helper', 'approve_acl');

        /** @var helper_plugin_approve_db $db */
        $db = plugin_load('helper', 'approve_db');

        if (!page_exists($page)) {
            throw new RemoteException('Page does not exist', 121);
        }

        if (!$acl->useApproveHere($page)) {
            throw new RemoteException('This page is not under control of the approve plugin', 124);
        }

        global $INFO;
        global $USERINFO;
        $INFO['userinfo'] = $USERINFO;

        if ($status == 'approved') {
            if (!$acl->clientCanApprove($page)) {
                throw new RemoteException('You are not allowed to approve this page', 111);
            }
            $db->setApprovedStatus($page);
        } elseif ($status == 'ready_for_approval') {
            if (!$acl->clientCanMarkReadyForApproval($page)) {
                throw new RemoteException('You are not allowed to mark this page as ready for approval', 111);
            }
            $db->setReadyForApprovalStatus($page);
        } else {
            throw new RemoteException('Invalid status', 122);

        }

        return true;
    }
}
