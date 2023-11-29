CREATE TABLE revisionTemp (
    page TEXT NOT NULL,
    rev INTEGER NOT NULL,
    media_rev INTEGER NOT NULL,
    ready_for_approval TEXT NULL,
    ready_for_approval_by TEXT NULL,
    approved TEXT NULL,
    approved_by TEXT NULL,
    version INTEGER NULL,
    current BOOLEAN NOT NULL DEFAULT 0,
    PRIMARY KEY (page, rev)
);

INSERT INTO revisionTemp(page,rev,media_rev,ready_for_approval,ready_for_approval_by,approved,approved_by,version,current)
    SELECT page,rev,rev,ready_for_approval,ready_for_approval_by,approved,approved_by,version,current FROM revision;
DROP TABLE revision;
ALTER TABLE revisionTemp RENAME TO revision;

CREATE INDEX idx_revision_current
    ON revision (current, page, rev, ready_for_approval, approved, version);

CREATE INDEX idx_page_approver
    ON page (approver, page, hidden);