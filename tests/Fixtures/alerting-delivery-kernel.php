<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
// Stands in for config/bootstrap.php under a copy of
// bin/legacy-alerting-deliver.php. Its container holds one service, the
// delivery use case, which records its arguments and returns a fixed report
// or fails as ALERTING_MODE says. Each kernel call is recorded.

use Kadupul\Alerting\Application\DeliveryReport;

return new class {
    private function record(string $event): void
    {
        file_put_contents(getenv('ALERTING_FIXTURE') . '/events', $event . "\n", FILE_APPEND);
    }

    public function boot(): void
    {
        $this->record('boot');
    }

    public function shutdown(): void
    {
        $this->record('shutdown');
    }

    public function getContainer(): object
    {
        $record = fn(string $event) => $this->record($event);

        return new class ($record) {
            public function __construct(private \Closure $record) {}

            public function get(string $id): object
            {
                ($this->record)('get ' . $id);

                return new class ($this->record) {
                    public function __construct(private \Closure $record) {}

                    public function __invoke(int $batchSize, float $budgetSeconds): DeliveryReport
                    {
                        ($this->record)('deliver ' . json_encode([$batchSize, $budgetSeconds], JSON_PRESERVE_ZERO_FRACTION));
                        if (getenv('ALERTING_MODE') === 'fail') {
                            throw new \RuntimeException('mysql:host=db.example;password=hunter2');
                        }

                        return new DeliveryReport(sent: 2, retrying: 1, failed: 0, lost: 0, suppressed: 3);
                    }
                };
            }
        };
    }
};
