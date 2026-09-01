<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

final class AtumModules
{
    private array $manifests = [];
    private array $instances = [];

    public function __construct(private Atum $Atum)
    {
        $this->refresh();
    }

    public function refresh(): void
    {
        $this->manifests = [];
        $this->instances = [];
        $paths = glob(ATUM_MODULES . '/*/module.xml') ?: [];
        sort($paths);

        foreach ($paths as $path) {
            $manifest = AtumManifest::parse($path);
            $manifest['path'] = dirname($path);
            $manifest['default_enabled'] = (bool) $manifest['enabled'];
            $state = $this->moduleState($manifest['rawname']);
            if ($state === null) {
                $manifest['installed'] = false;
                $manifest['enabled'] = false;
                $manifest['installed_version'] = null;
            } else {
                $manifest['installed'] = (bool) $state['installed'];
                $manifest['enabled'] = (bool) $state['enabled'];
                $manifest['installed_version'] = (string) $state['version'];
            }
            $this->manifests[$manifest['rawname']] = $manifest;
        }
    }

    public function getInfo(?string $rawname = null): array
    {
        return $rawname === null ? $this->manifests : ($this->manifests[$rawname] ?? []);
    }

    public function getActiveModules(): array
    {
        return array_filter($this->manifests, static fn(array $module): bool => $module['installed'] && $module['enabled']);
    }

    public function checkStatus(string $rawname): bool
    {
        return isset($this->manifests[$rawname]) && $this->manifests[$rawname]['installed'] && $this->manifests[$rawname]['enabled'];
    }

    public function load(string $rawname): object
    {
        if (isset($this->instances[$rawname])) {
            return $this->instances[$rawname];
        }
        if (!$this->checkStatus($rawname)) {
            throw new RuntimeException('Module is not installed or enabled: ' . $rawname);
        }
        $problems = $this->dependencyProblems($rawname);
        if ($problems !== []) {
            throw new RuntimeException('Module dependency failure for ' . $rawname . ': ' . implode('; ', $problems));
        }

        $manifest = $this->manifests[$rawname];
        $class = self::className($rawname);
        $file = $manifest['path'] . '/' . $class . '.class.php';
        if (!is_readable($file)) {
            throw new RuntimeException('Module class not found: ' . $file);
        }
        require_once $file;
        if (!class_exists($class)) {
            throw new RuntimeException('Module class ' . $class . ' was not defined by ' . $file);
        }
        $instance = new $class($this->Atum);
        if (!$instance instanceof AtumModule) {
            throw new RuntimeException('Module ' . $rawname . ' must extend AtumModule');
        }
        return $this->instances[$rawname] = $instance;
    }

    public function getMenu(): array
    {
        $menu = [];
        foreach ($this->getActiveModules() as $module) {
            foreach ($module['menuitems'] as $item) {
                if ($item['id'] === '' || !$this->Atum->Auth->hasPermission((string) ($item['permission'] ?? 'view'))) {
                    continue;
                }
                $category = $item['category'] !== '' ? $item['category'] : 'Applications';
                $menu[$category][] = array_merge($item, ['module' => $module['rawname']]);
            }
        }
        ksort($menu);
        foreach ($menu as &$items) {
            usort($items, static fn(array $a, array $b): int => [$a['sort'], $a['name']] <=> [$b['sort'], $b['name']]);
        }
        unset($items);
        return $menu;
    }

    public function pageFile(string $display): ?string
    {
        foreach ($this->getActiveModules() as $module) {
            foreach ($module['menuitems'] as $item) {
                if ($item['id'] === $display && $this->Atum->Auth->hasPermission((string) ($item['permission'] ?? 'view'))) {
                    $page = $module['path'] . '/page.' . $display . '.php';
                    return is_readable($page) ? $page : null;
                }
            }
        }
        return null;
    }

    public function dependencyProblems(string $rawname, bool $forInstall = false): array
    {
        $module = $this->manifests[$rawname] ?? null;
        if ($module === null) {
            return ['module is not present'];
        }
        $problems = [];
        $phpVersion = (string) ($module['phpversion'] ?? '');
        if ($phpVersion !== '' && version_compare(PHP_VERSION, $phpVersion, '<')) {
            $problems[] = 'requires PHP ' . $phpVersion . ' or newer';
        }
        foreach ($module['extensions'] ?? [] as $extension) {
            if (!extension_loaded((string) $extension)) {
                $problems[] = 'missing PHP extension ' . $extension;
            }
        }
        foreach ($module['depends'] ?? [] as $dependency) {
            $dep = $this->manifests[$dependency] ?? null;
            if ($dep === null) {
                $problems[] = 'missing Atum module ' . $dependency;
            } elseif (!$dep['installed']) {
                $problems[] = 'Atum module is not installed: ' . $dependency;
            } elseif (!$forInstall && !$dep['enabled']) {
                $problems[] = 'Atum module is disabled: ' . $dependency;
            }
        }
        return $problems;
    }

    /** Install all modules shipped in the application tree in dependency order. */
    public function installBundled(bool $trustedLocal = false): void
    {
        $visiting = [];
        foreach (array_keys($this->manifests) as $rawname) {
            $this->installRecursive($rawname, $visiting, $trustedLocal);
        }
    }

    public function install(string $rawname, bool $trustedLocal = false): void
    {
        $visiting = [];
        $this->installRecursive($rawname, $visiting, $trustedLocal);
    }

    private function installRecursive(string $rawname, array &$visiting, bool $trustedLocal): void
    {
        if (!$trustedLocal) {
            $this->Atum->Auth->requirePermission('admin');
        }
        if (!isset($this->manifests[$rawname])) {
            throw new RuntimeException('Unknown module: ' . $rawname);
        }
        if ($this->manifests[$rawname]['installed']) {
            $this->upgradeIfNeeded($rawname, $trustedLocal);
            return;
        }
        if (isset($visiting[$rawname])) {
            throw new RuntimeException('Circular module dependency involving ' . $rawname);
        }
        $visiting[$rawname] = true;
        foreach ($this->manifests[$rawname]['depends'] ?? [] as $dependency) {
            $this->installRecursive($dependency, $visiting, $trustedLocal);
        }
        unset($visiting[$rawname]);

        $problems = $this->dependencyProblems($rawname, !($this->manifests[$rawname]['default_enabled'] ?? false));
        if ($problems !== []) {
            throw new RuntimeException('Cannot install ' . $rawname . ': ' . implode('; ', $problems));
        }
        $manifest = $this->manifests[$rawname];
        $db = $this->Atum->State->db();
        $db->beginTransaction();
        try {
            $this->runHook($manifest, 'install.php');
            $statement = $db->prepare('INSERT INTO module_state (rawname,installed,enabled,version,updated_at) VALUES (:rawname,1,:enabled,:version,:updated) ON CONFLICT(rawname) DO UPDATE SET installed=1,enabled=excluded.enabled,version=excluded.version,updated_at=excluded.updated_at');
            $statement->execute([
                ':rawname' => $rawname,
                ':enabled' => $manifest['default_enabled'] ? 1 : 0,
                ':version' => $manifest['version'],
                ':updated' => gmdate(DATE_ATOM),
            ]);
            $db->commit();
            $this->Atum->Audit->log('module.install', 'success', 'module', $rawname, 'Version ' . $manifest['version']);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            try { $this->runHook($manifest, 'uninstall.php'); } catch (Throwable) { /* best effort hook rollback */ }
            $this->Atum->Audit->log('module.install', 'failure', 'module', $rawname, $e->getMessage());
            throw $e;
        }
        $this->refresh();
    }

    public function uninstall(string $rawname, bool $trustedLocal = false): void
    {
        if (!$trustedLocal) {
            $this->Atum->Auth->requirePermission('admin');
        }
        if ($rawname === 'framework') {
            throw new RuntimeException('The Atum framework cannot be uninstalled independently.');
        }
        $manifest = $this->manifests[$rawname] ?? null;
        if ($manifest === null || !$manifest['installed']) {
            throw new RuntimeException('Module is not installed: ' . $rawname);
        }
        foreach ($this->manifests as $candidate) {
            if ($candidate['installed'] && in_array($rawname, $candidate['depends'] ?? [], true)) {
                throw new RuntimeException('Cannot uninstall ' . $rawname . '; required by ' . $candidate['rawname']);
            }
        }
        $db = $this->Atum->State->db();
        $db->beginTransaction();
        try {
            $this->runHook($manifest, 'uninstall.php');
            $db->prepare('UPDATE module_state SET installed=0,enabled=0,updated_at=:updated WHERE rawname=:rawname')->execute([
                ':updated' => gmdate(DATE_ATOM), ':rawname' => $rawname,
            ]);
            $db->commit();
            $this->Atum->Audit->log('module.uninstall', 'success', 'module', $rawname);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->Atum->Audit->log('module.uninstall', 'failure', 'module', $rawname, $e->getMessage());
            throw $e;
        }
        $this->refresh();
    }

    public function upgradeIfNeeded(string $rawname, bool $trustedLocal = false): void
    {
        if (!$trustedLocal) {
            $this->Atum->Auth->requirePermission('admin');
        }
        $manifest = $this->manifests[$rawname] ?? null;
        if ($manifest === null || !$manifest['installed']) {
            return;
        }
        $installed = (string) ($manifest['installed_version'] ?? '0');
        if (version_compare($installed, (string) $manifest['version'], '>=')) {
            return;
        }
        $problems = $this->dependencyProblems($rawname, !($manifest['enabled'] ?? false));
        if ($problems !== []) {
            throw new RuntimeException('Cannot upgrade ' . $rawname . ': ' . implode('; ', $problems));
        }

        $db = $this->Atum->State->db();
        $db->beginTransaction();
        try {
            $this->runHook($manifest, 'install.php');
            $db->prepare('UPDATE module_state SET version=:version,updated_at=:updated WHERE rawname=:rawname')->execute([
                ':version' => $manifest['version'],
                ':updated' => gmdate(DATE_ATOM),
                ':rawname' => $rawname,
            ]);
            $db->commit();
            $this->Atum->Audit->log('module.upgrade', 'success', 'module', $rawname, $installed . ' -> ' . $manifest['version']);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->Atum->Audit->log('module.upgrade', 'failure', 'module', $rawname, $e->getMessage());
            throw $e;
        }
        $this->refresh();
    }

    public function setEnabled(string $rawname, bool $enabled, bool $trustedLocal = false): void
    {
        if (!$trustedLocal) {
            $this->Atum->Auth->requirePermission('admin');
        }
        $module = $this->manifests[$rawname] ?? null;
        if ($module === null || !$module['installed']) {
            throw new RuntimeException('Module is not installed: ' . $rawname);
        }
        if ($rawname === 'framework' && !$enabled) {
            throw new RuntimeException('The Atum framework cannot be disabled.');
        }
        if (!$enabled) {
            foreach ($this->manifests as $candidate) {
                if ($candidate['installed'] && $candidate['enabled'] && in_array($rawname, $candidate['depends'] ?? [], true)) {
                    throw new RuntimeException('Cannot disable ' . $rawname . '; required by ' . $candidate['rawname']);
                }
            }
        } else {
            $problems = $this->dependencyProblems($rawname, false);
            if ($problems !== []) {
                throw new RuntimeException('Cannot enable ' . $rawname . ': ' . implode('; ', $problems));
            }
        }
        $this->Atum->State->db()->prepare('UPDATE module_state SET enabled=:enabled,updated_at=:updated WHERE rawname=:rawname')->execute([
            ':enabled' => $enabled ? 1 : 0, ':updated' => gmdate(DATE_ATOM), ':rawname' => $rawname,
        ]);
        $this->Atum->Audit->log('module.' . ($enabled ? 'enable' : 'disable'), 'success', 'module', $rawname);
        $this->refresh();
    }

    private function runHook(array $manifest, string $filename): void
    {
        $hook = $manifest['path'] . '/' . $filename;
        if (!is_file($hook)) {
            return;
        }
        $Atum = $this->Atum; // deliberately available to FreePBX-style module hook files
        include $hook;
    }

    private function moduleState(string $rawname): ?array
    {
        $statement = $this->Atum->State->db()->prepare('SELECT * FROM module_state WHERE rawname=:rawname');
        $statement->execute([':rawname' => $rawname]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public static function className(string $rawname): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $rawname)));
    }
}
