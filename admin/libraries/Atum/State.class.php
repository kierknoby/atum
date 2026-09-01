<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

final class AtumState
{
    private PDO $db;
    private string $stateDir;

    public function __construct(AtumConfig $config)
    {
        $this->stateDir = (string) $config->get('ATUM_STATE_DIR', ATUM_ROOT . '/var');
        $this->initialiseDirectory($this->stateDir);
        $dbPath = $this->stateDir . '/atum.sqlite';

        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('Atum requires the PHP pdo_sqlite extension.');
        }

        $this->db = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db->exec('PRAGMA foreign_keys = ON');
        $this->db->exec('PRAGMA journal_mode = WAL');
        $this->db->exec('PRAGMA busy_timeout = 5000');
        @chmod($dbPath, 0600);
        $this->migrate();
    }

    public function db(): PDO
    {
        return $this->db;
    }

    public function stateDir(): string
    {
        return $this->stateDir;
    }

    private function initialiseDirectory(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create Atum state directory: ' . $dir);
        }
        if (!is_writable($dir)) {
            throw new RuntimeException('Atum state directory is not writable: ' . $dir);
        }
        @chmod($dir, 0700);

        $sessions = $dir . '/sessions';
        if (!is_dir($sessions) && !mkdir($sessions, 0700, true) && !is_dir($sessions)) {
            throw new RuntimeException('Unable to create Atum session directory: ' . $sessions);
        }
        @chmod($sessions, 0700);
    }

    private function migrate(): void
    {
        $this->db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL CHECK(role IN ('admin','viewer')),
    enabled INTEGER NOT NULL DEFAULT 1,
    failed_attempts INTEGER NOT NULL DEFAULT 0,
    locked_until INTEGER,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    last_login_at TEXT,
    session_version INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL,
    user_id INTEGER,
    username TEXT,
    remote_addr TEXT,
    action TEXT NOT NULL,
    object_type TEXT,
    object_id TEXT,
    outcome TEXT NOT NULL,
    detail TEXT,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE TABLE IF NOT EXISTS module_state (
    rawname TEXT PRIMARY KEY,
    installed INTEGER NOT NULL DEFAULT 1,
    enabled INTEGER NOT NULL DEFAULT 1,
    version TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS login_throttle (
    scope TEXT PRIMARY KEY,
    failures INTEGER NOT NULL DEFAULT 0,
    first_failure INTEGER NOT NULL,
    blocked_until INTEGER
);
CREATE TABLE IF NOT EXISTS permission_definitions (
    permission TEXT PRIMARY KEY,
    module TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS role_permissions (
    role TEXT NOT NULL,
    permission TEXT NOT NULL,
    PRIMARY KEY(role, permission),
    FOREIGN KEY(permission) REFERENCES permission_definitions(permission) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS lifecycle_journal (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    owner TEXT NOT NULL,
    status TEXT NOT NULL CHECK(status IN ('prepared','applying','committed','rolled_back')),
    operations TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
SQL);
        $this->ensureColumn('users', 'session_version', 'INTEGER NOT NULL DEFAULT 1');
        $this->db->exec("INSERT OR IGNORE INTO permission_definitions(permission,module,description) VALUES ('view','framework','View Atum'),('admin','framework','Administer Atum')");
        $this->db->exec("INSERT OR IGNORE INTO role_permissions(role,permission) VALUES ('viewer','view'),('admin','view'),('admin','admin')");
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        $columns = $this->db->query('PRAGMA table_info(' . $table . ')')->fetchAll();
        foreach ($columns as $existing) {
            if (($existing['name'] ?? null) === $column) {
                return;
            }
        }
        $this->db->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }
}
