<?php
// SPDX-License-Identifier: GPL-3.0-or-later

final class AtumKamailioScanner
{
    private const MAX_INCLUDE_DEPTH = 10;
    private array $seen = [], $moduleIndexes = [], $report = [];

    public function scan(string $root): array
    {
        $this->seen = $this->moduleIndexes = [];
        $this->report = ['root' => $this->absolute($root), 'files' => [], 'modules' => [], 'listeners' => [], 'routes' => [], 'defines' => [], 'includes' => [], 'warnings' => [], 'unknown' => [], 'custom' => [], 'database_schemes' => [], 'kemi_detected' => false, 'read_only' => true,
            'completeness' => ['scope' => 'statically recognised literal configuration', 'confidence' => 'partial', 'effective_configuration_proven' => false, 'reasons' => ['conditional preprocessing and custom/KEMI logic are not evaluated']]];
        $this->scanFile($this->report['root'], 0);
        foreach ($this->report['modules'] as &$module) { usort($module['params'], static fn($a, $b) => $a['name'] <=> $b['name']); }
        unset($module); sort($this->report['files']);
        $this->report['completeness']['reasons'] = array_values(array_unique($this->report['completeness']['reasons']));
        return $this->report;
    }

    private function scanFile(string $path, int $depth): void
    {
        if ($depth > self::MAX_INCLUDE_DEPTH) { $this->warning('include_depth', 'Static include depth limit exceeded', $path, 0); return; }
        $path = $this->absolute($path); if (isset($this->seen[$path])) { return; } $this->seen[$path] = true;
        $handle = @fopen($path, 'rb'); if (!$handle) { throw new RuntimeException('Unable to open Kamailio configuration: ' . $path); }
        $this->report['files'][] = $path; $number = 0; $block = false; $conditional = 0;
        try {
            while (($raw = fgets($handle)) !== false) {
                $number++; [$line, $block] = $this->comments(rtrim($raw, "\r\n"), $block); if (trim($line) === '') { continue; }
                $source = ['file' => $path, 'line' => $number, 'confidence' => $conditional ? 'conditional' : 'syntactic']; $trim = trim($line);
                if (preg_match('/^(?:#!|!!)(?:if|ifdef|ifndef)\b/i', $trim)) { $conditional++; $source['confidence'] = 'conditional'; $this->unknown($line, $source, 'preprocessor-conditional'); continue; }
                if (preg_match('/^(?:#!|!!)(?:else|elif)\b/i', $trim)) { $this->unknown($line, $source, 'preprocessor-conditional'); continue; }
                if (preg_match('/^(?:#!|!!)endif\b/i', $trim)) { $this->unknown($line, $source, 'preprocessor-conditional'); $conditional = max(0, $conditional - 1); continue; }
                $source['confidence'] = $conditional ? 'conditional' : 'syntactic';
                if (preg_match('/\b(?:kemi|ksr\.xhttp|app_lua|app_python3|app_jsdt)\b/i', $line)) { $this->report['kemi_detected'] = true; $this->report['custom'][] = ['kind' => 'kemi-indicator', 'source' => $source]; }
                if (preg_match('/^\s*loadmodule\s+(["\'])(.*?)\1/', $line, $m)) { $this->module(pathinfo(basename($m[2]), PATHINFO_FILENAME), $source); continue; }
                if (str_contains($line, 'modparam') && substr_count($line, '(') > substr_count($line, ')')) {
                    $start = $number;
                    while (($nextRaw = fgets($handle)) !== false) { $number++; [$next, $block] = $this->comments(rtrim($nextRaw, "\r\n"), $block); $line .= ' ' . trim($next); if (substr_count($line, '(') <= substr_count($line, ')')) { break; } }
                    $source['line'] = $start;
                }
                if (preg_match('/^\s*modparam\s*\(\s*(["\'])(.*?)\1\s*,\s*(["\'])(.*?)\3\s*,\s*(.+)\)\s*;?\s*$/', $line, $m)) {
                    $value = trim($m[5]); $this->scheme($value); $safe = $this->safe($m[4], $value);
                    $param = ['module' => $m[2], 'name' => $m[4], 'value' => $safe ? $value : (str_contains($value, '://') ? '"<redacted-dsn>"' : '"<redacted>"'), 'value_classification' => $safe ? 'safe' : 'redacted-unclassified', 'source' => $source];
                    foreach (explode('|', $m[2]) as $name) { $this->report['modules'][$this->module(trim($name), $source)]['params'][] = $param; } continue;
                }
                if (preg_match('/^\s*listen\s*=\s*(.+?)\s*;?\s*$/', $line, $m)) { $listener = trim($m[1]); $safeListener = preg_match('/^(?:(?:udp|tcp|tls|sctp|ws|wss):)?(?:\[[0-9a-f:]+\]|[A-Za-z0-9_.-]+)(?::[0-9]{1,5})?(?:\s+advertise\s+(?:\[[0-9a-f:]+\]|[A-Za-z0-9_.-]+)(?::[0-9]{1,5})?)?$/i', trim($listener, "\"'")) === 1; $this->report['listeners'][] = ['raw' => $safeListener ? $listener : '"<redacted-unclassified>"', 'value_classification' => $safeListener ? 'safe-network-listener' : 'redacted-unclassified', 'source' => $source]; continue; }
                if (preg_match('/^\s*(?:#!|!!)?define\s+([A-Za-z_][A-Za-z0-9_]*)(?:\s+(.+?))?\s*$/', $line, $m)) { $value = trim($m[2] ?? ''); $this->scheme($value); $this->report['defines'][] = ['name' => $m[1], 'value' => $value === '' ? '' : '"<redacted>"', 'value_classification' => $value === '' ? 'none' : 'redacted-unclassified', 'source' => $source]; continue; }
                if (preg_match('/^\s*(?:(?:#!|!!)?(include_file|import_file))\s+(["\'])(.*?)\2/', $line, $m)) {
                    $resolved = $this->includePath($path, $m[3]); $exists = is_file($resolved); $optional = $m[1] === 'import_file';
                    $this->report['includes'][] = ['path' => $m[3], 'resolved' => $resolved, 'optional' => $optional, 'exists' => $exists, 'source' => $source];
                    if ($exists) { $this->scanFile($resolved, $depth + 1); } elseif (!$optional) { $this->warning('missing_include', 'Required literal include not found', $path, $number); } continue;
                }
                if (preg_match('/^\s*(?:(?:#!|!!)?(?:include_file|import_file))\b/', $line)) { $this->unknown($line, $source, 'non-literal-include'); $this->report['completeness']['reasons'][] = 'a non-literal include could not be resolved'; continue; }
                if (preg_match('/^\s*request_route\s*\{/', $line)) { $this->report['routes'][] = ['type' => 'request_route', 'source' => $source]; continue; }
                if (preg_match('/^\s*(route|failure_route|branch_route|onreply_route|onsend_route|event_route)\s*\[\s*([^\]]+)\s*\]\s*\{/', $line, $m)) { $this->report['routes'][] = ['type' => $m[1], 'name' => trim($m[2]), 'source' => $source]; continue; }
                $this->unknown($line, $source, 'unsupported-or-unparsed');
            }
        } finally { fclose($handle); }
    }

    private function module(string $name, array $source): int { if (isset($this->moduleIndexes[$name])) { return $this->moduleIndexes[$name]; } $i = count($this->report['modules']); $this->moduleIndexes[$name] = $i; $this->report['modules'][] = ['name' => $name, 'source' => $source, 'params' => []]; return $i; }
    private function safe(string $name, string $value): bool { if (!preg_match('/^(?:debug|children|workers|processes|port|timeout|retry_count|max_size|log_level|use_domain|db_mode)$/i', $name)) { return false; } return preg_match('/^(?:-?[0-9]+|yes|no|true|false|on|off|[A-Za-z][A-Za-z0-9_-]{0,31})$/i', trim($value, " \t\n\r\0\x0B\"'")) === 1; }
    private function scheme(string $value): void { if (preg_match('/(?:^|[^A-Za-z0-9+.-])([a-z][a-z0-9+.-]*):\/\//i', $value, $m)) { $s = strtolower($m[1]); if (!in_array($s, $this->report['database_schemes'], true)) { $this->report['database_schemes'][] = $s; sort($this->report['database_schemes']); } } }
    private function unknown(string $line, array $source, string $kind): void { preg_match('/^\s*((?:#!|!!)?[A-Za-z_][A-Za-z0-9_.-]*)/', $line, $match); $keyword = $match[1] ?? 'statement'; $this->report['unknown'][] = ['kind' => $kind, 'excerpt' => $keyword . ' <content-redacted>', 'source' => $source]; }
    private function warning(string $code, string $message, string $file, int $line): void { $this->report['warnings'][] = ['code' => $code, 'message' => $message, 'source' => ['file' => $file, 'line' => $line]]; }
    private function includePath(string $parent, string $requested): string { return $requested !== '' && $requested[0] === '/' ? $this->absolute($requested) : $this->absolute(dirname($parent) . '/' . $requested); }
    private function absolute(string $path): string { if ($path === '') { throw new InvalidArgumentException('Configuration path is empty'); } if ($path[0] !== '/') { $path = getcwd() . '/' . $path; } $dir = realpath(dirname($path)); return $dir === false ? $path : $dir . '/' . basename($path); }
    private function comments(string $line, bool $block): array { $out = ''; $quote = null; $escaped = false; for ($i = 0, $n = strlen($line); $i < $n;) { if ($block) { if (substr($line, $i, 2) === '*/') { $block = false; $i += 2; } else { $i++; } continue; } $c = $line[$i]; if ($quote !== null) { $out .= $c; if ($escaped) { $escaped = false; } elseif ($c === '\\') { $escaped = true; } elseif ($c === $quote) { $quote = null; } $i++; continue; } if ($c === '"' || $c === "'") { $quote = $c; $out .= $c; $i++; continue; } if (substr($line, $i, 2) === '/*') { $block = true; $i += 2; continue; } if (substr($line, $i, 2) === '//' || ($c === '#' && ($line[$i + 1] ?? '') !== '!')) { break; } $out .= $c; $i++; } return [$out, $block]; }
}
