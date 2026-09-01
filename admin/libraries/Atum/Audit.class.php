<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

final class AtumAudit
{
    public function __construct(private AtumState $state)
    {
    }

    public function log(
        string $action,
        string $outcome = 'success',
        ?string $objectType = null,
        ?string $objectId = null,
        ?string $detail = null,
        ?array $actor = null
    ): void {
        $actor ??= $_SESSION['atum_user'] ?? null;
        $statement = $this->state->db()->prepare(
            'INSERT INTO audit_log (created_at,user_id,username,remote_addr,action,object_type,object_id,outcome,detail) '
            . 'VALUES (:created_at,:user_id,:username,:remote_addr,:action,:object_type,:object_id,:outcome,:detail)'
        );
        $statement->execute([
            ':created_at' => gmdate(DATE_ATOM),
            ':user_id' => is_array($actor) ? ($actor['id'] ?? null) : null,
            ':username' => is_array($actor) ? ($actor['username'] ?? null) : null,
            ':remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':action' => $action,
            ':object_type' => $objectType,
            ':object_id' => $objectId,
            ':outcome' => $outcome,
            ':detail' => $detail,
        ]);
    }

    public function recent(int $limit = 100): array
    {
        $limit = max(1, min($limit, 500));
        return $this->state->db()->query(
            'SELECT * FROM audit_log ORDER BY id DESC LIMIT ' . $limit
        )->fetchAll();
    }
}
