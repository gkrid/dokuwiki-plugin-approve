CREATE TABLE pageTemp (
    page TEXT NOT NULL,
    approver TEXT NULL,
    hidden BOOLEAN NOT NULL DEFAULT 0,
    PRIMARY KEY (page, approver)
);

INSERT INTO pageTemp(page,approver,hidden) SELECT page,approver,hidden FROM page;
DROP TABLE page;
ALTER TABLE pageTemp RENAME TO page;
