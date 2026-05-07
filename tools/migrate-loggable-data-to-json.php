#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Migration script: convert Loggable `data` column from PHP serialize() to JSON.
 *
 * This script is needed when upgrading from doctrine/dbal <4 to >=4, because the
 * "array" Doctrine type (which used PHP serialize/unserialize) was removed in DBAL 4.
 * The AbstractLogEntry::$data field now uses the "json" type instead.
 *
 * Steps performed by this script:
 *   1. Rename the existing `data` column to `data_serialized` (preserves all original data).
 *   2. Add a new `data` column of a JSON-compatible text type.
 *   3. Read every row with a non-NULL `data_serialized` value, unserialize it, JSON-encode
 *      it, and write the result into the new `data` column.
 *
 * After verifying the migration you can drop the `data_serialized` column manually:
 *   ALTER TABLE <table> DROP COLUMN data_serialized;
 *
 * Usage:
 *   php tools/migrate-loggable-data-to-json.php --dsn="mysql://user:pass@host/db" [--table="ext_log_entries"] [--batch-size=500] [--drop-legacy]
 *
 * Options:
 *   --dsn          DBAL-compatible DSN string (required).
 *   --table        Name of the log entry table (default: ext_log_entries).
 *   --batch-size   Number of rows to process per database round-trip (default: 500).
 *   --drop-legacy  Drop the data_serialized column after a successful migration.
 */

// ---------------------------------------------------------------------------
// Autoloader – try to find the Composer autoloader from common locations.
// ---------------------------------------------------------------------------
$autoloadCandidates = [
    __DIR__.'/../vendor/autoload.php',
    __DIR__.'/../../vendor/autoload.php',
    __DIR__.'/../../../vendor/autoload.php',
];
$autoloaderFound = false;
foreach ($autoloadCandidates as $candidate) {
    if (file_exists($candidate)) {
        require_once $candidate;
        $autoloaderFound = true;
        break;
    }
}
if (!$autoloaderFound) {
    fwrite(STDERR, "Could not find the Composer autoloader. Run 'composer install' first.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Parse CLI arguments.
// ---------------------------------------------------------------------------
$options = getopt('', ['dsn:', 'table::', 'batch-size::', 'drop-legacy']);

$dsn = $options['dsn'] ?? null;
if (null === $dsn) {
    fwrite(STDERR, "Error: --dsn is required.\n\nUsage:\n  php tools/migrate-loggable-data-to-json.php --dsn=\"mysql://user:pass@host/db\" [--table=ext_log_entries] [--batch-size=500] [--drop-legacy]\n");
    exit(1);
}

$table = $options['table'] ?? 'ext_log_entries';
$batchSize = (int) ($options['batch-size'] ?? 500);
$dropLegacy = array_key_exists('drop-legacy', $options);

// ---------------------------------------------------------------------------
// Build a DBAL connection from the DSN.
// ---------------------------------------------------------------------------
use Doctrine\DBAL\DriverManager;

$connectionParams = ['url' => $dsn];

try {
    $connection = DriverManager::getConnection($connectionParams);
    $connection->connect();
} catch (\Throwable $e) {
    fwrite(STDERR, sprintf("Could not connect to the database: %s\n", $e->getMessage()));
    exit(1);
}

$platform = $connection->getDatabasePlatform();
$schemaManager = method_exists($connection, 'createSchemaManager')
    ? $connection->createSchemaManager()
    : $connection->getSchemaManager(); // DBAL 3 compat

// ---------------------------------------------------------------------------
// Detect the existing columns on the table.
// ---------------------------------------------------------------------------
$columns = $schemaManager->listTableColumns($table);
$columnNames = array_keys($columns);

$hasData = in_array('data', $columnNames, true);
$hasDataSerialized = in_array('data_serialized', $columnNames, true);

if (!$hasData && !$hasDataSerialized) {
    fwrite(STDERR, sprintf("Neither 'data' nor 'data_serialized' column found in table '%s'. Nothing to migrate.\n", $table));
    exit(1);
}

// ---------------------------------------------------------------------------
// Step 1 – Rename data → data_serialized (skip if already done).
// ---------------------------------------------------------------------------
if ($hasData && !$hasDataSerialized) {
    echo "Step 1: Renaming column 'data' to 'data_serialized' in table '{$table}'...\n";

    $renameSql = $platform->getAlterTableSQL(
        (new \Doctrine\DBAL\Schema\TableDiff($table))
    );

    // Use raw SQL for maximum compatibility across drivers.
    $driver = $connection->getDriver();
    $driverName = strtolower((string) $driver->getDatabasePlatform($connection->getServerVersion())->getName());

    if (str_contains($driverName, 'sqlite')) {
        // SQLite does not support RENAME COLUMN before version 3.25.0; use recreate workaround.
        migrateViaSqliteRecreate($connection, $table);
        // After this helper the table already has data_serialized + data (JSON) columns.
        // Jump straight to step 3 (data already migrated inside helper).
        finalize($connection, $table, $dropLegacy);
        exit(0);
    }

    // MySQL / MariaDB / PostgreSQL
    $connection->executeStatement(sprintf('ALTER TABLE %s RENAME COLUMN data TO data_serialized', $table));
    echo "  Done.\n";
} elseif ($hasDataSerialized && !$hasData) {
    echo "Step 1: Column 'data_serialized' already exists; skipping rename.\n";
} else {
    // Both columns exist: the rename was done, but the data migration may not be complete.
    echo "Step 1: Both 'data' and 'data_serialized' columns exist; assuming rename was already performed.\n";
}

// ---------------------------------------------------------------------------
// Step 2 – Add the new JSON `data` column (skip if already present).
// ---------------------------------------------------------------------------
$columnsAfterRename = array_keys($schemaManager->listTableColumns($table));
if (!in_array('data', $columnsAfterRename, true)) {
    echo "Step 2: Adding new 'data' column (JSON/LONGTEXT) to table '{$table}'...\n";
    $connection->executeStatement(sprintf('ALTER TABLE %s ADD COLUMN data LONGTEXT DEFAULT NULL', $table));
    echo "  Done.\n";
} else {
    echo "Step 2: Column 'data' already exists; skipping ADD COLUMN.\n";
}

// ---------------------------------------------------------------------------
// Step 3 – Convert rows: unserialize → json_encode.
// ---------------------------------------------------------------------------
echo "Step 3: Converting serialized data to JSON...\n";
$converted = convertRows($connection, $table, $batchSize);
echo sprintf("  Converted %d row(s).\n", $converted);

// ---------------------------------------------------------------------------
// Finalize (optionally drop legacy column).
// ---------------------------------------------------------------------------
finalize($connection, $table, $dropLegacy);
exit(0);

// ---------------------------------------------------------------------------
// Helper functions
// ---------------------------------------------------------------------------

/**
 * Iterate over all rows with non-NULL data_serialized, convert them, and
 * write the JSON representation into the `data` column.
 *
 * @return int Number of rows converted.
 */
function convertRows(\Doctrine\DBAL\Connection $connection, string $table, int $batchSize): int
{
    $converted = 0;
    $offset = 0;

    while (true) {
        $rows = $connection->fetchAllAssociative(
            sprintf('SELECT id, data_serialized FROM %s WHERE data_serialized IS NOT NULL AND data IS NULL LIMIT %d OFFSET %d', $table, $batchSize, $offset)
        );

        if ([] === $rows) {
            break;
        }

        foreach ($rows as $row) {
            $serialized = $row['data_serialized'];
            if (null === $serialized) {
                continue;
            }

            // Suppress warnings; handle errors explicitly.
            $deserialized = @unserialize($serialized, ['allowed_classes' => false]);

            if (false === $deserialized && 'b:0;' !== $serialized) {
                fwrite(STDERR, sprintf(
                    "  Warning: could not unserialize row id=%s – skipping.\n",
                    $row['id']
                ));
                continue;
            }

            try {
                $json = json_encode($deserialized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            } catch (\JsonException $e) {
                fwrite(STDERR, sprintf(
                    "  Warning: could not JSON-encode row id=%s (%s) – skipping.\n",
                    $row['id'],
                    $e->getMessage()
                ));
                continue;
            }

            $connection->executeStatement(
                sprintf('UPDATE %s SET data = ? WHERE id = ?', $table),
                [$json, $row['id']]
            );
            ++$converted;
        }

        $offset += $batchSize;
    }

    return $converted;
}

/**
 * SQLite-specific workaround for renaming a column: recreate the table.
 * This also performs the data conversion in a single pass.
 */
function migrateViaSqliteRecreate(\Doctrine\DBAL\Connection $connection, string $table): void
{
    $tmpTable = $table.'_migration_tmp';

    // Fetch the original CREATE TABLE statement to clone the schema.
    $createSql = $connection->fetchOne(
        "SELECT sql FROM sqlite_master WHERE type='table' AND name=?",
        [$table]
    );

    if (false === $createSql || null === $createSql) {
        fwrite(STDERR, sprintf("Could not read schema for table '%s'.\n", $table));
        exit(1);
    }

    // Create a temporary table with `data_serialized` instead of `data`.
    $tmpCreate = str_replace(
        '"'.$table.'"',
        '"'.$tmpTable.'"',
        str_replace($table, $tmpTable, $createSql)
    );
    // Rename the data column definition to data_serialized in the DDL.
    $tmpCreate = preg_replace('/\bdata\b/', 'data_serialized', $tmpCreate, 1);

    $connection->executeStatement($tmpCreate);

    // Copy all data into the temporary table.
    $cols = $connection->fetchAllAssociative(sprintf('PRAGMA table_info(%s)', $table));
    $colList = implode(', ', array_map(static fn (array $c) => '"'.$c['name'].'"', $cols));
    $connection->executeStatement(sprintf('INSERT INTO "%s" SELECT %s FROM "%s"', $tmpTable, $colList, $table));

    // Drop the original table.
    $connection->executeStatement(sprintf('DROP TABLE "%s"', $table));

    // Rename the tmp table back.
    $connection->executeStatement(sprintf('ALTER TABLE "%s" RENAME TO "%s"', $tmpTable, $table));

    // Add the new `data` (JSON) column.
    $connection->executeStatement(sprintf('ALTER TABLE "%s" ADD COLUMN data TEXT DEFAULT NULL', $table));

    // Migrate data.
    $rows = $connection->fetchAllAssociative(sprintf('SELECT id, data_serialized FROM "%s" WHERE data_serialized IS NOT NULL', $table));
    foreach ($rows as $row) {
        $deserialized = @unserialize($row['data_serialized'], ['allowed_classes' => false]);
        if (false !== $deserialized || 'b:0;' === $row['data_serialized']) {
            try {
                $json = json_encode($deserialized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                $connection->executeStatement(sprintf('UPDATE "%s" SET data = ? WHERE id = ?', $table), [$json, $row['id']]);
            } catch (\JsonException) {
                fwrite(STDERR, sprintf("  Warning: could not JSON-encode row id=%s – skipping.\n", $row['id']));
            }
        } else {
            fwrite(STDERR, sprintf("  Warning: could not unserialize row id=%s – skipping.\n", $row['id']));
        }
    }

    echo sprintf("  SQLite migration complete (%d row(s) converted).\n", count($rows));
}

/**
 * Optionally drop the legacy `data_serialized` column.
 */
function finalize(\Doctrine\DBAL\Connection $connection, string $table, bool $dropLegacy): void
{
    if ($dropLegacy) {
        echo "Dropping legacy column 'data_serialized'...\n";
        $connection->executeStatement(sprintf('ALTER TABLE %s DROP COLUMN data_serialized', $table));
        echo "  Done.\n";
    } else {
        echo "\nMigration complete.\n";
        echo "The original data is still available in the 'data_serialized' column.\n";
        echo "Once you have verified the migration, you can drop it:\n";
        echo sprintf("  ALTER TABLE %s DROP COLUMN data_serialized;\n", $table);
    }
}
