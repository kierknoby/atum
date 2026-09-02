<?php
// SPDX-License-Identifier: GPL-3.0-or-later

class Callhandling extends AtumModule
{
    public function showPage(): string
    {
        try {
            $model = Atum::Discovery()->systemModel();
            $error = null;
        } catch (Throwable) {
            $model = null;
            $error = 'Call handling interpretation is unavailable. See the Atum audit log for details.';
            try {
                $this->Atum->Audit->log('callhandling.page.error', 'failure', 'kamailio', null, 'event=system_model_failure');
            } catch (Throwable) {
                // Keep the browser error generic even if audit storage is unavailable.
            }
        }

        return $this->Atum->View->load(__DIR__ . '/views/default.php', ['model' => $model, 'error' => $error]);
    }
}
