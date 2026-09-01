<?php
// SPDX-License-Identifier: GPL-3.0-or-later

interface AtumAuditDestination
{
    /** @param array<string,mixed> $event */
    public function write(array $event): void;
}

final class AtumSqliteAuditDestination implements AtumAuditDestination
{
    public function __construct(private AtumState $state) {}

    public function write(array $event): void
    {
        $statement = $this->state->db()->prepare('INSERT INTO audit_log (created_at,user_id,username,remote_addr,action,object_type,object_id,outcome,detail) VALUES (:created_at,:user_id,:username,:remote_addr,:action,:object_type,:object_id,:outcome,:detail)');
        $statement->execute($event);
    }
}
