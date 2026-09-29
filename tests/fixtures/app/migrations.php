<?php
/**
 * The legacy version ledger, for MigrationsAutoKeyTest.
 *
 * `Application::upgrade()` reads this file and runs each class whose version is
 * not yet recorded. One entry is enough to tell "upgrade() was entered and did
 * its work" from "upgrade() returned early", which is the distinction the
 * migrations.auto key has to get right.
 */
return [
    '0.001' => 'AutoKeyLegacyMigration',
];
