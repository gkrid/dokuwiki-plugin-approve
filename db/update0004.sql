CREATE TABLE media_revision (
    page TEXT NOT NULL,
    rev INTEGER NOT NULL,
    media TEXT NOT NULL,
    media_rev INTEGER NOT NULL,
    ready_for_approval TEXT NULL,
    approved TEXT NULL,
    PRIMARY KEY (page, rev, media, media_rev)
);