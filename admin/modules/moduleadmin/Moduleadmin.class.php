<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

class Moduleadmin extends AtumModule
{
    public function showPage(): string
    {
        $this->Atum->Auth->requirePermission('admin');
        return $this->Atum->View->load(__DIR__ . '/views/default.php', [
            'modules' => $this->Atum->Modules->getInfo(),
            'csrf' => $this->Atum->Auth->csrfToken(),
        ]);
    }

    public function ajaxRequest(string $command, array &$settings = []): bool
    {
        if (!in_array($command, ['install', 'uninstall', 'enable', 'disable'], true)) {
            return false;
        }
        $settings['permission'] = 'admin';
        $settings['method'] = 'POST';
        return true;
    }

    public function ajaxHandler(): mixed
    {
        $command = (string) ($_REQUEST['command'] ?? '');
        $rawname = strtolower((string) ($_POST['rawname'] ?? ''));
        if (!preg_match('/^[a-z0-9_-]+$/', $rawname)) {
            throw new InvalidArgumentException('Invalid module rawname.');
        }
        match ($command) {
            'install' => $this->Atum->Modules->install($rawname),
            'uninstall' => $this->Atum->Modules->uninstall($rawname),
            'enable' => $this->Atum->Modules->setEnabled($rawname, true),
            'disable' => $this->Atum->Modules->setEnabled($rawname, false),
            default => throw new InvalidArgumentException('Invalid module operation.'),
        };
        $info = $this->Atum->Modules->getInfo($rawname);
        return [
            'ok' => true,
            'rawname' => $rawname,
            'installed' => (bool) ($info['installed'] ?? false),
            'enabled' => (bool) ($info['enabled'] ?? false),
        ];
    }
}
