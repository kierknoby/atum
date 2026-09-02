<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

final class AtumRemoteDeployment
{
    /** @var list<array{type:string,path:string,sha256?:string,target?:string}> */
    private array $created = [];
    /** @var list<array{service:string,active:bool}> */
    private array $serviceStates = [];
    private bool $verbose = false;

    public function __construct(private readonly string $transactionDir)
    {
    }

    /** @param array<string,string> $options
     *  @return array{created_files:array,service_states:array,web_server:string,fpm_service:string,tls_certificate:string,tls_key:string}
     */
    public function install(array $options): array
    {
        $result = $this->prepare($options);
        return $this->activate($options, $result);
    }

    /** @param array<string,string> $options
     *  @return array{created_files:array,service_states:array,web_server:string,fpm_service:string,tls_certificate:string,tls_key:string}
     */
    public function prepare(array $options): array
    {
        $this->verbose = ($options['verbose'] ?? '0') === '1';
        $required = ['target', 'config-dir', 'listen-address', 'listen-port', 'web-server',
            'web-config', 'fpm-config', 'fpm-socket', 'fpm-service', 'web-service', 'web-group',
            'fpm-binary', 'web-config-test-binary', 'web-config-test-argument'];
        foreach ($required as $key) {
            if (($options[$key] ?? '') === '') {
                throw new RuntimeException('Remote deployment option is missing: ' . $key);
            }
        }

        $address = $options['listen-address'];
        $port = $options['listen-port'];
        if (!filter_var($address, FILTER_VALIDATE_IP) || !preg_match('/^[0-9]{1,5}$/', $port)
            || (int) $port < 1 || (int) $port > 65535) {
            throw new RuntimeException('Invalid remote listen address or port.');
        }
        foreach (['web-config', 'fpm-config', 'fpm-socket'] as $key) {
            $this->assertAbsoluteSafePath($options[$key]);
        }
        foreach (['target', 'state-dir', 'config-dir'] as $key) {
            $this->assertAbsoluteSafePath($options[$key]);
        }
        foreach (['fpm-binary', 'web-config-test-binary'] as $key) {
            $this->assertAbsoluteSafePath($options[$key]);
            if (!is_executable($options[$key])) {
                throw new RuntimeException('Configuration validator is not executable: ' . $key);
            }
        }
        if (!in_array($options['web-config-test-argument'], ['-t', 'configtest'], true)) {
            throw new RuntimeException('Invalid web-server configuration-test argument.');
        }
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $options['web-group'])) {
            throw new RuntimeException('Invalid web-server group name.');
        }

        $tlsDir = $options['config-dir'] . '/tls';
        if (!is_dir($tlsDir) && !mkdir($tlsDir, 0750, true) && !is_dir($tlsDir)) {
            throw new RuntimeException('Unable to create the Atum TLS directory.');
        }
        if (!chmod($tlsDir, 0750)) {
            throw new RuntimeException('Unable to secure the Atum TLS directory.');
        }
        $certificate = $tlsDir . '/development.crt';
        $key = $tlsDir . '/development.key';
        if (file_exists($certificate) || file_exists($key)) {
            throw new RuntimeException('Refusing to overwrite existing TLS material.');
        }
        $openssl = $options['openssl'] ?? 'openssl';
        $subject = '/CN=' . ($address === '0.0.0.0' || $address === '::' ? 'Atum development' : $address);
        $command = escapeshellarg($openssl) . ' req -x509 -newkey rsa:3072 -sha256 -nodes -days 30'
            . ' -subj ' . escapeshellarg($subject)
            . ' -keyout ' . escapeshellarg($key) . ' -out ' . escapeshellarg($certificate);
        $this->showCommand($command);
        exec($command . ' 2>&1', $output, $status);
        $this->showOutput($output);
        if ($status !== 0 || !is_file($certificate) || !is_file($key)) {
            @unlink($certificate); @unlink($key);
            throw new RuntimeException($this->withCommandOutput(
                'Unable to generate the self-signed development certificate.',
                $output
            ));
        }
        if (!chmod($key, 0600) || !chmod($certificate, 0644)) {
            @unlink($certificate); @unlink($key);
            throw new RuntimeException('Unable to secure generated Atum TLS material.');
        }
        $this->recordFile($certificate);
        $this->recordFile($key);

        $pool = $this->fpmPool($options);
        $this->createFile($options['fpm-config'], $pool, 0644);

        $web = $options['web-server'] === 'nginx'
            ? $this->nginxConfig($options, $certificate, $key)
            : $this->apacheConfig($options, $certificate, $key);
        $this->createFile($options['web-config'], $web, 0644);

        $this->validateCommand($options['fpm-binary'], '-t', 'PHP-FPM');
        $this->validateCommand($options['web-config-test-binary'], $options['web-config-test-argument'], ucfirst($options['web-server']));

        return [
            'created_files' => $this->created,
            'service_states' => [],
            'web_server' => $options['web-server'],
            'fpm_service' => $options['fpm-service'],
            'tls_certificate' => $certificate,
            'tls_key' => $key,
        ];
    }

    /** @param array<string,string> $options
     *  @param array{created_files:array,service_states:array,web_server:string,fpm_service:string,tls_certificate:string,tls_key:string} $result
     *  @return array{created_files:array,service_states:array,web_server:string,fpm_service:string,tls_certificate:string,tls_key:string}
     */
    public function activate(array $options, array $result): array
    {
        if (($options['web-enable-link'] ?? '') !== '') {
            $this->createLink($options['web-enable-link'], $options['web-config']);
        }

        foreach (array_values(array_unique([$options['fpm-service'], $options['web-service']])) as $index => $service) {
            $wasActive = $this->serviceIsActive($options['service-command'] ?? 'systemctl', $service);
            $record = $this->transactionDir . '/service-state-' . ($index + 1);
            if (file_put_contents($record, $service . "\n" . ($wasActive ? 'active' : 'inactive') . "\n", LOCK_EX) === false) {
                throw new RuntimeException('Unable to journal a service state.');
            }
            chmod($record, 0600);
            $this->serviceStates[] = ['service' => $service, 'active' => $wasActive];
            $action = $wasActive ? 'reload' : 'start';
            $this->service($options['service-command'] ?? 'systemctl', $action, $service);
            $this->service($options['service-command'] ?? 'systemctl', 'is-active', $service);
        }

        $result['created_files'] = $this->created;
        $result['service_states'] = $this->serviceStates;
        return $result;
    }

    /** @param array<string,string> $options */
    private function fpmPool(array $options): string
    {
        return "; Atum remote development pool - NOT SUITABLE FOR PRODUCTION\n"
            . "[atum]\nuser = atum\ngroup = atum\n"
            . "listen = {$options['fpm-socket']}\nlisten.owner = atum\nlisten.group = {$options['web-group']}\nlisten.mode = 0660\n"
            . "pm = ondemand\npm.max_children = 4\npm.process_idle_timeout = 10s\npm.max_requests = 200\n"
            . "clear_env = yes\ncatch_workers_output = no\nsecurity.limit_extensions = .php\n"
            . "php_admin_value[session.save_path] = {$options['state-dir']}/sessions\n"
            . "php_admin_value[upload_max_filesize] = 2M\nphp_admin_value[post_max_size] = 2M\n"
            . "php_admin_value[memory_limit] = 128M\nphp_admin_value[max_execution_time] = 30\n"
            . "env[ATUM_STATE_DIR] = {$options['state-dir']}\nenv[ATUM_CONFIG_DIR] = {$options['config-dir']}\n";
    }

    /** @param array<string,string> $options */
    private function nginxConfig(array $options, string $certificate, string $key): string
    {
        $root = $options['target'] . '/public';
        $endpoint = $this->endpoint($options['listen-address'], $options['listen-port']);
        return "# Atum remote development vhost - NOT SUITABLE FOR PRODUCTION\n"
            . "server {\n    listen {$endpoint} ssl;\n"
            . "    server_name _;\n    root {$root};\n    index index.php;\n"
            . "    ssl_certificate {$certificate};\n    ssl_certificate_key {$key};\n"
            . "    ssl_protocols TLSv1.2 TLSv1.3;\n    server_tokens off;\n"
            . "    location / { try_files \$uri \$uri/ /index.php?\$query_string; }\n"
            . "    location ~ \\.php$ { try_files \$uri =404; include fastcgi_params; fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name; fastcgi_param HTTPS on; fastcgi_pass unix:{$options['fpm-socket']}; }\n"
            . "    location ~ /\\. { deny all; }\n}\n";
    }

    /** @param array<string,string> $options */
    private function apacheConfig(array $options, string $certificate, string $key): string
    {
        $root = $options['target'] . '/public';
        $endpoint = $this->endpoint($options['listen-address'], $options['listen-port']);
        return "# Atum remote development vhost - NOT SUITABLE FOR PRODUCTION\n"
            . "Listen {$endpoint}\n"
            . "<VirtualHost {$endpoint}>\n"
            . "    DocumentRoot {$root}\n    SSLEngine on\n    SSLCertificateFile {$certificate}\n    SSLCertificateKeyFile {$key}\n"
            . "    <Directory {$root}>\n        Options -Indexes -ExecCGI\n        AllowOverride None\n        Require all granted\n"
            . "        DirectoryIndex index.php\n    </Directory>\n"
            . "    <FilesMatch \"\\.php$\">\n        SetHandler \"proxy:unix:{$options['fpm-socket']}|fcgi://localhost/\"\n    </FilesMatch>\n"
            . "</VirtualHost>\n";
    }

    private function createFile(string $path, string $contents, int $mode): void
    {
        $this->assertAbsoluteSafePath($path);

        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Refusing to overwrite existing host configuration: ' . $path);
        }

        $directory = dirname($path);
        if (!is_dir($directory)) {
            throw new RuntimeException('Required host configuration directory does not exist: ' . $directory);
        }

        // Construct the file completely under a private temporary name in the same
        // directory. Recovery may remove this transient path regardless of content
        // because it is journalled before creation and is never an operator file.
        $temporary = $directory . '/.' . basename($path)
            . '.atum-' . bin2hex(random_bytes(6)) . '.tmp';

        $this->assertAbsoluteSafePath($temporary);
        $transientRecord = $this->journalTransient($temporary);

        $handle = @fopen($temporary, 'x');
        if ($handle === false) {
            @unlink($transientRecord);
            throw new RuntimeException('Unable to create temporary host configuration for ' . $path);
        }

        try {
            $length = strlen($contents);
            $written = 0;

            while ($written < $length) {
                $result = fwrite($handle, substr($contents, $written));
                if ($result === false || $result === 0) {
                    throw new RuntimeException('Unable to write temporary host configuration for ' . $path);
                }
                $written += $result;
            }

            if (!fflush($handle)) {
                throw new RuntimeException('Unable to flush temporary host configuration for ' . $path);
            }

            if (function_exists('fsync') && !fsync($handle)) {
                throw new RuntimeException('Unable to synchronise temporary host configuration for ' . $path);
            }
        } catch (Throwable $exception) {
            fclose($handle);
            if (@unlink($temporary) || !file_exists($temporary)) {
                @unlink($transientRecord);
            }
            throw $exception;
        }

        fclose($handle);

        if (!chmod($temporary, $mode)) {
            if (@unlink($temporary) || !file_exists($temporary)) {
                @unlink($transientRecord);
            }
            throw new RuntimeException('Unable to set host configuration permissions for ' . $path);
        }

        $entry = [
            'type' => 'file',
            'path' => $path,
            'sha256' => hash('sha256', $contents),
        ];

        $this->created[] = $entry;

        try {
            $finalRecord = $this->journalEntry($entry);
        } catch (Throwable $exception) {
            array_pop($this->created);
            if (@unlink($temporary) || !file_exists($temporary)) {
                @unlink($transientRecord);
            }
            throw $exception;
        }

        // link() publishes the completed inode without overwriting an object that
        // appeared at the destination between validation and publication.
        if (!@link($temporary, $path)) {
            array_pop($this->created);
            @unlink($finalRecord);
            if (@unlink($temporary) || !file_exists($temporary)) {
                @unlink($transientRecord);
            }
            throw new RuntimeException('Unable or unwilling to publish host configuration: ' . $path);
        }

        if (!@unlink($temporary)) {
            throw new RuntimeException('Unable to remove temporary host configuration for ' . $path);
        }

        @unlink($transientRecord);
    }

    private function recordFile(string $path): void
    {
        $entry = ['type' => 'file', 'path' => $path, 'sha256' => (string) hash_file('sha256', $path)];
        $this->created[] = $entry;
        $this->journalEntry($entry);
    }

    private function createLink(string $path, string $target): void
    {
        $this->assertAbsoluteSafePath($path);
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Unable or unwilling to create web-server enablement link: ' . $path);
        }
        $entry = ['type' => 'symlink', 'path' => $path, 'target' => $target];
        $this->created[] = $entry;
        $this->journalEntry($entry);
        if (!symlink($target, $path)) {
            throw new RuntimeException('Unable or unwilling to create web-server enablement link: ' . $path);
        }
    }

    private function journalTransient(string $path): string
    {
        $record = $this->transactionDir . '/host-created-transient-' . bin2hex(random_bytes(6));
        $temporary = $record . '.tmp';
        $value = "transient\n{$path}\n\n";

        if (file_put_contents($temporary, $value, LOCK_EX) === false || !rename($temporary, $record)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to journal a transient remote deployment artefact.');
        }

        chmod($record, 0600);

        return $record;
    }

    /** @param array{type:string,path:string,sha256?:string,target?:string} $entry */
    private function journalEntry(array $entry): string
    {
        $number = count($this->created);
        $record = $this->transactionDir . '/host-created-' . $number;
        $temporary = $this->transactionDir . '/.host-created-' . $number . '.tmp';
        $value = $entry['type'] . "\n" . $entry['path'] . "\n"
            . ($entry['sha256'] ?? $entry['target'] ?? '') . "\n";

        if (file_put_contents($temporary, $value, LOCK_EX) === false || !rename($temporary, $record)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to journal a remote deployment artefact.');
        }

        chmod($record, 0600);

        return $record;
    }

    private function service(string $command, string $action, string $service): void
    {
        if (!preg_match('/^[A-Za-z0-9@_.-]+$/', $service)) {
            throw new RuntimeException('Invalid service name.');
        }
        $commandLine = escapeshellarg($command) . ' ' . $action . ' ' . escapeshellarg($service);
        $this->showCommand($commandLine);
        exec($commandLine . ' 2>&1', $output, $status);
        $this->showOutput($output);
        if ($status !== 0) {
            throw new RuntimeException($this->withCommandOutput(
                'Unable to ' . $action . ' service ' . $service . '.',
                $output
            ));
        }
    }

    private function serviceIsActive(string $command, string $service): bool
    {
        if (!preg_match('/^[A-Za-z0-9@_.-]+$/', $service)) {
            throw new RuntimeException('Invalid service name.');
        }
        $commandLine = escapeshellarg($command) . ' is-active ' . escapeshellarg($service);
        $this->showCommand($commandLine);
        exec($commandLine . ' 2>&1', $output, $status);
        $this->showOutput($output);
        if ($status === 0) {
            return true;
        }
        if ($status === 3) {
            return false;
        }
        throw new RuntimeException($this->withCommandOutput(
            'Unable to determine service state for ' . $service . '.',
            $output
        ));
    }

    private function validateCommand(string $command, string $argument, string $label): void
    {
        $commandLine = escapeshellarg($command) . ' ' . escapeshellarg($argument);
        $this->showCommand($commandLine);
        exec($commandLine . ' 2>&1', $output, $status);
        $this->showOutput($output);
        if ($status !== 0) {
            throw new RuntimeException($this->withCommandOutput(
                $label . ' rejected the generated configuration.',
                $output
            ));
        }
    }

    /** @param list<string> $output */
    private function showOutput(array $output): void
    {
        if ($this->verbose && $output !== []) {
            echo implode("\n", $output) . "\n";
        }
    }

    private function showCommand(string $command): void
    {
        if ($this->verbose) {
            echo '$ ' . $command . "\n";
        }
    }

    /** @param list<string> $output */
    private function withCommandOutput(string $message, array $output): string
    {
        $details = trim(implode("\n", $output));
        return $details === '' ? $message : $message . "\n" . $details;
    }

    public function rollback(string $serviceCommand = 'systemctl'): void
    {
        foreach (array_reverse($this->created) as $entry) {
            $path = $entry['path'];
            if ($entry['type'] === 'file' && is_file($path)
                && hash_equals((string) ($entry['sha256'] ?? ''), (string) hash_file('sha256', $path))) {
                @unlink($path);
            } elseif ($entry['type'] === 'symlink' && is_link($path)
                && readlink($path) === ($entry['target'] ?? '')) {
                @unlink($path);
            }
        }
        foreach ($this->serviceStates as $serviceState) {
            try {
                $this->service($serviceCommand, $serviceState['active'] ? 'reload' : 'stop', $serviceState['service']);
            } catch (Throwable) { }
        }
    }

    private function assertAbsoluteSafePath(string $path): void
    {
        if ($path === '' || $path === '/' || !str_starts_with($path, '/') || !preg_match('#^/[A-Za-z0-9._/-]+$#', $path)
            || str_contains($path, '/../')) {
            throw new RuntimeException('Unsafe remote deployment path.');
        }
    }

    private function endpoint(string $address, string $port): string
    {
        return str_contains($address, ':') ? '[' . $address . ']:' . $port : $address . ':' . $port;
    }
}
