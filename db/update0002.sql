CREATE TABLE maintainerTemp (
    id INTEGER PRIMARY KEY,
    namespace TEXT NOT NULL,
    approver TEXT NULL
);

INSERT INTO maintainerTemp(id,namespace,approver) SELECT id,namespace,maintainer FROM maintainer;
DROP TABLE maintainer;
ALTER TABLE maintainerTemp RENAME TO maintainer;

CREATE TABLE pageTemp (
    page TEXT PRIMARY KEY,
    approver TEXT NULL,
    hidden BOOLEAN NOT NULL DEFAULT 0
);

INSERT INTO pageTemp(page,approver,hidden) SELECT page,maintainer,hidden FROM page;
DROP TABLE page;
ALTER TABLE pageTemp RENAME TO page;
