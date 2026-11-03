<?php

namespace dokuwiki\plugin\approve;

use dokuwiki\Remote\Response\ApiResponse;

/** Latest approval metadata; uninitialized optional fields are omitted from RPC responses. */
class ApprovalRemoteResponse extends ApiResponse
{
    /** @var bool Whether an approved revision exists */
    public bool $found;
    /** @var int Approved revision timestamp, present when found */
    public int $rev;
    /** @var int Approval timestamp, present when found */
    public int $approved;
    /** @var string Approving username, present when found */
    public string $approved_by;
    /** @var int Approval version, present when available */
    public int $version;

    public function __construct(?array $revision)
    {
        $this->found = $revision !== null;
        if ($revision === null) return;

        $this->rev = (int)$revision['rev'];
        $this->approved = (int)strtotime($revision['approved']);
        $this->approved_by = (string)$revision['approved_by'];
        if ($revision['version'] !== null) $this->version = (int)$revision['version'];
    }

    public function __toString(): string
    {
        return $this->found ? (string)$this->rev : '';
    }
}
