<?php
// SPDX-License-Identifier: GPL-3.0-or-later

require_once __DIR__ . '/../../libraries/Atum/Kamailio/Scanner.class.php';
require_once __DIR__ . '/../../libraries/Atum/Kamailio/Semantics.class.php';

class Discovery extends AtumModule
{
    private const VIEWS = [
        'overview' => 'Overview',
        'call-flow' => 'Call Flow',
        'connectivity' => 'Connectivity',
        'routing' => 'Routing',
        'media' => 'Media',
        'access' => 'Access & Registration',
        'evidence' => 'Configuration & Evidence',
    ];

    public function scan(?string $config = null): array
    {
        $config ??= (string) $this->Atum->Config->get('KAMAILIO_CONFIG');
        $report = (new AtumKamailioScanner())->scan($config);
        return (new AtumKamailioSemantics())->present($report);
    }

    public function showPage(?string $view = null): string
    {
        $view = isset(self::VIEWS[$view ?? '']) ? $view : 'overview';
        try {
            $report = $this->scan();
            $error = null;
        } catch (Throwable $e) {
            $report = null;
            $error = 'Kamailio discovery failed. See the Atum audit log for details.';
            try {
                $this->Atum->Audit->log('discovery.page.error', 'failure', 'kamailio', null, 'event=discovery_failure');
            } catch (Throwable) {
                // Keep the browser error generic even if audit storage is unavailable.
            }
        }

        return $this->Atum->View->load(__DIR__ . '/views/default.php', [
            'report' => $report,
            'error' => $error,
            'configPath' => (string) $this->Atum->Config->get('KAMAILIO_CONFIG'),
            'view' => $view,
            'views' => self::VIEWS,
        ]);
    }

    public function ajaxRequest(string $command, array &$settings = []): bool
    {
        if ($command !== 'scan') {
            return false;
        }

        $settings['read_only'] = true;
        $settings['permission'] = 'discovery.view';
        $settings['method'] = 'GET';
        return true;
    }

    public function ajaxHandler(): mixed
    {
        return match ((string) ($_REQUEST['command'] ?? '')) {
            'scan' => $this->scan(),
            default => false,
        };
    }
}
