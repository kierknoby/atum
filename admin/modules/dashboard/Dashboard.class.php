<?php
// SPDX-License-Identifier: GPL-3.0-or-later

class Dashboard extends AtumModule
{
    public function showPage(): string
    {
        $report = null;
        $error = null;

        try {
            $report = Atum::Discovery()->scan();
        } catch (Throwable $e) {
            $error = 'Kamailio discovery is unavailable. See the Atum audit log for details.';
            try {
                $this->Atum->Audit->log('dashboard.discovery.error', 'failure', 'kamailio', null, $e->getMessage());
            } catch (Throwable) {
                // Keep the browser error generic even if audit storage is unavailable.
            }
        }

        return $this->Atum->View->load(__DIR__ . '/views/default.php', [
            'report' => $report,
            'error' => $error,
            'modules' => $this->Atum->Modules->getActiveModules(),
        ]);
    }
}
