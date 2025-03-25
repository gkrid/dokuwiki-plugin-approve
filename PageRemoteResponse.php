<?php

namespace dokuwiki\plugin\approve;

use dokuwiki\Remote\Response\Page;

/**
 * Represents a page to return as result in the remote API
 */
class PageRemoteResponse extends Page
{
    /** @var string User or Group assigned to approve this page */
    public $approver;

    /** @var string The current state of approval: draft, ready_for_approval, approved */
    public $status;

    /** @var int Timestamp of approval, 0 if not approved */
    public $approved;

    /** @var int Timestamp of ready for approval, 0 if not ready */
    public $ready_for_approval;

    /** @var string User who approved this page, empty if not approved */
    public $approved_by;

    /** @var string User who marked this page as ready for approval, empty if not ready */
    public $ready_for_approval_by;

    /** @var string not returned by this endpoint - will always be empty */
    public $author = '';

    /** @var string not returned by this endpoint - will always be empty */
    public $hash = '';


    /**
     * @param array $data The data as returned by helper_db::getPages
     */
    public function __construct($data)
    {
        parent::__construct(
            $data['id'],
            $data['rev'],
        );

        $this->approver = $data['approver'];
        $this->status = $data['status'];

        $this->approved = $data['approved'] ? strtotime($data['approved']) : 0;
        $this->ready_for_approval = $data['ready_for_approval'] ? strtotime($data['ready_for_approval']) : 0;

        $this->approved_by = (string)$data['approved_by'];
        $this->ready_for_approval_by = (string)$data['ready_for_approval_by'];
    }
}
