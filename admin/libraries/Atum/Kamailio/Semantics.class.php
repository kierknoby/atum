<?php
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Adds conservative operator-facing meaning to factual Kamailio discovery.
 *
 * The scanner remains the authority for what was found. This service only
 * describes explicitly catalogued names and never classifies unknown modules.
 */
final class AtumKamailioSemantics
{
    private const GROUPS = [
        'core-sip' => 'Core SIP processing',
        'transactions-routing' => 'Transactions and routing',
        'dialog-state' => 'Dialog and state',
        'registration-location' => 'Registration and location',
        'authentication-permissions' => 'Authentication and permissions',
        'nat-media' => 'NAT and media',
        'dispatching' => 'Dispatching and load balancing',
        'database-storage' => 'Database and storage',
        'transport-protocol' => 'Transport and protocol',
        'observability' => 'Observability and diagnostics',
        'utilities' => 'Utilities',
        'unclassified' => 'Other / unclassified',
    ];

    /** @var array<string, array{group: string, purpose: string, capability?: string}> */
    private const MODULES = [
        'sl' => ['group' => 'core-sip', 'purpose' => 'Stateless SIP reply generation'],
        'maxfwd' => ['group' => 'core-sip', 'purpose' => 'Max-Forwards header processing'],
        'sanity' => ['group' => 'core-sip', 'purpose' => 'SIP message formatting sanity checks'],
        'siputils' => ['group' => 'core-sip', 'purpose' => 'General SIP message and URI utilities'],

        'tm' => ['group' => 'transactions-routing', 'purpose' => 'Stateful SIP transaction management', 'capability' => 'Transaction handling'],
        'tmx' => ['group' => 'transactions-routing', 'purpose' => 'Extended transaction-management functions', 'capability' => 'Transaction handling'],
        'rr' => ['group' => 'transactions-routing', 'purpose' => 'Record-Route and loose routing support', 'capability' => 'Record-Route support'],

        'dialog' => ['group' => 'dialog-state', 'purpose' => 'Stateful SIP dialog tracking', 'capability' => 'Dialog tracking'],

        'registrar' => ['group' => 'registration-location', 'purpose' => 'SIP registrar request handling', 'capability' => 'SIP registration support'],
        'usrloc' => ['group' => 'registration-location', 'purpose' => 'Registered-contact location storage', 'capability' => 'User location support'],

        'auth' => ['group' => 'authentication-permissions', 'purpose' => 'SIP authentication interface', 'capability' => 'SIP authentication support'],
        'auth_db' => ['group' => 'authentication-permissions', 'purpose' => 'Database-backed SIP authentication', 'capability' => 'Database-backed authentication'],
        'permissions' => ['group' => 'authentication-permissions', 'purpose' => 'Source-address and group permission checks', 'capability' => 'Permission checks'],

        'nathelper' => ['group' => 'nat-media', 'purpose' => 'NAT traversal and contact keepalive helpers', 'capability' => 'NAT traversal support'],
        'rtpengine' => ['group' => 'nat-media', 'purpose' => 'Media relay control through RTPengine', 'capability' => 'RTPengine media relay support'],
        'rtpproxy' => ['group' => 'nat-media', 'purpose' => 'Media relay control through RTPProxy', 'capability' => 'RTPProxy media relay support'],

        'dispatcher' => ['group' => 'dispatching', 'purpose' => 'Destination selection and load balancing', 'capability' => 'Dispatcher and load-balancing support'],
        'drouting' => ['group' => 'dispatching', 'purpose' => 'Database-backed dynamic prefix routing', 'capability' => 'Dynamic routing support'],

        'db_mysql' => ['group' => 'database-storage', 'purpose' => 'MySQL/MariaDB backend for the Kamailio database API', 'capability' => 'MySQL/MariaDB module support'],
        'db_postgres' => ['group' => 'database-storage', 'purpose' => 'PostgreSQL backend for the Kamailio database API', 'capability' => 'PostgreSQL module support'],
        'db_sqlite' => ['group' => 'database-storage', 'purpose' => 'SQLite backend for the Kamailio database API', 'capability' => 'SQLite module support'],
        'sqlops' => ['group' => 'database-storage', 'purpose' => 'SQL queries and result handling from routing logic', 'capability' => 'Direct SQL operation support'],

        'tls' => ['group' => 'transport-protocol', 'purpose' => 'TLS transport configuration and operations', 'capability' => 'TLS transport support'],
        'websocket' => ['group' => 'transport-protocol', 'purpose' => 'SIP transport over WebSocket connections', 'capability' => 'WebSocket transport support'],
        'tcpops' => ['group' => 'transport-protocol', 'purpose' => 'Runtime TCP connection controls'],
        'xhttp' => ['group' => 'transport-protocol', 'purpose' => 'Basic HTTP request handling'],

        'acc' => ['group' => 'observability', 'purpose' => 'SIP transaction accounting', 'capability' => 'SIP accounting support'],
        'debugger' => ['group' => 'observability', 'purpose' => 'Interactive configuration debugging'],
        'siptrace' => ['group' => 'observability', 'purpose' => 'SIP traffic tracing', 'capability' => 'SIP tracing support'],
        'xlog' => ['group' => 'observability', 'purpose' => 'Structured logging from routing logic'],

        'corex' => ['group' => 'utilities', 'purpose' => 'Extensions to Kamailio core operations'],
        'ctl' => ['group' => 'utilities', 'purpose' => 'Binary control interface for external management tools'],
        'jsonrpcs' => ['group' => 'utilities', 'purpose' => 'JSON-RPC server transport'],
        'kex' => ['group' => 'utilities', 'purpose' => 'Kamailio core extension functions'],
        'pv' => ['group' => 'utilities', 'purpose' => 'Additional pseudo-variables and transformations'],
        'textops' => ['group' => 'utilities', 'purpose' => 'SIP message text operations'],
        'textopsx' => ['group' => 'utilities', 'purpose' => 'Extended SIP message text operations'],
    ];

    public function present(array $report): array
    {
        $grouped = array_fill_keys(array_keys(self::GROUPS), []);
        $capabilityModules = [];
        $recognised = 0;

        foreach (($report['modules'] ?? []) as $module) {
            $name = strtolower((string) ($module['name'] ?? ''));
            $metadata = self::MODULES[$name] ?? null;
            $group = $metadata['group'] ?? 'unclassified';
            $module['semantic_status'] = $metadata === null ? 'unclassified' : 'recognised';
            $module['purpose'] = $metadata['purpose'] ?? 'No presentation metadata is available for this discovered module.';
            $module['group'] = $group;
            $module['group_label'] = self::GROUPS[$group];
            $grouped[$group][] = $module;

            if ($metadata !== null) {
                $recognised++;
                if (($module['source']['confidence'] ?? null) === 'syntactic' && isset($metadata['capability'])) {
                    $capabilityModules[$metadata['capability']][] = (string) $module['name'];
                }
            }
        }

        $groups = [];
        foreach (self::GROUPS as $key => $label) {
            if ($grouped[$key] === []) {
                continue;
            }
            usort($grouped[$key], static fn(array $a, array $b): int => strnatcasecmp((string) $a['name'], (string) $b['name']));
            $groups[] = ['key' => $key, 'label' => $label, 'modules' => $grouped[$key]];
        }

        $capabilities = [];
        foreach ($capabilityModules as $label => $moduleNames) {
            $moduleNames = array_values(array_unique($moduleNames));
            sort($moduleNames, SORT_NATURAL | SORT_FLAG_CASE);
            $capabilities[] = ['label' => $label, 'modules' => $moduleNames];
        }
        usort($capabilities, static fn(array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

        $total = count($report['modules'] ?? []);
        $report['presentation']['modules'] = [
            'capabilities' => $capabilities,
            'coverage' => ['total' => $total, 'recognised' => $recognised, 'unclassified' => $total - $recognised],
            'groups' => $groups,
        ];
        $report['presentation']['system'] = $this->systemPresentation($report, $recognised, $total - $recognised);

        return $report;
    }

    /** @return array<string,mixed> */
    private function systemPresentation(array $report, int $recognised, int $unclassified): array
    {
        $root = (string) ($report['root'] ?? '');
        $modules = [];
        foreach (($report['modules'] ?? []) as $module) {
            $name = strtolower((string) ($module['name'] ?? ''));
            if (($module['source']['confidence'] ?? '') === 'syntactic' && isset(self::MODULES[$name])) {
                $modules[$name] = $module;
            }
        }

        $listeners = [];
        foreach (($report['listeners'] ?? []) as $listener) {
            $interpreted = $this->listenerPresentation($listener);
            if ($interpreted !== null) {
                $listeners[] = $interpreted;
            }
        }
        usort($listeners, static fn(array $a, array $b): int => strnatcasecmp($a['description'], $b['description']));

        $routes = $this->routePresentation($report, $root);
        $findings = [];
        foreach ($listeners as $listener) {
            $findings[] = [
                'title' => 'SIP signalling',
                'explanation' => $listener['description'],
                'confidence' => $listener['confidence'],
                'evidence' => [['label' => 'Listener', 'source' => $listener['source']]],
                'caveat' => '',
            ];
        }

        foreach ([
            'tm' => ['Stateful transaction handling is available through tm.', 'Transaction handling'],
            'rr' => ['Record-Route / loose routing support is loaded.', 'Record-Route support'],
            'nathelper' => ['NAT handling support is loaded.', 'NAT traversal support'],
            'dispatcher' => ['Dispatcher and load-balancing support is loaded.', 'Dispatcher and load-balancing support'],
            'registrar' => ['SIP registration support is loaded.', 'SIP registration support'],
            'usrloc' => ['User location support is loaded.', 'User location support'],
            'auth' => ['SIP authentication support is loaded.', 'SIP authentication support'],
            'auth_db' => ['Database-backed SIP authentication support is loaded.', 'Database-backed authentication'],
            'dialog' => ['Dialog tracking support is loaded.', 'Dialog tracking'],
            'tls' => ['TLS transport support is loaded.', 'TLS transport support'],
            'websocket' => ['WebSocket transport support is loaded.', 'WebSocket transport support'],
        ] as $moduleName => [$explanation, $title]) {
            if (!isset($modules[$moduleName])) {
                continue;
            }
            $findings[] = [
                'title' => $title,
                'explanation' => $explanation,
                'confidence' => 'syntactic',
                'evidence' => [['label' => 'Module ' . $moduleName . ' loaded', 'source' => $modules[$moduleName]['source']]],
                'caveat' => 'A loaded module makes support available; it does not prove active use.',
            ];
        }

        foreach (['rtpengine' => 'RTPengine media handling', 'rtpproxy' => 'RTPProxy media handling'] as $moduleName => $title) {
            if (!isset($modules[$moduleName])) {
                continue;
            }
            $matchingRoutes = array_values(array_filter($routes['all'], static fn(array $route): bool => $route['confidence'] === 'syntactic'
                && $route['name'] !== null && stripos($route['name'], $moduleName) !== false));
            $evidence = [['label' => 'Module ' . $moduleName . ' loaded', 'source' => $modules[$moduleName]['source']]];
            foreach ($matchingRoutes as $route) {
                $evidence[] = ['label' => $route['label'], 'source' => $route['source']];
            }
            $findings[] = [
                'title' => $title,
                'explanation' => $matchingRoutes === []
                    ? $title . ' support is loaded.'
                    : $title . ' appears to be configured.',
                'confidence' => 'syntactic',
                'evidence' => $evidence,
                'caveat' => $matchingRoutes === []
                    ? 'A loaded module makes support available; it does not prove active use.'
                    : 'This does not establish that media relay traffic is occurring.',
            ];
        }

        $confirmedCustomRoutes = array_values(array_filter($routes['custom'], static fn(array $route): bool => $route['confidence'] === 'syntactic'));
        if ($confirmedCustomRoutes !== []) {
            $count = count($confirmedCustomRoutes);
            $findings[] = [
                'title' => 'Custom routing logic detected',
                'explanation' => $count . ' named route' . ($count === 1 ? ' was' : 's were') . ' discovered in custom configuration components.',
                'confidence' => 'syntactic',
                'evidence' => array_map(static fn(array $route): array => ['label' => $route['label'], 'source' => $route['source']], $confirmedCustomRoutes),
                'caveat' => 'Custom route internals are not interpreted.',
            ];
        }
        usort($findings, static fn(array $a, array $b): int => strnatcasecmp($a['title'] . $a['explanation'], $b['title'] . $b['explanation']));

        $composition = $this->compositionPresentation($report, $root);
        $gaps = [];
        if ($unclassified > 0) {
            $gaps[] = $unclassified . ' loaded module' . ($unclassified === 1 ? ' is' : 's are') . ' unclassified.';
        }
        if ($confirmedCustomRoutes !== []) {
            $gaps[] = count($confirmedCustomRoutes) . ' custom named route' . (count($confirmedCustomRoutes) === 1 ? ' remains' : 's remain') . ' only partially understood.';
        }
        foreach ([
            'registrar/location' => ['registrar', 'usrloc'],
            'authentication' => ['auth', 'auth_db'],
            'dispatcher/load balancing' => ['dispatcher'],
            'database driver' => ['db_mysql', 'db_postgres', 'db_sqlite'],
        ] as $role => $names) {
            if (array_intersect($names, array_keys($modules)) === []) {
                $gaps[] = 'No recognised ' . $role . ' modules were found in the scanned configuration.';
            }
        }

        return [
            'findings' => $findings,
            'listeners' => $listeners,
            'routes' => $routes,
            'composition' => $composition,
            'confidence' => [
                'level' => (string) ($report['completeness']['confidence'] ?? 'partial'),
                'scope' => (string) ($report['completeness']['scope'] ?? 'unknown'),
                'reasons' => $report['completeness']['reasons'] ?? [],
                'gaps' => $gaps,
                'recognised_modules' => $recognised,
                'unclassified_modules' => $unclassified,
                'unknown_statements' => count($report['unknown'] ?? []),
                'warnings' => count($report['warnings'] ?? []),
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    private function listenerPresentation(array $listener): ?array
    {
        if (($listener['value_classification'] ?? '') !== 'safe-network-listener') {
            return null;
        }
        $raw = trim((string) ($listener['raw'] ?? ''), " \t\r\n\"'");
        if (!preg_match('/^(?:(udp|tcp|tls|sctp|ws|wss):)?(\[[0-9a-f:]+\]|[A-Za-z0-9_.-]+)(?::([0-9]{1,5}))?/i', $raw, $match)) {
            return null;
        }
        $transport = strtolower($match[1] ?: 'udp');
        $address = $match[2];
        $endpoint = $address . (isset($match[3]) ? ':' . $match[3] : '');
        $label = ['udp' => 'SIP over UDP', 'tcp' => 'SIP over TCP', 'tls' => 'SIP over TLS', 'sctp' => 'SIP over SCTP', 'ws' => 'SIP over WebSocket', 'wss' => 'SIP over secure WebSocket'][$transport] ?? strtoupper($transport);
        $nonLoopback = !in_array(trim($address, '[]'), ['127.0.0.1', '::1', 'localhost'], true);
        return ['label' => $label, 'description' => $label . ' listening on ' . $endpoint . ($nonLoopback ? ' (non-loopback address).' : '.'), 'source' => $listener['source'], 'confidence' => (string) ($listener['source']['confidence'] ?? 'unknown')];
    }

    /** @return array<string,mixed> */
    private function routePresentation(array $report, string $root): array
    {
        $types = ['request_route' => 'Main request route', 'route' => 'Named routes', 'onreply_route' => 'Reply routes', 'failure_route' => 'Failure routes', 'branch_route' => 'Branch routes', 'event_route' => 'Event routes', 'onsend_route' => 'Send routes'];
        $groups = array_fill_keys(array_keys($types), []);
        $all = [];
        foreach (($report['routes'] ?? []) as $route) {
            $type = (string) ($route['type'] ?? '');
            if (!isset($types[$type])) {
                continue;
            }
            $name = isset($route['name']) ? (string) $route['name'] : null;
            $entry = ['type' => $type, 'type_label' => $types[$type], 'name' => $name, 'label' => $type . ($name === null ? '' : '[' . $name . ']'), 'source' => $route['source'], 'confidence' => (string) ($route['source']['confidence'] ?? 'unknown'), 'component' => (string) ($route['source']['file'] ?? '')];
            $groups[$type][] = $entry;
            $all[] = $entry;
        }
        foreach ($groups as &$group) { usort($group, static fn(array $a, array $b): int => strnatcasecmp(($a['name'] ?? '') . $a['component'], ($b['name'] ?? '') . $b['component'])); }
        unset($group);
        $custom = array_values(array_filter($all, static fn(array $route): bool => $route['type'] === 'route' && $route['name'] !== null));
        $customByComponent = [];
        foreach ($custom as $route) {
            if ($route['component'] === $root) { continue; }
            $customByComponent[$route['component']][] = $route;
        }
        ksort($customByComponent, SORT_NATURAL | SORT_FLAG_CASE);
        return ['groups' => array_values(array_filter(array_map(static fn(string $type, array $items): array => ['key' => $type, 'label' => $types[$type], 'routes' => $items], array_keys($groups), $groups), static fn(array $group): bool => $group['routes'] !== [])), 'all' => $all, 'custom' => $custom, 'custom_by_component' => $customByComponent];
    }

    /** @return list<array<string,mixed>> */
    private function compositionPresentation(array $report, string $root): array
    {
        $includes = [];
        foreach (($report['includes'] ?? []) as $include) {
            if (($include['exists'] ?? false) === true) { $includes[(string) ($include['resolved'] ?? '')] = $include; }
        }
        $components = [];
        foreach (($report['files'] ?? []) as $file) {
            $components[] = ['path' => $file, 'kind' => $file === $root ? 'Main configuration' : 'Included configuration', 'included_from' => $includes[$file]['source'] ?? null, 'modules' => count(array_filter($report['modules'] ?? [], static fn(array $item): bool => ($item['source']['file'] ?? '') === $file)), 'listeners' => count(array_filter($report['listeners'] ?? [], static fn(array $item): bool => ($item['source']['file'] ?? '') === $file)), 'routes' => count(array_filter($report['routes'] ?? [], static fn(array $item): bool => ($item['source']['file'] ?? '') === $file)), 'defines' => count(array_filter($report['defines'] ?? [], static fn(array $item): bool => ($item['source']['file'] ?? '') === $file))];
        }
        usort($components, static fn(array $a, array $b): int => ($a['kind'] === 'Main configuration' ? -1 : 1) <=> ($b['kind'] === 'Main configuration' ? -1 : 1) ?: strnatcasecmp($a['path'], $b['path']));
        return $components;
    }

    /** @return list<string> */
    public static function recognisedModuleNames(): array
    {
        $names = array_keys(self::MODULES);
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        return $names;
    }
}
