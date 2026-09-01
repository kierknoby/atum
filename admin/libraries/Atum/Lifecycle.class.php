<?php
// SPDX-License-Identifier: GPL-3.0-or-later
declare(strict_types=1);

interface AtumChangeOperation
{
    /** @return array<string,mixed> Secret-free description persisted before apply. */
    public function describe(): array;
    public function validate(): void;
    public function apply(): void;
    public function verify(): void;
    public function rollback(): void;
}

final class AtumLifecycle
{
    public function __construct(private AtumState $state, private AtumAudit $audit) {}

    /** @param list<AtumChangeOperation> $operations */
    public function execute(string $owner, array $operations): void
    {
        if (!preg_match('/^[a-z0-9_-]+$/', $owner) || $operations === []) { throw new InvalidArgumentException('A lifecycle owner and declared operations are required.'); }
        $pending = $this->state->db()->query("SELECT COUNT(*) FROM lifecycle_journal WHERE status IN ('prepared','applying')")->fetchColumn();
        if ((int) $pending > 0) { throw new RuntimeException('An interrupted lifecycle plan must be recovered before another plan can run.'); }
        foreach ($operations as $operation) { if (!$operation instanceof AtumChangeOperation) { throw new InvalidArgumentException('Undeclared lifecycle operation.'); } $operation->validate(); }
        $descriptions = array_map(static fn(AtumChangeOperation $operation): array => $operation->describe(), $operations);
        $encoded = json_encode($descriptions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (str_contains($encoded, '://')) { throw new RuntimeException('Lifecycle descriptions must not contain credentials or URLs.'); }
        $statement = $this->state->db()->prepare('INSERT INTO lifecycle_journal(owner,status,operations,created_at,updated_at) VALUES (:owner,\'prepared\',:operations,:created,:updated)');
        $now = gmdate(DATE_ATOM); $statement->execute([':owner' => $owner, ':operations' => $encoded, ':created' => $now, ':updated' => $now]); $id = (int) $this->state->db()->lastInsertId();
        $applied = [];
        try {
            $this->status($id, 'applying');
            foreach ($operations as $operation) { $operation->apply(); $applied[] = $operation; }
            foreach ($operations as $operation) { $operation->verify(); }
            $this->status($id, 'committed'); $this->audit->log('lifecycle.commit', 'success', 'module', $owner, 'event=lifecycle_committed');
        } catch (Throwable $error) {
            foreach (array_reverse($applied) as $operation) { try { $operation->rollback(); } catch (Throwable) {} }
            $this->status($id, 'rolled_back'); $this->audit->log('lifecycle.rollback', 'failure', 'module', $owner, 'event=lifecycle_rolled_back'); throw $error;
        }
    }

    /** @param list<AtumChangeOperation> $operations Reconstructed from the persisted, secret-free descriptions. */
    public function recover(int $id, array $operations): void
    {
        $statement = $this->state->db()->prepare("SELECT operations FROM lifecycle_journal WHERE id=:id AND status IN ('prepared','applying')");
        $statement->execute([':id' => $id]); $stored = $statement->fetchColumn();
        if (!is_string($stored)) { throw new RuntimeException('Lifecycle plan is not recoverable.'); }
        $described = json_encode(array_map(static fn(AtumChangeOperation $operation): array => $operation->describe(), $operations), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (!hash_equals($stored, $described)) { throw new RuntimeException('Lifecycle recovery operations do not match the journal.'); }
        foreach (array_reverse($operations) as $operation) { $operation->rollback(); }
        $this->status($id, 'rolled_back'); $this->audit->log('lifecycle.recover', 'success', 'lifecycle', (string) $id, 'event=lifecycle_recovered');
    }

    private function status(int $id, string $status): void { $this->state->db()->prepare('UPDATE lifecycle_journal SET status=:status,updated_at=:updated WHERE id=:id')->execute([':status' => $status, ':updated' => gmdate(DATE_ATOM), ':id' => $id]); }
}
