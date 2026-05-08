<?php

declare(strict_types=1);

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\Loggable\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class MigrateDataToJsonCommand extends Command
{
    protected static $defaultName = 'gedmo:loggable:migrate-data-to-json';
    protected static $defaultDescription = 'Migrate Loggable data column from PHP serialized values to JSON.';

    private ?Connection $connection;

    public function __construct(?Connection $connection = null)
    {
        parent::__construct();

        $this->connection = $connection;
    }

    protected function configure(): void
    {
        $this
            ->addOption('dsn', null, InputOption::VALUE_REQUIRED, 'DBAL DSN. Optional when using injected DBAL connection or DATABASE_URL env var.')
            ->addOption('table', null, InputOption::VALUE_REQUIRED, 'Name of the log entry table.', 'ext_log_entries')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Rows per round-trip.', '500')
            ->addOption('drop-legacy', null, InputOption::VALUE_NONE, 'Drop the data_serialized column after successful migration.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table = (string) $input->getOption('table');
        $batchSize = (int) $input->getOption('batch-size');
        $dropLegacy = (bool) $input->getOption('drop-legacy');

        if ($batchSize < 1) {
            $output->writeln('<error>--batch-size must be greater than 0.</error>');

            return self::FAILURE;
        }

        $connection = $this->connection;

        if (null === $connection) {
            $dsn = (string) ($input->getOption('dsn') ?: (getenv('DATABASE_URL') ?: ''));

            if ('' === $dsn) {
                $output->writeln('<error>No DB connection available. Provide --dsn, set DATABASE_URL, or register this command with an injected Doctrine DBAL Connection.</error>');

                return self::FAILURE;
            }

            try {
                $connection = DriverManager::getConnection(['url' => $dsn]);
                $connection->connect();
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>Could not connect to the database: %s</error>', $e->getMessage()));

                return self::FAILURE;
            }
        }

        $platform = $connection->getDatabasePlatform();
        $schemaManager = method_exists($connection, 'createSchemaManager')
            ? $connection->createSchemaManager()
            : $connection->getSchemaManager(); // DBAL 3 compat

        $quotedTable = $platform->quoteSingleIdentifier($table);
        $columns = $schemaManager->listTableColumns($table);
        $columnNames = array_keys($columns);

        $hasData = in_array('data', $columnNames, true);
        $hasDataSerialized = in_array('data_serialized', $columnNames, true);

        if (!$hasData && !$hasDataSerialized) {
            $output->writeln(sprintf("<error>Neither 'data' nor 'data_serialized' column found in table '%s'. Nothing to migrate.</error>", $table));

            return self::FAILURE;
        }

        $platformName = strtolower(get_class($platform));
        $isSqlite = str_contains($platformName, 'sqlite');

        if ($hasData && !$hasDataSerialized) {
            $output->writeln(sprintf("Step 1: Renaming column 'data' to 'data_serialized' in table '%s'...", $table));

            if ($isSqlite) {
                $this->migrateViaSqliteRecreate($connection, $platform, $table, $quotedTable, $output);
                $this->finalize($connection, $platform, $table, $quotedTable, $dropLegacy, $output);

                return self::SUCCESS;
            }

            $connection->executeStatement(sprintf(
                'ALTER TABLE %s RENAME COLUMN %s TO %s',
                $quotedTable,
                $platform->quoteSingleIdentifier('data'),
                $platform->quoteSingleIdentifier('data_serialized')
            ));
            $output->writeln('  Done.');
        } elseif ($hasDataSerialized && !$hasData) {
            $output->writeln("Step 1: Column 'data_serialized' already exists; skipping rename.");
        } else {
            $output->writeln("Step 1: Both 'data' and 'data_serialized' columns exist; assuming rename was already performed.");
        }

        $columnsAfterRename = array_keys($schemaManager->listTableColumns($table));
        if (!in_array('data', $columnsAfterRename, true)) {
            $output->writeln(sprintf("Step 2: Adding new 'data' column to table '%s'...", $table));
            $clobType = $platform->getClobTypeDeclarationSQL([]);
            $connection->executeStatement(sprintf(
                'ALTER TABLE %s ADD COLUMN %s %s DEFAULT NULL',
                $quotedTable,
                $platform->quoteSingleIdentifier('data'),
                $clobType
            ));
            $output->writeln('  Done.');
        } else {
            $output->writeln("Step 2: Column 'data' already exists; skipping ADD COLUMN.");
        }

        $output->writeln('Step 3: Converting serialized data to JSON...');
        $converted = $this->convertRows($connection, $platform, $quotedTable, $batchSize, $output);
        $output->writeln(sprintf('  Converted %d row(s).', $converted));

        $this->finalize($connection, $platform, $table, $quotedTable, $dropLegacy, $output);

        return self::SUCCESS;
    }

    private function convertRows(
        Connection $connection,
        AbstractPlatform $platform,
        string $quotedTable,
        int $batchSize,
        OutputInterface $output
    ): int {
        $converted = 0;
        $offset = 0;

        $qData = $platform->quoteSingleIdentifier('data');
        $qDataSerialized = $platform->quoteSingleIdentifier('data_serialized');
        $qId = $platform->quoteSingleIdentifier('id');

        while (true) {
            $rows = $connection->fetchAllAssociative(
                sprintf(
                    'SELECT %s, %s FROM %s WHERE %s IS NOT NULL AND %s IS NULL LIMIT %d OFFSET %d',
                    $qId,
                    $qDataSerialized,
                    $quotedTable,
                    $qDataSerialized,
                    $qData,
                    $batchSize,
                    $offset
                )
            );

            if ([] === $rows) {
                break;
            }

            foreach ($rows as $row) {
                $serialized = $row['data_serialized'];
                if (null === $serialized) {
                    continue;
                }

                $deserialized = unserialize($serialized, ['allowed_classes' => false]);

                if (false === $deserialized && 'b:0;' !== $serialized) {
                    $output->writeln(sprintf('  <comment>Warning: could not unserialize row id=%s – skipping.</comment>', $row['id']));
                    continue;
                }

                try {
                    $json = json_encode($deserialized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                } catch (\JsonException $e) {
                    $output->writeln(sprintf('  <comment>Warning: could not JSON-encode row id=%s (%s) – skipping.</comment>', $row['id'], $e->getMessage()));
                    continue;
                }

                $connection->executeStatement(
                    sprintf('UPDATE %s SET %s = ? WHERE %s = ?', $quotedTable, $qData, $qId),
                    [$json, $row['id']]
                );
                ++$converted;
            }

            $offset += $batchSize;
        }

        return $converted;
    }

    private function migrateViaSqliteRecreate(
        Connection $connection,
        AbstractPlatform $platform,
        string $table,
        string $quotedTable,
        OutputInterface $output
    ): void {
        $tmpTable = $table.'_migration_tmp';
        $quotedTmp = $platform->quoteSingleIdentifier($tmpTable);

        $createSql = $connection->fetchOne(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name=?",
            [$table]
        );

        if (false === $createSql || null === $createSql) {
            throw new \RuntimeException(sprintf("Could not read schema for table '%s'.", $table));
        }

        $tmpCreate = preg_replace(
            '/^(CREATE\\s+TABLE\\s+)((?:"[^"]*"|`[^`]*`|\\[[^\\]]*\\]|[a-zA-Z_][a-zA-Z0-9_]*))(\\s*\\()/i',
            '$1'.$quotedTmp.'$3',
            $createSql,
            1
        );

        $tmpCreate = preg_replace(
            '/(?<=\\(|,)\\s*("data"|`data`|\\[data\\]|(?<![a-zA-Z0-9_])data(?![a-zA-Z0-9_]))(?=\\s)/i',
            ' "data_serialized"',
            $tmpCreate,
            1
        );

        $connection->executeStatement($tmpCreate);

        $cols = $connection->fetchAllAssociative(sprintf('PRAGMA table_info(%s)', $table));
        $colList = implode(', ', array_map(
            static fn (array $c): string => $platform->quoteSingleIdentifier($c['name']),
            $cols
        ));
        $connection->executeStatement(sprintf('INSERT INTO %s SELECT %s FROM %s', $quotedTmp, $colList, $quotedTable));

        $connection->executeStatement(sprintf('DROP TABLE %s', $quotedTable));
        $connection->executeStatement(sprintf('ALTER TABLE %s RENAME TO %s', $quotedTmp, $quotedTable));

        $clobType = $platform->getClobTypeDeclarationSQL([]);
        $connection->executeStatement(sprintf(
            'ALTER TABLE %s ADD COLUMN %s %s DEFAULT NULL',
            $quotedTable,
            $platform->quoteSingleIdentifier('data'),
            $clobType
        ));

        $qData = $platform->quoteSingleIdentifier('data');
        $qDataSerialized = $platform->quoteSingleIdentifier('data_serialized');
        $qId = $platform->quoteSingleIdentifier('id');

        $rows = $connection->fetchAllAssociative(
            sprintf('SELECT %s, %s FROM %s WHERE %s IS NOT NULL', $qId, $qDataSerialized, $quotedTable, $qDataSerialized)
        );
        $converted = 0;
        foreach ($rows as $row) {
            $deserialized = unserialize($row['data_serialized'], ['allowed_classes' => false]);
            if (false !== $deserialized || 'b:0;' === $row['data_serialized']) {
                try {
                    $json = json_encode($deserialized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                    $connection->executeStatement(
                        sprintf('UPDATE %s SET %s = ? WHERE %s = ?', $quotedTable, $qData, $qId),
                        [$json, $row['id']]
                    );
                    ++$converted;
                } catch (\JsonException) {
                    $output->writeln(sprintf('  <comment>Warning: could not JSON-encode row id=%s – skipping.</comment>', $row['id']));
                }
            } else {
                $output->writeln(sprintf('  <comment>Warning: could not unserialize row id=%s – skipping.</comment>', $row['id']));
            }
        }

        $output->writeln(sprintf('  SQLite migration complete (%d row(s) converted).', $converted));
    }

    private function finalize(
        Connection $connection,
        AbstractPlatform $platform,
        string $table,
        string $quotedTable,
        bool $dropLegacy,
        OutputInterface $output
    ): void {
        if ($dropLegacy) {
            $output->writeln("Dropping legacy column 'data_serialized'...");
            $connection->executeStatement(sprintf(
                'ALTER TABLE %s DROP COLUMN %s',
                $quotedTable,
                $platform->quoteSingleIdentifier('data_serialized')
            ));
            $output->writeln('  Done.');

            return;
        }

        $output->writeln('');
        $output->writeln('Migration complete.');
        $output->writeln("The original data is still available in the 'data_serialized' column.");
        $output->writeln('Once you have verified the migration, you can drop it:');
        $output->writeln(sprintf('  ALTER TABLE %s DROP COLUMN data_serialized;', $table));
    }
}
