<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

final class AtumAuth
{
    private const USER_SOURCE_FAILURES = 5;
    private const IP_FAILURES = 20;
    private const THROTTLE_WINDOW = 300;
    private const BLOCK_SECONDS = 300;
    private const MAX_PASSWORD_BYTES = 1024;
    private bool $sessionStarted = false;
    private bool $sessionValidated = false;

    public function __construct(
        private AtumState $state,
        private AtumAudit $audit,
        private AtumConfig $config
    ) {
    }

    public function startSession(): void
    {
        if ($this->sessionStarted || PHP_SAPI === 'cli') {
            return;
        }

        $secure = $this->isHttps();
        session_name('ATUMSESSID');
        session_save_path($this->state->stateDir() . '/sessions');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.gc_maxlifetime', '3600');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
        $this->sessionStarted = true;

        $now = time();
        $created = (int) ($_SESSION['atum_created'] ?? 0);
        $lastSeen = (int) ($_SESSION['atum_last_seen'] ?? 0);
        if (($created && $now - $created > 28800) || ($lastSeen && $now - $lastSeen > 1800)) {
            $this->logout(false);
            session_start();
            $this->sessionStarted = true;
        }
        $_SESSION['atum_created'] ??= $now;
        $_SESSION['atum_last_seen'] = $now;
    }

    public function createUser(string $username, string $password, string $role = 'admin'): int
    {
        $username = trim($username);
        if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
            throw new InvalidArgumentException('Username must be 3-64 characters using letters, numbers, dot, underscore or hyphen.');
        }
        $this->validatePassword($password);
        if (!in_array($role, ['admin', 'viewer'], true)) {
            throw new InvalidArgumentException('Invalid Atum role.');
        }

        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        $hash = password_hash($password, $algo);
        if ($hash === false) {
            throw new RuntimeException('Unable to hash password.');
        }

        $now = gmdate(DATE_ATOM);
        $statement = $this->state->db()->prepare(
            'INSERT INTO users (username,password_hash,role,enabled,created_at,updated_at) VALUES (:username,:hash,:role,1,:created,:updated)'
        );
        $statement->execute([
            ':username' => $username,
            ':hash' => $hash,
            ':role' => $role,
            ':created' => $now,
            ':updated' => $now,
        ]);
        $id = (int) $this->state->db()->lastInsertId();
        $this->audit->log('user.create', 'success', 'user', (string) $id, 'Created ' . $role . ' account ' . $username, null);
        return $id;
    }

    public function authenticate(string $username, string $password): bool
    {
        $this->startSession();
        $username = trim($username);
        if (strlen($username) > 64 || strlen($password) > self::MAX_PASSWORD_BYTES) {
            return false;
        }
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? 'local');
        $scopes = [
            'user-source:' . hash('sha256', strtolower($username) . "\0" . $remote),
            'ip:' . hash('sha256', $remote),
        ];
        if ($this->throttled($scopes)) {
            $this->audit->log('auth.login', 'throttled', 'user', null, 'Login source is temporarily throttled', null);
            return false;
        }

        $statement = $this->state->db()->prepare('SELECT * FROM users WHERE username = :username COLLATE NOCASE LIMIT 1');
        $statement->execute([':username' => $username]);
        $user = $statement->fetch();

        if (!$user || !(bool) $user['enabled']) {
            password_verify($password, $this->dummyPasswordHash());
            $this->recordFailure($scopes);
            $this->audit->log('auth.login', 'failure', 'user', null, 'Unknown or disabled account', null);
            return false;
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            $this->recordFailure($scopes);
            $this->audit->log('auth.login', 'failure', 'user', (string) $user['id'], 'Invalid password', $user);
            return false;
        }

        $this->clearThrottle($scopes);
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        if (password_needs_rehash((string) $user['password_hash'], $algorithm)) {
            $rehash = password_hash($password, $algorithm);
            if ($rehash !== false) {
                $this->state->db()->prepare('UPDATE users SET password_hash=:hash WHERE id=:id')->execute([':hash' => $rehash, ':id' => $user['id']]);
            }
        }

        session_regenerate_id(true);
        $_SESSION['atum_user'] = [
            'id' => (int) $user['id'],
            'username' => (string) $user['username'],
            'role' => (string) $user['role'],
            'session_version' => (int) ($user['session_version'] ?? 1),
        ];
        $this->sessionValidated = true;
        $now = time();
        $_SESSION['atum_created'] = $now;
        $_SESSION['atum_last_seen'] = $now;
        $_SESSION['atum_csrf'] = bin2hex(random_bytes(32));
        $this->state->db()->prepare('UPDATE users SET failed_attempts=0, locked_until=NULL, last_login_at=:last, updated_at=:updated WHERE id=:id')->execute([
            ':last' => gmdate(DATE_ATOM),
            ':updated' => gmdate(DATE_ATOM),
            ':id' => $user['id'],
        ]);
        $this->audit->log('auth.login', 'success', 'user', (string) $user['id'], null, $_SESSION['atum_user']);
        return true;
    }

    private function throttled(array $scopes): bool
    {
        $now = time();
        $statement = $this->state->db()->prepare('SELECT blocked_until FROM login_throttle WHERE scope=:scope');
        foreach ($scopes as $scope) {
            $statement->execute([':scope' => $scope]);
            $blockedUntil = $statement->fetchColumn();
            if ($blockedUntil !== false && $blockedUntil !== null && (int) $blockedUntil > $now) {
                return true;
            }
        }
        return false;
    }

    private function recordFailure(array $scopes): void
    {
        $now = time();
        foreach ($scopes as $index => $scope) {
            $threshold = $index === 0 ? self::USER_SOURCE_FAILURES : self::IP_FAILURES;
            $statement = $this->state->db()->prepare('SELECT failures,first_failure FROM login_throttle WHERE scope=:scope');
            $statement->execute([':scope' => $scope]);
            $row = $statement->fetch();
            if (!is_array($row) || $now - (int) $row['first_failure'] > self::THROTTLE_WINDOW) {
                $failures = 1;
                $first = $now;
            } else {
                $failures = (int) $row['failures'] + 1;
                $first = (int) $row['first_failure'];
            }
            $blocked = $failures >= $threshold ? $now + self::BLOCK_SECONDS : null;
            $upsert = $this->state->db()->prepare('INSERT INTO login_throttle (scope,failures,first_failure,blocked_until) VALUES (:scope,:failures,:first,:blocked) ON CONFLICT(scope) DO UPDATE SET failures=excluded.failures,first_failure=excluded.first_failure,blocked_until=excluded.blocked_until');
            $upsert->execute([':scope' => $scope, ':failures' => $failures, ':first' => $first, ':blocked' => $blocked]);
        }
    }

    private function clearThrottle(array $scopes): void
    {
        $statement = $this->state->db()->prepare('DELETE FROM login_throttle WHERE scope=:scope');
        foreach ($scopes as $scope) {
            $statement->execute([':scope' => $scope]);
        }
    }

    public function logout(bool $audit = true): void
    {
        $actor = $_SESSION['atum_user'] ?? null;
        if ($audit && is_array($actor)) {
            $this->audit->log('auth.logout', 'success', 'user', (string) ($actor['id'] ?? ''), null, $actor);
        }
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], true);
            }
            session_destroy();
        }
        $this->sessionStarted = false;
        $this->sessionValidated = false;
    }

    public function user(): ?array
    {
        $this->startSession();
        if (!isset($_SESSION['atum_user']) || !is_array($_SESSION['atum_user'])) {
            return null;
        }
        if ($this->sessionValidated) {
            return $_SESSION['atum_user'];
        }

        $sessionUser = $_SESSION['atum_user'];
        $statement = $this->state->db()->prepare('SELECT id,username,role,enabled,session_version FROM users WHERE id=:id LIMIT 1');
        $statement->execute([':id' => (int) ($sessionUser['id'] ?? 0)]);
        $current = $statement->fetch();
        if (!is_array($current) || !(bool) $current['enabled'] || (int) ($sessionUser['session_version'] ?? 0) !== (int) $current['session_version']) {
            $this->logout(false);
            return null;
        }

        $_SESSION['atum_user'] = [
            'id' => (int) $current['id'],
            'username' => (string) $current['username'],
            'role' => (string) $current['role'],
            'session_version' => (int) $current['session_version'],
        ];
        $this->sessionValidated = true;
        return $_SESSION['atum_user'];
    }

    public function isAuthenticated(): bool
    {
        return $this->user() !== null;
    }

    public function hasPermission(string $permission): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }
        if (($user['role'] ?? '') === 'admin') {
            return true;
        }
        return $permission === 'view';
    }

    public function requirePermission(string $permission): void
    {
        if (!$this->isAuthenticated()) {
            throw new RuntimeException('Authentication required.');
        }
        if (!$this->hasPermission($permission)) {
            throw new RuntimeException('Permission denied.');
        }
    }

    public function csrfToken(): string
    {
        $this->startSession();
        $_SESSION['atum_csrf'] ??= bin2hex(random_bytes(32));
        return (string) $_SESSION['atum_csrf'];
    }

    public function validateCsrf(?string $token): bool
    {
        $expected = $this->csrfToken();
        return is_string($token) && strlen($token) >= 32 && hash_equals($expected, $token);
    }

    public function users(): array
    {
        return $this->state->db()->query('SELECT id,username,role,enabled,created_at,updated_at,last_login_at FROM users ORDER BY username COLLATE NOCASE')->fetchAll();
    }

    public function changePassword(int $userId, string $password): void
    {
        $this->validatePassword($password);
        $exists = $this->state->db()->prepare('SELECT id FROM users WHERE id=:id LIMIT 1');
        $exists->execute([':id' => $userId]);
        if ($exists->fetchColumn() === false) {
            throw new RuntimeException('Unknown Atum user.');
        }
        $hash = password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
        if ($hash === false) {
            throw new RuntimeException('Unable to hash password.');
        }
        $this->state->db()->prepare('UPDATE users SET password_hash=:hash,session_version=session_version+1,updated_at=:updated WHERE id=:id')->execute([
            ':hash' => $hash,
            ':updated' => gmdate(DATE_ATOM),
            ':id' => $userId,
        ]);
        $this->audit->log('user.password.change', 'success', 'user', (string) $userId);
    }


    public function setEnabled(int $userId, bool $enabled): void
    {
        $statement = $this->state->db()->prepare('SELECT id,username,role,enabled FROM users WHERE id=:id');
        $statement->execute([':id' => $userId]);
        $user = $statement->fetch();
        if (!is_array($user)) {
            throw new RuntimeException('Unknown Atum user.');
        }
        if (!$enabled && $user['role'] === 'admin' && (bool) $user['enabled'] && $this->adminCount() <= 1) {
            throw new RuntimeException('The last enabled administrator cannot be disabled.');
        }
        $this->state->db()->prepare('UPDATE users SET enabled=:enabled,session_version=session_version+1,updated_at=:updated WHERE id=:id')->execute([
            ':enabled' => $enabled ? 1 : 0,
            ':updated' => gmdate(DATE_ATOM),
            ':id' => $userId,
        ]);
        $this->audit->log('user.' . ($enabled ? 'enable' : 'disable'), 'success', 'user', (string) $userId, (string) $user['username']);
    }

    public function deleteUser(int $userId): void
    {
        $statement = $this->state->db()->prepare('SELECT id,username,role,enabled FROM users WHERE id=:id');
        $statement->execute([':id' => $userId]);
        $user = $statement->fetch();
        if (!is_array($user)) {
            throw new RuntimeException('Unknown Atum user.');
        }
        if ($user['role'] === 'admin' && (bool) $user['enabled'] && $this->adminCount() <= 1) {
            throw new RuntimeException('The last enabled administrator cannot be deleted.');
        }
        $this->state->db()->prepare('DELETE FROM users WHERE id=:id')->execute([':id' => $userId]);
        $this->audit->log('user.delete', 'success', 'user', (string) $userId, (string) $user['username']);
    }

    public function adminCount(): int
    {
        return (int) $this->state->db()->query("SELECT COUNT(*) FROM users WHERE role='admin' AND enabled=1")->fetchColumn();
    }


    private function validatePassword(string $password): void
    {
        $length = strlen($password);
        if ($length < 12) {
            throw new InvalidArgumentException('Password must be at least 12 characters.');
        }
        if ($length > self::MAX_PASSWORD_BYTES) {
            throw new InvalidArgumentException('Password is too long.');
        }
    }

    private function dummyPasswordHash(): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return '$argon2id$v=19$m=65536,t=4,p=1$em5zLnpOci9FZVBSSG0vUQ$R/bh4qkHp+mgEqxrYfN0VOHcpr5WMGK/dJaqHA6h8Mg';
        }
        return '$2y$12$OfjULTGLySo784qorINA6uCP1w26Z3dqx.au4LFG83NsB/TqapZlu';
    }

    private function isHttps(): bool
    {
        return AtumSecurity::isHttps();
    }
}
