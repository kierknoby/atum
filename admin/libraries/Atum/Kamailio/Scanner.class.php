<?php
// SPDX-License-Identifier: GPL-3.0-or-later

final class AtumKamailioScanner
{
    private const MAX_INCLUDE_DEPTH = 10;

    private array $seen = [];
    private array $moduleIndexes = [];

    private array $report = [];

    public function scan(string $root): array
    {
        $absolute = $this->absolutePath($root);
        $this->seen = [];
        $this->moduleIndexes = [];
        $this->report = [
            'root' => $absolute,
            'files' => [],
            'modules' => [],
            'listeners' => [],
            'routes' => [],
            'defines' => [],
            'includes' => [],
            'warnings' => [],
            'database_schemes' => [],
            'kemi_detected' => false,
            'read_only' => true,
        ];

        $this->scanFile($absolute, 0);

        foreach ($this->report['modules'] as &$module) {
            usort($module['params'], static fn(array $a, array $b): int => $a['name'] <=> $b['name']);
        }
        unset($module);

        sort($this->report['files']);
        return $this->report;
    }

    private function scanFile(string $path, int $depth): void
    {
        if ($depth > self::MAX_INCLUDE_DEPTH) {
            $this->warning('include_depth', "Include depth exceeds Kamailio's documented limit", $path, 0);
            return;
        }

        $absolute = $this->absolutePath($path);
        if (isset($this->seen[$absolute])) {
            return;
        }
        $this->seen[$absolute] = true;

        $handle = @fopen($absolute, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open Kamailio configuration: ' . $absolute);
        }

        $this->report['files'][] = $absolute;
        $lineNumber = 0;
        $inBlockComment = false;

        while (($raw = fgets($handle)) !== false) {
            $lineNumber++;
            [$line, $inBlockComment] = $this->stripComments(rtrim($raw, "\r\n"), $inBlockComment);
            if (trim($line) === '') {
                continue;
            }

            $source = ['file' => $absolute, 'line' => $lineNumber];

            if (preg_match('/\b(?:kemi|ksr\.xhttp|app_lua|app_python3|app_jsdt)\b/i', $line)) {
                $this->report['kemi_detected'] = true;
            }

            if (preg_match('/^\s*loadmodule\s+"([^"]+)"/', $line, $match)) {
                $name = pathinfo(basename($match[1]), PATHINFO_FILENAME);
                $this->ensureModule($name, $source);
                continue;
            }

            if (preg_match('/^\s*modparam\s*\(\s*"([^"]+)"\s*,\s*"([^"]+)"\s*,\s*(.+)\)\s*;?\s*$/', $line, $match)) {
                if (str_contains(strtolower($match[2]), 'db_url')) {
                    $this->captureDatabaseScheme(trim($match[3]));
                }

                $param = [
                    'module' => $match[1],
                    'name' => $match[2],
                    'value' => $this->redactValue($match[2], trim($match[3])),
                    'source' => $source,
                ];

                foreach (explode('|', $match[1]) as $moduleName) {
                    $moduleName = trim($moduleName);
                    $index = $this->ensureModule($moduleName, $source);
                    $this->report['modules'][$index]['params'][] = $param;
                }
                continue;
            }

            if (preg_match('/^\s*listen\s*=\s*(.+?)\s*;?\s*$/', $line, $match)) {
                $this->report['listeners'][] = ['raw' => trim($match[1]), 'source' => $source];
                continue;
            }

            if (preg_match('/^\s*(?:#!|!!)?define\s+([A-Za-z_][A-Za-z0-9_]*)(?:\s+(.+?))?\s*$/', $line, $match)) {
                $rawDefineValue = isset($match[2]) ? trim($match[2]) : '';
                $this->captureDatabaseScheme($rawDefineValue);
                $this->report['defines'][] = [
                    'name' => $match[1],
                    'value' => $this->redactValue($match[1], $rawDefineValue),
                    'source' => $source,
                ];
                continue;
            }

            if (preg_match('/^\s*(?:(?:#!|!!)?(include_file|import_file))\s+"([^"]+)"/', $line, $match)) {
                $optional = $match[1] === 'import_file';
                $requested = $match[2];
                $resolved = $this->resolveInclude($absolute, $requested);
                $exists = is_file($resolved);

                $this->report['includes'][] = [
                    'path' => $requested,
                    'resolved' => $resolved,
                    'optional' => $optional,
                    'exists' => $exists,
                    'source' => $source,
                ];

                if ($exists) {
                    $this->scanFile($resolved, $depth + 1);
                } elseif (!$optional) {
                    $this->warning('missing_include', 'Required include not found: ' . $requested, $absolute, $lineNumber);
                }
                continue;
            }

            if (preg_match('/^\s*request_route\s*\{/', $line)) {
                $this->report['routes'][] = ['type' => 'request_route', 'source' => $source];
                continue;
            }

            if (preg_match('/^\s*(route|failure_route|branch_route|onreply_route|onsend_route|event_route)\s*\[\s*([^\]]+)\s*\]\s*\{/', $line, $match)) {
                $this->report['routes'][] = [
                    'type' => $match[1],
                    'name' => trim($match[2]),
                    'source' => $source,
                ];
            }
        }

        fclose($handle);
    }

    private function ensureModule(string $name, array $source): int
    {
        if (isset($this->moduleIndexes[$name])) {
            return $this->moduleIndexes[$name];
        }

        $index = count($this->report['modules']);
        $this->moduleIndexes[$name] = $index;
        $this->report['modules'][] = [
            'name' => $name,
            'source' => $source,
            'params' => [],
        ];
        return $index;
    }

    private function stripComments(string $line, bool $inBlock): array
    {
        $output = '';
        $length = strlen($line);
        $quote = null;
        $escaped = false;

        for ($i = 0; $i < $length;) {
            if ($inBlock) {
                if ($i + 1 < $length && substr($line, $i, 2) === '*/') {
                    $inBlock = false;
                    $i += 2;
                } else {
                    $i++;
                }
                continue;
            }

            $char = $line[$i];

            if ($quote !== null) {
                $output .= $char;
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                $i++;
                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $output .= $char;
                $i++;
                continue;
            }

            if ($i + 1 < $length && substr($line, $i, 2) === '/*') {
                $inBlock = true;
                $i += 2;
                continue;
            }

            if ($i + 1 < $length && substr($line, $i, 2) === '//') {
                break;
            }

            if ($char === '#' && !($i + 1 < $length && $line[$i + 1] === '!')) {
                break;
            }

            $output .= $char;
            $i++;
        }

        return [$output, $inBlock];
    }


    private function captureDatabaseScheme(string $value): void
    {
        $candidate = trim($value, " \t\n\r\0\x0B\"'");
        if (!preg_match('/(?:^|[^A-Za-z0-9+.-])([a-z][a-z0-9+.-]*):\/\//i', $candidate, $match)) {
            return;
        }

        $scheme = strtolower($match[1]);
        if (!in_array($scheme, $this->report['database_schemes'], true)) {
            $this->report['database_schemes'][] = $scheme;
            sort($this->report['database_schemes']);
        }
    }

    private function redactValue(string $parameter, string $value): string
    {
        $lower = strtolower($parameter . ' ' . $value);
        $secretName = preg_match('/(?:password|passwd|secret|token|api[_-]?key|credential|private[_-]?key)/i', $parameter) === 1;
        $credentialDsn = preg_match('/[a-z][a-z0-9+.-]*:\/\/[^\s\"\'@\/]+:[^\s\"\'@]+@/i', $value) === 1;
        if (str_contains($lower, 'db_url') || $secretName || $credentialDsn) {
            return str_contains($value, '://') ? '"<redacted-dsn>"' : '"<redacted>"';
        }

        return $value;
    }

    private function warning(string $code, string $message, string $file, int $line): void
    {
        $this->report['warnings'][] = [
            'code' => $code,
            'message' => $message,
            'source' => ['file' => $file, 'line' => $line],
        ];
    }

    private function resolveInclude(string $parent, string $requested): string
    {
        if ($requested !== '' && $requested[0] === '/') {
            return $this->absolutePath($requested);
        }

        return $this->absolutePath(dirname($parent) . '/' . $requested);
    }

    private function absolutePath(string $path): string
    {
        if ($path === '') {
            throw new InvalidArgumentException('Configuration path is empty');
        }

        if ($path[0] !== '/') {
            $path = getcwd() . '/' . $path;
        }

        $dir = realpath(dirname($path));
        if ($dir !== false) {
            return $dir . '/' . basename($path);
        }

        return $path;
    }

}
