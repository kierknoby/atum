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

        return $report;
    }

    /** @return list<string> */
    public static function recognisedModuleNames(): array
    {
        $names = array_keys(self::MODULES);
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        return $names;
    }
}
