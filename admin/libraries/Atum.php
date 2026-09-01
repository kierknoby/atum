<?php
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Atum application container.
 *
 * Intentionally follows the service/module access pattern familiar to FreePBX
 * module developers while remaining independent of FreePBX itself.
 */
final class Atum
{
    private static ?self $instance = null;

    public AtumConfig $Config;
    public AtumModules $Modules;
    public AtumView $View;
    public AtumAjax $Ajax;
    public AtumSystem $System;
    public AtumState $State;
    public AtumAudit $Audit;
    public AtumAuth $Auth;
    public AtumLifecycle $Lifecycle;

    private array $moduleObjects = [];

    private function __construct()
    {
        $this->Config = new AtumConfig();
        $this->View = new AtumView();
        $this->State = new AtumState($this->Config);
        $this->Audit = new AtumAudit($this->State);
        $this->Auth = new AtumAuth($this->State, $this->Audit, $this->Config);
        $this->Lifecycle = new AtumLifecycle($this->State, $this->Audit);
        $this->Modules = new AtumModules($this);
        $this->Ajax = new AtumAjax($this);
        $this->System = new AtumSystem($this->Config);
    }

    public static function create(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function __callStatic(string $name, array $arguments): object
    {
        return self::create()->getModule(strtolower($name));
    }

    public function __get(string $name): object
    {
        return $this->getModule(strtolower($name));
    }

    public function getModule(string $rawname): object
    {
        if (!isset($this->moduleObjects[$rawname])) {
            $this->moduleObjects[$rawname] = $this->Modules->load($rawname);
        }

        return $this->moduleObjects[$rawname];
    }
}
