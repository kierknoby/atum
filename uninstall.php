#!/usr/bin/env php
<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$stateDir = getenv('ATUM_STATE_DIR') ?: '/var/lib/atum';
$configDirOverride = getenv('ATUM_CONFIG_DIR') ?: '/etc/atum';
$checkOnly = in_array('--check', $argv, true);
$keepDependencies = in_array('--keep-dependencies', $argv, true);
$assumeYes = in_array('--yes', $argv, true) || in_array('-y', $argv, true);
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--state-dir=')) {
        $stateDir = substr($arg, strlen('--state-dir='));
    } elseif (str_starts_with($arg, '--config-dir=')) {
        $configDirOverride = substr($arg, strlen('--config-dir='));
        $ledgerPath = rtrim($configDirOverride, '/') . '/install-ledger.json';
    }
}

$ledgerPath = rtrim($configDirOverride, '/') . '/install-ledger.json';
$lockPath = getenv('ATUM_LIFECYCLE_LOCK_PATH') ?: '/run/atum';
if (!str_starts_with($lockPath, '/') || $lockPath === '/') {
    fwrite(STDERR, "Lifecycle lock path is unsafe: {$lockPath}\n");
    exit(1);
}
$lockParent = dirname($lockPath);
$parentStat = @lstat($lockParent);
if (!is_array($parentStat) || !is_dir($lockParent) || is_link($lockParent)
    || (int) ($parentStat['uid'] ?? -1) !== 0 || (((int) ($parentStat['mode'] ?? 0)) & 0022) !== 0) {
    fwrite(STDERR, "Lifecycle lock parent is not a secure root-controlled directory: {$lockParent}\n");
    exit(1);
}
if (!file_exists($lockPath) && !@mkdir($lockPath, 0700)) {
    fwrite(STDERR, "Unable to create lifecycle lock directory: {$lockPath}\n");
    exit(1);
}
$lockStat = @stat($lockPath);
if (!is_array($lockStat) || !is_dir($lockPath) || is_link($lockPath)
    || (int) ($lockStat['uid'] ?? -1) !== 0 || (((int) ($lockStat['mode'] ?? 0)) & 0077) !== 0) {
    fwrite(STDERR, "Lifecycle lock path is not a secure root-controlled directory: {$lockPath}\n");
    exit(1);
}
$lifecycleLock = @fopen($lockPath, 'r');
if ($lifecycleLock === false || !flock($lifecycleLock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another Atum install or uninstall operation is already running.\n");
    exit(1);
}
if (!is_readable($ledgerPath)) {
    fwrite(STDERR, "Atum install ledger not found: {$ledgerPath}\nRefusing to guess what belongs to Atum.\n");
    exit(1);
}
$ledgerStat = @stat($ledgerPath);
if (!is_array($ledgerStat) || (int) $ledgerStat['uid'] !== 0 || (((int) $ledgerStat['mode']) & 0022) !== 0) {
    fwrite(STDERR, "Atum install ledger is not securely root-owned; refusing to use it.\n");
    exit(1);
}
$ledger = json_decode((string) file_get_contents($ledgerPath), true, 512, JSON_THROW_ON_ERROR);
if (($ledger['schema'] ?? null) !== 1) {
    fwrite(STDERR, "Unsupported install-ledger schema.\n");
    exit(1);
}

$applicationPath = (string) ($ledger['paths']['application'] ?? '');
$configDir = (string) ($ledger['paths']['configuration'] ?? '');
$stateDir = (string) ($ledger['paths']['state'] ?? $stateDir);
$packages = is_array($ledger['packages_added'] ?? null) ? $ledger['packages_added'] : [];
$account = is_array($ledger['system_account'] ?? null) ? $ledger['system_account'] : [];

function verifyOwnedTree(string $path, string $installId): bool
{
    if ($path === '' || $path === '/' || !str_starts_with($path, '/')) {
        return false;
    }
    if (!file_exists($path) && !is_link($path)) {
        return true; // already removed by a previous interrupted uninstall attempt
    }
    $marker = rtrim($path, '/') . '/.atum-install-id';
    if (!is_readable($marker)) {
        return false;
    }
    return hash_equals($installId, trim((string) file_get_contents($marker)));
}

function removeTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_link($path) || is_file($path)) {
        if (!unlink($path)) {
            throw new RuntimeException('Unable to remove ' . $path);
        }
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $ok = $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        if (!$ok) {
            throw new RuntimeException('Unable to remove ' . $item->getPathname());
        }
    }
    if (!rmdir($path)) {
        throw new RuntimeException('Unable to remove ' . $path);
    }
}

function commandExists(string $name): bool
{
    $result = shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null');
    return is_string($result) && trim($result) !== '';
}

function confirmRemoval(bool $assumeYes): bool
{
    if ($assumeYes) {
        return true;
    }
    $tty = @fopen('/dev/tty', 'r+');
    if ($tty === false) {
        return false;
    }
    fwrite($tty, "Remove Atum and all Atum-owned state? [y/N] ");
    $answer = strtolower(trim((string) fgets($tty)));
    fclose($tty);
    return in_array($answer, ['y', 'yes'], true);
}

$installId = (string) ($ledger['install_id'] ?? '');
if ($installId === '' || !preg_match('/^[a-f0-9]{32}$/', $installId)) {
    fwrite(STDERR, "Install ledger has no valid installation ID.\n");
    exit(1);
}
foreach ([$applicationPath, $stateDir, $configDir] as $path) {
    if (!verifyOwnedTree($path, $installId)) {
        fwrite(STDERR, "Atum ownership marker does not match the install ledger: {$path}\nRefusing to continue.\n");
        exit(1);
    }
}

$kamailioChangedByInstaller = (bool) ($ledger['kamailio']['installer_modified'] ?? true);
if ($kamailioChangedByInstaller) {
    fwrite(STDERR, "Ledger indicates installation modified Kamailio. Automatic clean removal is unavailable.\n");
    exit(1);
}

// Pre-validate every host artefact before --check can succeed or any
// destructive uninstall operation begins.
$preflightModifiedHostFiles = $ledger['host_integrations']['modified_files'] ?? [];
if (!is_array($preflightModifiedHostFiles)) {
    throw new RuntimeException('Invalid modified-file records in install ledger.');
}

foreach ($preflightModifiedHostFiles as $entry) {
    $path = (string) ($entry['path'] ?? '');
    $afterHash = (string) ($entry['after_sha256'] ?? '');
    $backup = (string) ($entry['backup'] ?? '');

    if ($path === '' || $afterHash === '' || $backup === '' || !is_readable($backup)) {
        throw new RuntimeException('Incomplete modified-file record in install ledger.');
    }
    if (!is_file($path) || is_link($path)) {
        throw new RuntimeException('Host file recorded as modified by Atum is missing or no longer a regular file: ' . $path);
    }

    $currentHash = (string) hash_file('sha256', $path);
    $originalHash = (string) ($entry['before_sha256'] ?? hash_file('sha256', $backup));

    if (!hash_equals($originalHash, $currentHash) && !hash_equals($afterHash, $currentHash)) {
        throw new RuntimeException('Host file changed since Atum installed its integration; refusing to overwrite it: ' . $path);
    }
}

$preflightCreatedHostFiles = $ledger['host_integrations']['created_files'] ?? [];
if (!is_array($preflightCreatedHostFiles)) {
    throw new RuntimeException('Invalid created-file records in install ledger.');
}

$disabledDefaultSites = $ledger['host_integrations']['disabled_default_sites'] ?? [];
if (!is_array($disabledDefaultSites)) {
    throw new RuntimeException('Invalid disabled default-site records in install ledger.');
}
foreach ($disabledDefaultSites as $entry) {
    $sitePath = (string) ($entry['path'] ?? '');
    $siteTarget = (string) ($entry['target'] ?? '');
    if (!preg_match('#^/[A-Za-z0-9._/-]+$#', $sitePath)
        || !in_array($siteTarget, ['../sites-available/default', '/etc/nginx/sites-available/default', '../sites-available/000-default.conf', '/etc/apache2/sites-available/000-default.conf'], true)) {
        throw new RuntimeException('Invalid disabled default-site record in install ledger.');
    }
    if (file_exists($sitePath) || is_link($sitePath)) {
        throw new RuntimeException('Default web-server site changed after Atum disabled it; refusing to overwrite it: ' . $sitePath);
    }
}

foreach ($preflightCreatedHostFiles as $entry) {
    $path = (string) ($entry['path'] ?? '');
    $type = (string) ($entry['type'] ?? '');
    $expectedHash = (string) ($entry['sha256'] ?? '');

    if ($path === '' || !in_array($type, ['file', 'symlink'], true)) {
        throw new RuntimeException('Invalid created-file record in install ledger.');
    }

    if ($type === 'symlink') {
        if (is_link($path)) {
            if (readlink($path) !== (string) ($entry['target'] ?? '')) {
                throw new RuntimeException('Atum-created host symlink was changed after installation; refusing to delete it: ' . $path);
            }
        } elseif (file_exists($path)) {
            throw new RuntimeException('Atum-created host symlink path is now occupied by another object; refusing to delete it: ' . $path);
        }
        continue;
    }

    if (is_link($path)) {
        throw new RuntimeException('Atum-created host file was replaced by a symlink; refusing to delete it: ' . $path);
    }
    if (!file_exists($path)) {
        continue;
    }
    if (!is_file($path) || $expectedHash === ''
        || !hash_equals($expectedHash, (string) hash_file('sha256', $path))) {
        throw new RuntimeException('Atum-created host file was changed after installation; refusing to delete it: ' . $path);
    }
}

$preflightLinks = [
    '/usr/local/sbin/atum' => $applicationPath . '/bin/atum',
    '/usr/local/sbin/atum-uninstall' => $applicationPath . '/uninstall.php',
];

foreach ($preflightLinks as $link => $expected) {
    if (is_link($link)) {
        $actual = readlink($link);
        if ($actual !== $expected) {
            throw new RuntimeException("Refusing to remove changed symlink {$link} -> {$actual}");
        }
    } elseif (file_exists($link)) {
        throw new RuntimeException("Refusing to remove non-symlink path {$link}");
    }
}

echo "Atum clean-removal plan\n\n";
echo "Application     : {$applicationPath}\n";
echo "Configuration   : {$configDir}\n";
echo "State           : {$stateDir}\n";
echo "CLI links       : /usr/local/sbin/atum, /usr/local/sbin/atum-uninstall\n";
echo "System user     : " . (($account['user_created'] ?? false) ? 'remove atum' : 'leave pre-existing account') . "\n";
echo "System group    : " . (($account['group_created'] ?? false) ? 'remove atum' : 'leave pre-existing group') . "\n";
$packageSummary = $packages ? implode(', ', $packages) : 'none';
if ($packages && in_array((string) ($ledger['package_manager'] ?? ''), ['dnf', 'yum'], true) && !$keepDependencies) {
    $packageSummary .= ' (retained by v0.1 on RPM systems; safe reverse-dependency proof is not implemented)';
}
echo "Packages added  : {$packageSummary}\n";
echo "Kamailio        : untouched by installation; no Kamailio files will be removed or restored\n";
$remote = is_array($ledger['remote_development'] ?? null) ? $ledger['remote_development'] : null;
if ($remote !== null) {
    echo "Remote access   : remove Atum " . ($remote['web_server'] ?? 'web-server') . " vhost, dedicated PHP-FPM pool and generated TLS material\n";
    foreach (($ledger['host_integrations']['created_files'] ?? []) as $entry) {
        echo "  remove        : " . ($entry['path'] ?? '[invalid ledger path]') . "\n";
    }
}

if ($checkOnly) {
    exit(0);
}
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Run as root to uninstall a system installation.\n");
    exit(1);
}
if (!confirmRemoval($assumeYes)) {
    fwrite(STDERR, "Uninstall cancelled.\n");
    exit(1);
}

// Stop only services explicitly recorded as Atum-created integrations.
foreach (($ledger['host_integrations']['services'] ?? []) as $service) {
    if (!is_string($service) || !preg_match('/^[A-Za-z0-9@_.-]+$/', $service)) {
        throw new RuntimeException('Invalid service name in install ledger.');
    }
    if (commandExists('systemctl')) {
        passthru('systemctl stop ' . escapeshellarg($service), $serviceStatus);
        if ($serviceStatus !== 0) {
            throw new RuntimeException('Unable to stop Atum service: ' . $service);
        }
    }
}

// Restore shared host files only when they are still exactly in the state
// Atum wrote. If an administrator or another package changed one afterwards,
// do not destroy that work in the name of cleanup.
foreach (($ledger['host_integrations']['modified_files'] ?? []) as $entry) {
    $path = (string) ($entry['path'] ?? '');
    $afterHash = (string) ($entry['after_sha256'] ?? '');
    $backup = (string) ($entry['backup'] ?? '');
    if ($path === '' || $afterHash === '' || $backup === '' || !is_readable($backup)) {
        throw new RuntimeException('Incomplete modified-file record in install ledger.');
    }
    if (!is_file($path)) {
        throw new RuntimeException('Host file recorded as modified by Atum is missing: ' . $path);
    }
    $currentHash = (string) hash_file('sha256', $path);
    $originalHash = (string) ($entry['before_sha256'] ?? hash_file('sha256', $backup));
    if (hash_equals($originalHash, $currentHash)) {
        continue; // already restored by an earlier interrupted uninstall attempt
    }
    if (!hash_equals($afterHash, $currentHash)) {
        throw new RuntimeException('Host file changed since Atum installed its integration; refusing to overwrite it: ' . $path);
    }
    if (!copy($backup, $path)) {
        throw new RuntimeException('Unable to restore host file: ' . $path);
    }
}

$createdHostFiles = $ledger['host_integrations']['created_files'] ?? [];
if (!is_array($createdHostFiles)) {
    throw new RuntimeException('Invalid created-file records in install ledger.');
}

// Verify every remaining Atum-created host artefact before deleting any of
// them. A conflict must leave the complete integration intact for a safe retry.
foreach ($createdHostFiles as $entry) {
    $path = (string) ($entry['path'] ?? '');
    $type = (string) ($entry['type'] ?? 'file');
    $expectedHash = (string) ($entry['sha256'] ?? '');
    if ($path === '') {
        throw new RuntimeException('Invalid created-file path in install ledger.');
    }
    if ($type === 'symlink') {
        if (!is_link($path)) { continue; }
        if (readlink($path) !== (string) ($entry['target'] ?? '')) {
            throw new RuntimeException('Atum-created host symlink was changed after installation; refusing to delete it: ' . $path);
        }
    } else {
        if (!is_file($path)) { continue; }
        if (is_link($path) || $expectedHash === '' || !hash_equals($expectedHash, (string) hash_file('sha256', $path))) {
            throw new RuntimeException('Atum-created host file was changed after installation; refusing to delete it: ' . $path);
        }
    }
}

foreach ($createdHostFiles as $entry) {
    $path = (string) ($entry['path'] ?? '');
    $type = (string) ($entry['type'] ?? 'file');
    if ($type === 'symlink') {
        if (!is_link($path)) { continue; }
    } else {
        if (!is_file($path) || is_link($path)) { continue; }
    }
    unlink($path);
}

foreach ($disabledDefaultSites as $entry) {
    $sitePath = (string) ($entry['path'] ?? '');
    $siteTarget = (string) ($entry['target'] ?? '');
    if (!symlink($siteTarget, $sitePath)) {
        throw new RuntimeException('Unable to restore package default web-server site: ' . $sitePath);
    }
}

// Restore each service to the state captured before Atum activated its
// integration. Services remain host-owned: Atum never disables them here.
foreach (($ledger['host_integrations']['service_states'] ?? []) as $serviceState) {
    $service = is_array($serviceState) ? (string) ($serviceState['service'] ?? '') : '';
    $wasActive = is_array($serviceState) ? ($serviceState['active'] ?? null) : null;
    if (!preg_match('/^[A-Za-z0-9@_.-]+$/', $service) || !is_bool($wasActive)) {
        throw new RuntimeException('Invalid service-state record in install ledger.');
    }
    if (commandExists('systemctl')) {
        $action = $wasActive ? 'reload' : 'stop';
        passthru('systemctl ' . $action . ' ' . escapeshellarg($service), $serviceStatus);
        if ($serviceStatus !== 0) {
            throw new RuntimeException('Unable to ' . $action . ' host service after removing Atum integration: ' . $service);
        }
    }
}

if (($account['user_created'] ?? false) && commandExists('ps')) {
    $processes = trim((string) shell_exec('ps -u atum -o pid= 2>/dev/null'));
    if ($processes !== '') {
        fwrite(STDERR, "Atum processes remain after disabling host integration; retaining the ledger so removal can be retried.\n");
        exit(1);
    }
}

// Remove only symlinks that still point at the paths Atum created.
$links = [
    '/usr/local/sbin/atum' => $applicationPath . '/bin/atum',
    '/usr/local/sbin/atum-uninstall' => $applicationPath . '/uninstall.php',
];
foreach ($links as $link => $expected) {
    if (is_link($link)) {
        $actual = readlink($link);
        if ($actual !== $expected) {
            throw new RuntimeException("Refusing to remove changed symlink {$link} -> {$actual}");
        }
        unlink($link);
    } elseif (file_exists($link)) {
        throw new RuntimeException("Refusing to remove non-symlink path {$link}");
    }
}

// Remove the dedicated account only if Atum created it. Do this before
// deleting the ledger so a failure remains safely retryable. No -r: state is
// removed from the ledger path explicitly and userdel must never chase files.
if (($account['user_created'] ?? false) && commandExists('userdel') && commandExists('id')) {
    exec('id atum >/dev/null 2>&1', $unused, $userExists);
    if ($userExists === 0) {
        exec('userdel atum 2>/dev/null', $unused, $userDeleteStatus);
        if ($userDeleteStatus !== 0) {
            throw new RuntimeException('Unable to remove the Atum system user.');
        }
    }
}
if (($account['group_created'] ?? false) && commandExists('groupdel')) {
    $groupExists = false;
    if (commandExists('getent')) {
        exec('getent group atum >/dev/null 2>&1', $unused, $groupStatus);
        $groupExists = $groupStatus === 0;
    } elseif (is_readable('/etc/group')) {
        $groupExists = preg_match('/^atum:/m', (string) file_get_contents('/etc/group')) === 1;
    }
    if ($groupExists) {
        exec('groupdel atum 2>/dev/null', $unused, $groupDeleteStatus);
        if ($groupDeleteStatus !== 0) {
            throw new RuntimeException('Unable to remove the Atum system group.');
        }
    }
}

// Package rollback is conservative. It is attempted only for packages that
// were absent in the install-time snapshot. If the package manager wants to
// remove anything outside that set, keep the packages rather than harm a host
// that has evolved since Atum was installed.
if (!$keepDependencies && $packages) {
    $pm = (string) ($ledger['package_manager'] ?? '');
    if ($pm === 'apt-get' && commandExists('apt-get')) {
        $args = implode(' ', array_map('escapeshellarg', $packages));
        $simulation = (string) shell_exec('DEBIAN_FRONTEND=noninteractive apt-get -s purge ' . $args . ' 2>/dev/null');
        preg_match_all('/^Remv\s+(\S+)/m', $simulation, $matches);
        $planned = array_values(array_unique($matches[1] ?? []));
        $unexpected = array_values(array_diff($planned, $packages));
        if (!$unexpected) {
            passthru('DEBIAN_FRONTEND=noninteractive apt-get purge -y ' . $args, $status);
            if ($status !== 0) {
                fwrite(STDERR, "Dependency cleanup failed; Atum itself has still been removed.\n");
            }
        } else {
            fwrite(STDERR, "Dependencies retained because removing them would now affect other packages: " . implode(', ', $unexpected) . "\n");
        }
    } elseif (in_array($pm, ['dnf', 'yum'], true) && commandExists($pm)) {
        fwrite(STDERR, "Atum-added RPM dependencies are retained by default because safe reverse-dependency proof is not yet implemented for {$pm}.\n");
    }
}

// Core Atum trees are removed last. Configuration, which contains the
// authoritative ledger, is deliberately the final tree so an interrupted
// uninstall remains retryable for as long as possible.
removeTree($applicationPath);
removeTree($stateDir);
removeTree($configDir);

echo "\nAtum application, configuration and state removed. Kamailio was not modified by uninstall.\n";
