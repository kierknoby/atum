<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

class Userman extends AtumModule
{
    public function showPage(): string
    {
        $this->Atum->Auth->requirePermission('admin');
        return $this->Atum->View->load(__DIR__ . '/views/default.php', [
            'users' => $this->Atum->Auth->users(),
            'csrf' => $this->Atum->Auth->csrfToken(),
        ]);
    }

    public function ajaxRequest(string $command, array &$settings = []): bool
    {
        if (!in_array($command, ['create', 'password', 'enable', 'disable', 'delete'], true)) {
            return false;
        }
        $settings['permission'] = 'admin';
        $settings['method'] = 'POST';
        return true;
    }

    public function ajaxHandler(): mixed
    {
        $command = (string) ($_REQUEST['command'] ?? '');
        return match ($command) {
            'create' => $this->create(),
            'password' => $this->password(),
            'enable' => $this->enabled(true),
            'disable' => $this->enabled(false),
            'delete' => $this->delete(),
            default => false,
        };
    }

    private function create(): array
    {
        $id = $this->Atum->Auth->createUser(
            (string) ($_POST['username'] ?? ''),
            (string) ($_POST['password'] ?? ''),
            (string) ($_POST['role'] ?? 'viewer')
        );
        return ['ok' => true, 'id' => $id];
    }

    private function password(): array
    {
        $this->Atum->Auth->changePassword((int) ($_POST['id'] ?? 0), (string) ($_POST['password'] ?? ''));
        return ['ok' => true];
    }

    private function enabled(bool $enabled): array
    {
        $this->Atum->Auth->setEnabled((int) ($_POST['id'] ?? 0), $enabled);
        return ['ok' => true];
    }

    private function delete(): array
    {
        $this->Atum->Auth->deleteUser((int) ($_POST['id'] ?? 0));
        return ['ok' => true];
    }
}
