<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

/** Preserve per-collector replication outcomes rather than claiming distributed atomicity. */
final readonly class InstallerCollectorSynchronization
{
    /** Replication remains behind the native collector boundary. */
    public function __construct(private NativeInstallerFunctions $native) {}

    /** @return array{success: list<int|string>, failed: list<int|string>, skipped: list<int|string>, timeout: list<int|string>, total: int} */
    public function run(): array
    {
        $pinterval = $this->native->text($this->native->call('read_config_option', 'poller_interval'));
        if (preg_match('/\A[0-9]{1,6}\z/D', $pinterval) !== 1) {
            throw new \RuntimeException('Invalid collector synchronization interval.');
        }
        $gap_time = (int) $pinterval * 2;

        /* counter arrays */
        $failed    = array();
        $success   = array();
        $skipped   = array();
        $timeout   = array();

        $pollers = $this->native->call('db_fetch_assoc', 'SELECT id, status, UNIX_TIMESTAMP() - UNIX_TIMESTAMP(last_update) AS gap
		FROM poller
		WHERE id > 1
		AND disabled = ""');

        if (!is_array($pollers)) {
            throw new \RuntimeException('Collector synchronization inventory is unavailable.');
        }
        $this->native->call('log_install_always', 'sync', 'Found ' . count($pollers) . ' poller(s) to sync');

        if ($pollers !== []) {
            foreach ($pollers as $poller) {
                if (!is_array($poller) || !isset($poller['id'], $poller['status'], $poller['gap']) || (!is_int($poller['id']) && !is_string($poller['id']))) {
                    throw new \RuntimeException('Invalid collector synchronization record.');
                }
                $this->native->call('log_install_debug', 'sync', 'Poller ' . $poller['id'] . ' has a status of ' . $this->native->text($poller['status']) . ' with gap ' . $this->native->text($poller['gap']));

                $status = $this->native->text($poller['status']);
                $gap = $this->native->text($poller['gap']);
                if (preg_match('/\A[0-9]+\z/D', $status) !== 1 || preg_match('/\A-?[0-9]+\z/D', $gap) !== 1) {
                    throw new \RuntimeException('Invalid collector synchronization state.');
                }
                // Native status constants: NEW=0, DOWN=3, HEARTBEAT=6, RECOVERING=5, DISABLED=4.
                if (in_array((int) $status, [0, 3, 6, 5, 4], true)) {
                    $skipped[] = $poller['id'];
                } elseif ((int) $gap < $gap_time) {
                    $this->native->call('log_install_medium', 'sync', 'Replicating to Poller ' . $poller['id']);
                    if ((bool) $this->native->call('replicate_out', $poller['id'])) {
                        $this->native->call('log_install_debug', 'sync', 'Completed replication to Poller ' . $poller['id']);

                        $confirmed = $this->native->call(
                            'db_execute_prepared',
                            'UPDATE poller
						SET last_sync = NOW()
						WHERE id = ?',
                            [$poller['id']]
                        );
                        if ($confirmed === false) {
                            $failed[] = $poller['id'];
                        } else {
                            $success[] = $poller['id'];
                        }
                    } else {
                        $this->native->call('log_install_debug', 'sync', 'Failed replication to Poller ' . $poller['id']);
                        $failed[] = $poller['id'];
                    }
                } else {
                    $timeout[] = $poller['id'];
                }
            }
        }

        $this->native->call('log_install_debug', 'sync', 'Success: ' . count($success) . ', Failed: ' . count($failed) . ', Skipped: ' . count($skipped) . ', Total: ' . count($pollers));

        return array(
            'success' => $success,
            'failed'  => $failed,
            'skipped' => $skipped,
            'timeout' => $timeout,
            'total'   => count($pollers)
        );
    }
}
