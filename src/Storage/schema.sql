-- Self-Review Extension SQLite Schema
-- Stores review sessions, comments, and verdicts

CREATE TABLE IF NOT EXISTS sessions (
    id TEXT PRIMARY KEY,
    base_ref TEXT NOT NULL,
    head_ref TEXT NOT NULL,
    context TEXT,
    diff_json TEXT NOT NULL,
    status TEXT DEFAULT 'in_progress',
    created_at TEXT DEFAULT (datetime('now')),
    submitted_at TEXT
);

CREATE TABLE IF NOT EXISTS comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id TEXT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    file_path TEXT NOT NULL,
    start_line INTEGER NOT NULL,
    end_line INTEGER NOT NULL,
    side TEXT DEFAULT 'new',
    body TEXT NOT NULL,
    tag TEXT DEFAULT 'question',
    suggestion TEXT,
    resolved INTEGER DEFAULT 0,
    created_at TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS review_summary (
    session_id TEXT PRIMARY KEY REFERENCES sessions(id) ON DELETE CASCADE,
    verdict TEXT NOT NULL,
    summary_note TEXT
);

CREATE TABLE IF NOT EXISTS chat_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id TEXT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    role TEXT NOT NULL,
    content TEXT NOT NULL,
    file_context TEXT,
    line_context INTEGER,
    parent_id INTEGER,
    status TEXT DEFAULT 'pending',
    error_message TEXT,
    created_at TEXT DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_comments_session ON comments(session_id);
CREATE INDEX IF NOT EXISTS idx_sessions_status ON sessions(status);
CREATE INDEX IF NOT EXISTS idx_chat_messages_session ON chat_messages(session_id);
CREATE INDEX IF NOT EXISTS idx_chat_messages_status ON chat_messages(status);
