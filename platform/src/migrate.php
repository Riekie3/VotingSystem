<?php
// Database upgrades. Each step runs once, in order, the first time a request
// arrives after new files are uploaded. Fresh installs start at the latest version.

declare(strict_types=1);

const DB_VERSION = 2;

function migrations(): array
{
    return [
        // v2: chart style per poll (bars / pie / both)
        2 => function (): void {
            if (!one("SHOW COLUMNS FROM polls LIKE 'chart'")) {
                db()->exec("ALTER TABLE polls ADD COLUMN chart ENUM('bars','pie','both') NOT NULL DEFAULT 'bars' AFTER top_n");
            }
        },
    ];
}

function migrate(): void
{
    $current = (int) (setting('db_version') ?: 1);
    if ($current >= DB_VERSION) return;
    foreach (migrations() as $v => $step) {
        if ($v <= $current) continue;
        $step();
        setting_set('db_version', (string) $v);
        audit('migrate', "database upgraded to v$v");
    }
}
