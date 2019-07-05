CREATE TABLE ireadit (
    page TEXT NOT NULL,
    rev INTEGER NOT NULL,
    user TEXT NOT NULL,
    timestamp TEXT NULL,
    PRIMARY KEY (page, rev, user)
);
