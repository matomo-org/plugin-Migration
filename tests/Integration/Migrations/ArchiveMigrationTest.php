<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\tests\Integration\Migrations;

use Piwik\Common;
use Piwik\Config;
use Piwik\DataAccess\ArchiveTableCreator;
use Piwik\Date;
use Piwik\Db;
use Piwik\Plugins\Migration\Migrations;
use Piwik\Plugins\Migration\Migrations\ArchiveMigration;
use Piwik\Plugins\Migration\Migrations\Request;
use Piwik\Plugins\Migration\TargetDb;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group Migration
 * @group ArchiveMigrationTest
 * @group Plugins
 */
class ArchiveMigrationTest extends IntegrationTestCase
{
    private string $originalPrefix;
    private string $originalDatabase;
    private array $originalTables;
    private ?string $otherDatabase = null;

    public function setUp(): void
    {
        parent::setUp();
        $this->originalPrefix = Common::prefixTable('');
        $this->originalDatabase = (string) Db::fetchOne('SELECT DATABASE()');
        $this->originalTables = Db::get()->fetchCol('SHOW TABLES');
    }

    public function tearDown(): void
    {
        $this->setSourcePrefix($this->originalPrefix);
        ArchiveTableCreator::clear();
        foreach (array_diff(Db::get()->fetchCol('SHOW TABLES'), $this->originalTables) as $table) {
            Db::query('DROP TABLE ' . $this->quote($table));
        }
        if ($this->otherDatabase !== null) {
            Db::query('DROP DATABASE ' . $this->quote($this->otherDatabase));
        }
        parent::tearDown();
    }

    /**
     * @dataProvider getOverlappingPrefixes
     */
    public function test_sharedDatabaseDoesNotTreatTargetTablesAsSourceArchives(string $sourcePrefix, string $targetPrefix): void
    {
        $this->setSourcePrefix($sourcePrefix);
        $sourceTable = $this->createSourceArchive();
        $targetDb = $this->createTarget($targetPrefix);
        $targetDb->createArchiveTableIfNeeded('archive_numeric_2098_12');
        $targetDb->insert('archive_numeric_2098_12', $this->archiveRow(1, 900, 'target_existing', 999));
        $sourceBefore = Db::fetchAll('SELECT * FROM ' . $this->quote($sourceTable));
        $beforeTables = Db::get()->fetchCol('SHOW TABLES');

        ArchiveTableCreator::clear();
        $installed = ArchiveTableCreator::getTablesArchivesInstalled();
        // Core's SHOW TABLES LIKE '<prefix>archive_numeric%' includes these target tables.
        $this->assertContains($targetDb->prefixTable('archive_numeric_2098_12'), $installed);
        $this->assertContains($targetDb->prefixTable('sequence'), $installed);

        $migration = new ArchiveMigration();
        $migration->prepare($targetDb);
        $this->assertSame($beforeTables, Db::get()->fetchCol('SHOW TABLES'), 'Preparation must not create copies of destination tables.');

        $request = new Request();
        $request->sourceIdSite = 1;
        $request->targetIdSite = 2;
        (new Migrations())->migrate([$migration], $request, $targetDb);

        $this->assertSame($sourceBefore, Db::fetchAll('SELECT * FROM ' . $this->quote($sourceTable)));
        $this->assertSame($beforeTables, Db::get()->fetchCol('SHOW TABLES'));
        $this->assertEquals([
            ['idsite' => 1, 'name' => 'target_existing', 'value' => 999],
            ['idsite' => 2, 'name' => 'source_marker', 'value' => 111],
        ], $targetDb->fetchAll('SELECT idsite, name, value FROM ' . $targetDb->quoteTable('archive_numeric_2098_12') . ' ORDER BY idsite, name'));
        $this->assertGreaterThan(900, (int) $targetDb->fetchOne('SELECT idarchive FROM ' . $targetDb->quoteTable('archive_numeric_2098_12') . ' WHERE idsite = 2'));
    }

    public static function getOverlappingPrefixes(): array
    {
        return [
            'empty source, numeric-like destination' => ['', 'archive_numeric_'],
            'empty source, blob-like destination' => ['', 'archive_blob_'],
            'nonempty source, numeric-like destination' => ['m_', 'm_archive_numeric_'],
            // The original reported prefix can also exceed the 64-char table limit when prefixed twice.
            'long numeric-like destination' => ['', 'archive_numeric_target_'],
        ];
    }

    public function test_separateDatabasesWithTheSamePrefixStillCopyLegitimateSourceArchives(): void
    {
        $this->setSourcePrefix('archive_numeric_');
        $sourceTable = $this->createSourceArchive();
        $database = 'migration_archive_probe_' . substr(hash('sha256', $this->originalDatabase), 0, 8);
        Db::query('CREATE DATABASE ' . $this->quote($database));
        $this->otherDatabase = $database;
        $targetDb = $this->createTarget('archive_numeric_', $database);
        $targetDb->createArchiveTableIfNeeded('archive_numeric_2098_12');
        $targetDb->insert('archive_numeric_2098_12', $this->archiveRow(1, 900, 'target_existing', 999));
        $sourceBefore = Db::fetchAll('SELECT * FROM ' . $this->quote($sourceTable));
        ArchiveTableCreator::clear();

        $request = new Request();
        $request->sourceIdSite = 1;
        $request->targetIdSite = 2;
        (new Migrations())->migrate([new ArchiveMigration()], $request, $targetDb);

        $this->assertSame($sourceBefore, Db::fetchAll('SELECT * FROM ' . $this->quote($sourceTable)));
        $this->assertEquals([
            ['idsite' => 1, 'name' => 'target_existing', 'value' => 999],
            ['idsite' => 2, 'name' => 'source_marker', 'value' => 111],
        ], $targetDb->fetchAll('SELECT idsite, name, value FROM ' . $targetDb->quoteTable('archive_numeric_2098_12') . ' ORDER BY idsite, name'));
        $this->assertGreaterThan(900, (int) $targetDb->fetchOne('SELECT idarchive FROM ' . $targetDb->quoteTable('archive_numeric_2098_12') . ' WHERE idsite = 2'));
    }

    /**
     * @dataProvider getArchiveTypePrefixes
     */
    public function test_archiveCreationReplacesTheWholeSourceTemplateName(string $sourcePrefix, string $type): void
    {
        $this->setSourcePrefix($sourcePrefix);
        $targetDb = $this->createTarget('archive_create_probe_');
        $beforeTables = Db::get()->fetchCol('SHOW TABLES');
        $table = $type . '_2098_12';

        $targetDb->createArchiveTableIfNeeded($table);

        $this->assertTrue($targetDb->doesTableExist($targetDb->prefixTable($table)));
        $this->assertSame([$targetDb->prefixTable($table)], array_values(array_diff(Db::get()->fetchCol('SHOW TABLES'), $beforeTables)));
        $valueType = strtolower($targetDb->getTableColumns($targetDb->prefixTable($table))['value']['Type']);
        $this->assertStringContainsString($type === 'archive_numeric' ? 'double' : 'blob', $valueType);
    }

    public static function getArchiveTypePrefixes(): array
    {
        return [
            ['archive_numeric_', 'archive_numeric'],
            ['archive_blob_', 'archive_blob'],
        ];
    }

    /**
     * @dataProvider getSequenceRepairCases
     */
    public function test_preparationRepairsTheNumericSequenceWithoutChangingTheTargetPrefix(string $targetPrefix, string $type): void
    {
        $targetDb = $this->createTarget($targetPrefix);
        $numericTable = 'archive_numeric_2098_12';
        $targetDb->createArchiveTableIfNeeded($numericTable);
        $targetDb->insert($numericTable, $this->archiveRow(1, 900, 'target_existing', 999));
        $numericName = $targetDb->prefixTable($numericTable);
        $targetDb->delete('sequence', ['name' => $numericName]);
        $targetDb->insert('sequence', ['name' => $numericName, 'value' => 0]);

        $targetDb->createArchiveTableIfNeeded($type . '_2098_12');

        $this->assertSame(920, (int) $targetDb->fetchOne('SELECT value FROM ' . $targetDb->quoteTable('sequence') . ' WHERE name = ?', [$numericName]));
        $this->assertSame(921, $targetDb->createArchiveId($numericTable));
    }

    public static function getSequenceRepairCases(): array
    {
        return [
            'numeric, blob-like target prefix' => ['archive_blob_', 'archive_numeric'],
            'blob, blob-like target prefix' => ['archive_blob_', 'archive_blob'],
            'blob, ordinary target prefix' => ['sequence_probe_', 'archive_blob'],
        ];
    }

    private function createSourceArchive(): string
    {
        ArchiveTableCreator::clear();
        $table = ArchiveTableCreator::getNumericTable(Date::factory('2098-12-01'), true);
        Db::get()->insert($table, $this->archiveRow(1, 10, 'source_marker', 111));
        return $table;
    }

    private function createTarget(string $prefix, ?string $database = null): TargetDb
    {
        $targetDb = new TargetDb(['tables_prefix' => $prefix, 'dbname' => $database ?? $this->originalDatabase]);
        // Clone only the sequence structure, using the original source's actual database explicitly.
        $sourceSequence = $this->quote($this->originalDatabase) . '.' . $this->quote($this->originalPrefix . 'sequence');
        $targetDb->getDb()->query('CREATE TABLE ' . $targetDb->quoteTable('sequence') . ' LIKE ' . $sourceSequence);
        return $targetDb;
    }

    private function archiveRow(int $idSite, int $idArchive, string $name, int $value): array
    {
        return [
            'idarchive' => $idArchive,
            'name' => $name,
            'idsite' => $idSite,
            'date1' => '2098-12-01',
            'date2' => '2098-12-01',
            'period' => 1,
            'ts_archived' => '2098-12-01 00:00:00',
            'value' => $value,
        ];
    }

    private function quote(string $name): string
    {
        return Db::get()->quoteIdentifier([$name]);
    }

    private function setSourcePrefix(string $prefix): void
    {
        $config = Config::getInstance();
        $database = $config->database;
        $database['tables_prefix'] = $prefix;
        $config->database = $database;
    }
}
