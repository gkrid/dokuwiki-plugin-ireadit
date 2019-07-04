CREATE TABLE ireadit (
    id INTEGER PRIMARY KEY,
    page TEXT NOT NULL,
    rev INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE ireadit_user(
    ireadit_id INTEGER NOT NULL,
    user TEXT NOT NULL,
    timestamp TEXT NULL,
    PRIMARY KEY (ireadit_id, user)
);
