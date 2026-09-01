#!/usr/bin/env php
<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/admin/libraries/Atum/InstallerCredentials.class.php';

$record = $argv[1] ?? '';
$stages = $argv[2] ?? '';
if ($record === '' || $stages === '') {
    fwrite(STDERR, "Credential fixture paths are required.\n");
    exit(2);
}

// Represents package/web/FPM work completed by the caller before credentials.
file_put_contents($stages, "caller-infrastructure-complete\n", FILE_APPEND | LOCK_EX);
$createCalls = 0;

try {
    $username = AtumInstallerCredentials::createAdministrator(
        static function (string $acceptedUsername, string $acceptedPassword) use (&$createCalls, $record): void {
            $createCalls++;
            $result = ['create_calls' => $createCalls, 'username' => $acceptedUsername];
            file_put_contents(
                $record,
                json_encode($result, JSON_THROW_ON_ERROR) . "\n",
                LOCK_EX
            );
        }
    );
    echo "Initial administrator: {$username}\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Install failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
