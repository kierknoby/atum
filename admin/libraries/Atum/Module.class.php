<?php
// SPDX-License-Identifier: GPL-3.0-or-later

abstract class AtumModule
{
    protected Atum $Atum;

    public function __construct(?Atum $atum = null)
    {
        if ($atum === null) {
            throw new RuntimeException('Not given an Atum object');
        }

        $this->Atum = $atum;
    }

    public function ajaxRequest(string $command, array &$settings = []): bool
    {
        return false;
    }

    public function ajaxHandler(): mixed
    {
        return false;
    }
}
