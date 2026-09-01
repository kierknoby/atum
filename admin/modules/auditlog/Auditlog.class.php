<?php
// SPDX-License-Identifier: GPL-3.0-or-later
class Auditlog extends AtumModule
{
    public function showPage(): string
    {
        $this->Atum->Auth->requirePermission('admin');
        return $this->Atum->View->load(__DIR__ . '/views/default.php', ['records' => $this->Atum->Audit->recent(200)]);
    }
}
