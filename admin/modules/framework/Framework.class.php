<?php
// SPDX-License-Identifier: GPL-3.0-or-later

class Framework extends AtumModule
{
    public function version(): string
    {
        $info = $this->Atum->Modules->getInfo('framework');
        return (string) ($info['version'] ?? 'unknown');
    }
}
