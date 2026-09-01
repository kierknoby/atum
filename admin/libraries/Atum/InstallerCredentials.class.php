<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

final class AtumInstallerCredentials
{
    private const MIN_PASSWORD_BYTES = 12;
    private const MAX_PASSWORD_BYTES = 1024;

    /**
     * Collect and validate the first administrator credentials, then pass the
     * accepted pair to the persistence callback exactly once.
     */
    public static function createAdministrator(callable $createUser): string
    {
        $presetUser = getenv('ATUM_ADMIN_USER');
        $passwordFile = getenv('ATUM_ADMIN_PASSWORD_FILE');
        $hasPresetUser = $presetUser !== false && $presetUser !== '';
        $hasPasswordFile = $passwordFile !== false && $passwordFile !== '';

        if ($hasPresetUser !== $hasPasswordFile) {
            throw new InvalidArgumentException(
                'ATUM_ADMIN_USER and ATUM_ADMIN_PASSWORD_FILE must be supplied together.'
            );
        }

        if ($hasPresetUser) {
            $username = trim((string) $presetUser);
            self::validateUsername($username);
            $password = self::readPasswordFile((string) $passwordFile);
            self::validatePassword($password);
            try {
                $createUser($username, $password);
            } finally {
                $password = '';
            }
            return $username;
        }

        while (true) {
            $username = self::readUsername();
            try {
                self::validateUsername($username);
            } catch (InvalidArgumentException $exception) {
                self::reject($exception->getMessage());
                continue;
            }

            while (true) {
                $password = self::readSecret('Administrator password: ');
                try {
                    self::validatePassword($password);
                } catch (InvalidArgumentException $exception) {
                    $password = '';
                    self::reject($exception->getMessage());
                    continue;
                }

                $confirmation = self::readSecret('Confirm password: ');
                if (!hash_equals($password, $confirmation)) {
                    $password = '';
                    $confirmation = '';
                    self::reject('Administrator passwords do not match.');
                    continue;
                }

                try {
                    $createUser($username, $password);
                } finally {
                    $password = '';
                    $confirmation = '';
                }
                return $username;
            }
        }
    }

    private static function readUsername(): string
    {
        $tty = @fopen('/dev/tty', 'r+');
        if ($tty === false) {
            throw new RuntimeException('A TTY or ATUM_ADMIN_USER is required to create the first administrator.');
        }
        try {
            if (fwrite($tty, 'Administrator username [admin]: ') === false) {
                throw new RuntimeException('Unable to prompt for administrator credentials.');
            }
            $line = fgets($tty);
            if ($line === false) {
                throw new RuntimeException('Administrator credential input was aborted.');
            }
        } finally {
            fclose($tty);
        }
        $value = trim($line);
        return $value !== '' ? $value : 'admin';
    }

    private static function readSecret(string $prompt): string
    {
        $tty = @fopen('/dev/tty', 'r+');
        if ($tty === false) {
            throw new RuntimeException(
                'A TTY or ATUM_ADMIN_PASSWORD_FILE is required to create the first administrator.'
            );
        }
        if (fwrite($tty, $prompt) === false) {
            fclose($tty);
            throw new RuntimeException('Unable to prompt for administrator credentials.');
        }
        $state = trim((string) shell_exec('stty -g < /dev/tty'));
        shell_exec('stty -echo < /dev/tty');
        try {
            $line = fgets($tty);
            if ($line === false) {
                throw new RuntimeException('Administrator credential input was aborted.');
            }
            return rtrim($line, "\r\n");
        } finally {
            shell_exec('stty ' . escapeshellarg($state !== '' ? $state : 'echo') . ' < /dev/tty');
            fwrite($tty, "\n");
            fclose($tty);
        }
    }

    private static function readPasswordFile(string $path): string
    {
        $value = @file_get_contents($path);
        if ($value === false) {
            throw new RuntimeException('Unable to read ATUM_ADMIN_PASSWORD_FILE.');
        }
        return rtrim($value, "\r\n");
    }

    private static function validateUsername(string $username): void
    {
        if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
            throw new InvalidArgumentException(
                'Username must be 3-64 characters using letters, numbers, dot, underscore or hyphen.'
            );
        }
    }

    private static function validatePassword(string $password): void
    {
        $length = strlen($password);
        if ($length < self::MIN_PASSWORD_BYTES) {
            throw new InvalidArgumentException('Password must be at least 12 characters.');
        }
        if ($length > self::MAX_PASSWORD_BYTES) {
            throw new InvalidArgumentException('Password is too long.');
        }
    }

    private static function reject(string $problem): void
    {
        fwrite(STDERR, "Administrator credentials rejected: {$problem}\n");
    }
}
