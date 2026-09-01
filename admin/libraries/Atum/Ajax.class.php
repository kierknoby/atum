<?php
// SPDX-License-Identifier: GPL-3.0-or-later

final class AtumAjax
{
    private Atum $Atum;

    public function __construct(Atum $atum)
    {
        $this->Atum = $atum;
    }

    public function doRequest(string $module, string $command): never
    {
        if (!preg_match('/^[a-z0-9_-]+$/', $module) || !preg_match('/^[a-z0-9_-]+$/', $command)) {
            $this->respond(['error' => 'Invalid module or command'], 400);
        }

        try {
            $instance = $this->Atum->getModule($module);
            $settings = [];

            if (!$instance->ajaxRequest($command, $settings)) {
                $this->respond(['error' => 'Request declined'], 403);
            }

            $permission = (string) ($settings['permission'] ?? 'view');
            if (!$this->Atum->Auth->hasPermission($permission)) {
                $this->respond(['error' => 'Permission denied'], 403);
            }
            $expectedMethod = strtoupper((string) ($settings['method'] ?? 'GET'));
            $actualMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            if ($expectedMethod !== $actualMethod) {
                $this->respond(['error' => 'Method not allowed'], 405);
            }

            $_REQUEST['command'] = $command;
            $result = $instance->ajaxHandler();
            if ($result === false) {
                $this->respond(['error' => 'Module did not handle request'], 501);
            }

            $this->respond($result, 200);
        } catch (Throwable $e) {
            try {
                $this->Atum->Audit->log('ajax.error', 'failure', 'module', $module, 'event=ajax_handler_failure command=' . $command);
            } catch (Throwable) {
                // Do not let audit failure expose an internal exception to the client.
            }
            $this->respond(['error' => 'Request failed. See the Atum audit log for details.'], 500);
        }
    }

    private function respond(mixed $body, int $status): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
