<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\tests\Integration;

use Piwik\Common;
use Piwik\Db;
use Piwik\Plugins\Migration\Migrations\LogActionMigration;
use Piwik\Plugins\Migration\Migrations\PluginDataMigration;
use Piwik\Plugins\Migration\Migrations\Request;
use Piwik\Plugins\Migration\PluginData\PluginDataMigrator;
use Piwik\Plugins\Migration\PluginData\PluginDefinition;
use Piwik\Plugins\Migration\PluginData\PluginDefinitions;
use Piwik\Plugins\Migration\PluginData\TableDefinition;
use Piwik\Plugins\Migration\TargetDb;
use Piwik\Plugins\SitesManager\API as SitesAPI;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * Checks definitions using features where they aren't supported are refused before anything is migrated.
 *
 * @group Migration
 * @group PluginDataMigratorTest
 * @group Plugins
 */
class PluginDataMigratorTest extends IntegrationTestCase
{
    private const TARGET_PREFIX = 'testprefix_';

    /**
     * The plugin of the definitions needs to be activated, like this one
     */
    private const PLUGIN_NAME = 'Migration';

    private const TABLES = [
        'mtest_item' => '`iditem` INT UNSIGNED NOT NULL AUTO_INCREMENT, `idsite` INT UNSIGNED NOT NULL, `name` VARCHAR(50) NOT NULL, PRIMARY KEY (`iditem`)',
        'mtest_item_part' => '`iditem` INT UNSIGNED NOT NULL, `name` VARCHAR(50) NOT NULL',
        'mtest_shared' => '`idshared` INT UNSIGNED NOT NULL AUTO_INCREMENT, `hash` INT UNSIGNED NOT NULL, `name` VARCHAR(50) NOT NULL, PRIMARY KEY (`idshared`)',
        'mtest_import' => '`property` VARCHAR(50) NOT NULL, `date` DATE NOT NULL, `data` TEXT NULL, PRIMARY KEY (`property`, `date`)',
        'mtest_import_by_date' => '`property` VARCHAR(50) NOT NULL, `date` DATE NOT NULL, `data` TEXT NULL, PRIMARY KEY (`date`)',
        'mtest_log' => '`idrow` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `idsite` INT UNSIGNED NOT NULL, `server_time` DATETIME NOT NULL, `data` TEXT NULL',
    ];

    /**
     * @var TargetDb
     */
    private $targetDb;

    public function setUp(): void
    {
        parent::setUp();
        $this->targetDb = new TargetDb(['tables_prefix' => self::TARGET_PREFIX]);
        foreach (self::TABLES as $table => $columns) {
            foreach ([Common::prefixTable($table), self::TARGET_PREFIX . $table] as $prefixedTable) {
                Db::query(sprintf('CREATE TABLE `%s` (%s)', $prefixedTable, $columns));
            }
        }
    }

    public function tearDown(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            foreach ([Common::prefixTable($table), self::TARGET_PREFIX . $table] as $prefixedTable) {
                Db::query(sprintf('DROP TABLE IF EXISTS `%s`', $prefixedTable));
            }
        }
        parent::tearDown();
    }

    public function test_validate_acceptsTheFeaturesWhereTheyAreSupported()
    {
        $definition = new PluginDefinition(self::PLUGIN_NAME);
        $definition->addTable(TableDefinition::shared('mtest_shared', 'idshared', ['hash', 'name'])->keepsUnique('name'));
        $definition->addTable(TableDefinition::forSite('mtest_item')->autoIncrement('iditem')->identifiedBy('name')
            ->includesRowsOfSeveralSites('idsite = 0'));
        $definition->addTable(TableDefinition::childOf('mtest_item_part', 'mtest_item', 'iditem'));
        $definition->identifiesSubcategoriesBy('MTest_Items', 'mtest_item');
        $definition->translatesArchiveRowColumnNames('/^MTest_items$/', 'mtest_shared', '/^shared_(\d+)$/');
        $definition->addTable(TableDefinition::forSiteKeys('mtest_import', 'property', 'date', [$this, 'getSiteKeys']));

        $this->assertEquals([], $this->validate($definition));
    }

    /** @dataProvider getExistingConfigurationRuns */
    public function test_existingConfigurationKeepsItsValuesAndMapsParentAndChildIds(bool $dryRun, bool $differentSettings): void
    {
        foreach ([Common::prefixTable(''), self::TARGET_PREFIX] as $prefix) {
            Db::query('ALTER TABLE ' . $prefix . 'mtest_item ADD enabled INT NOT NULL DEFAULT 1, ADD copy_of INT NULL');
            Db::query('ALTER TABLE ' . $prefix . 'mtest_item_part ADD idpart INT AUTO_INCREMENT PRIMARY KEY, ADD position INT NOT NULL');
        }
        Db::get()->insert(Common::prefixTable('mtest_item'), ['iditem' => 7, 'idsite' => 1, 'name' => 'Existing configuration', 'enabled' => (int) $differentSettings, 'copy_of' => 7]);
        Db::get()->insert(Common::prefixTable('mtest_item_part'), ['idpart' => 8, 'iditem' => 7, 'name' => $differentSettings ? 'Source setting' : 'Destination setting', 'position' => 1]);
        $this->targetDb->insert('mtest_item', ['iditem' => 79, 'idsite' => 2, 'name' => 'Existing configuration', 'enabled' => 0, 'copy_of' => 79]);
        $this->targetDb->insert('mtest_item_part', ['idpart' => 80, 'iditem' => 79, 'name' => 'Destination setting', 'position' => 1]);
        $definition = new PluginDefinition(self::PLUGIN_NAME);
        $definition->addTable(TableDefinition::forSite('mtest_item')->autoIncrement('iditem')->identifiedBy('name')->referencesOwnTable('copy_of'));
        $definition->addTable(TableDefinition::childOf('mtest_item_part', 'mtest_item', 'iditem')->autoIncrement('idpart')->identifiedBy('position'));
        $definitions = new PluginDefinitions();
        $definitions->add($definition);
        $migrator = new PluginDataMigrator($definitions);
        $this->assertSame([], $migrator->validate($this->targetDb, true));
        $request = new Request();
        $request->sourceIdSite = 1;
        $request->targetIdSite = 2;
        $request->isExistingTargetSite = true;
        $request->idMaps->set('site', 1, 2);
        if ($dryRun) {
            $this->targetDb->enableDryRun();
        }
        $migrator->migrateSiteData($request, $this->targetDb);
        $this->assertEquals(79, $request->idMaps->get('mtest_item', 7));
        $this->assertEquals(80, $request->idMaps->get('mtest_item_part', 8));
        $this->assertEquals(1, $this->targetDb->fetchOne('SELECT COUNT(*) FROM testprefix_mtest_item'));
        $this->assertEquals(0, $this->targetDb->fetchOne('SELECT enabled FROM testprefix_mtest_item WHERE iditem = 79'));
        $this->assertEquals(1, $this->targetDb->fetchOne('SELECT COUNT(*) FROM testprefix_mtest_item_part'));
        $this->assertSame('Destination setting', $this->targetDb->fetchOne('SELECT name FROM testprefix_mtest_item_part WHERE idpart = 80'));
        $this->assertSame($differentSettings, $request->archivesNotCopiedReason !== null, 'Only different definitions require rebuilding; remapped self/child IDs alone do not.');
    }

    public static function getExistingConfigurationRuns(): array
    {
        return ['different settings' => [false, true], 'different settings dry run' => [true, true], 'equivalent settings' => [false, false], 'equivalent settings dry run' => [true, false]];
    }

    /**
     * @dataProvider getTableKinds
     */
    public function test_validateStructure_reportsMissingSourceTable(bool $siteStep): void
    {
        $definition = new PluginDefinition(self::PLUGIN_NAME);
        if ($siteStep) {
            $definition->migratesSiteWith(function () {
                return [];
            }, 'mtest_item');
        } else {
            $definition->addTable(TableDefinition::forSite('mtest_item')->autoIncrement('iditem')->identifiedBy('name'));
        }
        $definition->addTable(TableDefinition::forSiteKeys('mtest_import', 'property', 'date', [$this, 'getSiteKeys']));
        $definitions = new PluginDefinitions();
        $definitions->add($definition);
        Db::query('DROP TABLE ' . Common::prefixTable('mtest_item'));
        Db::query('ALTER TABLE ' . self::TARGET_PREFIX . 'mtest_import ADD extra_column INT NOT NULL');

        $migration = new PluginDataMigration(new PluginDataMigrator($definitions), true);
        $this->assertSame([
            'The table "' . Common::prefixTable('mtest_item') . '" of plugin Migration does not exist in the source DB. Please make sure the plugin is installed correctly.',
            'The following columns are missing in the source DB table "' . Common::prefixTable('mtest_import') . '": extra_column',
        ], $migration->validateStructure($this->targetDb));
    }

    /**
     * @dataProvider getTableKinds
     */
    public function test_migrate_distinguishesSkippedAndUnknownTables(bool $siteStep): void
    {
        $definition = new PluginDefinition(self::PLUGIN_NAME);
        if ($siteStep) {
            $definition->migratesSiteWith(function () {
                return [];
            }, 'mtest_item');
        } else {
            $definition->addTable(TableDefinition::forSite('mtest_item')->autoIncrement('iditem')->identifiedBy('name'));
        }
        $definitions = new PluginDefinitions();
        $definitions->add($definition);
        Db::get()->insert(Common::prefixTable('mtest_item'), ['idsite' => 1, 'name' => 'Skipped item']);
        Db::query('ALTER TABLE ' . Common::prefixTable('mtest_item_part') . ' ADD idsite INT NOT NULL');
        Db::get()->insert(Common::prefixTable('mtest_item_part'), ['idsite' => 1, 'iditem' => 1, 'name' => 'Unknown item']);
        $migration = new PluginDataMigration(new PluginDataMigrator($definitions, [self::PLUGIN_NAME]), true);
        $messages = [];
        $migration->onLog(function ($message) use (&$messages) {
            $messages[] = $message;
        });
        $request = new Request();
        $request->sourceIdSite = 1;
        $request->targetIdSite = 2;

        $migration->migrate($request, $this->targetDb);

        $this->assertContains('The table ' . Common::prefixTable('mtest_item') . ' has data of this site that is not migrated, as plugin Migration was excluded with --skip-plugin-data', $messages);
        $this->assertContains('The table ' . Common::prefixTable('mtest_item_part') . ' has data of this site that is not migrated, as no plugin data definition of an activated plugin describes it', $messages);
        $this->assertSame(0, (int) $this->targetDb->fetchOne('SELECT COUNT(*) FROM ' . self::TARGET_PREFIX . 'mtest_item'));
    }

    public function getTableKinds(): array
    {
        return ['table definition' => [false], 'site step' => [true]];
    }

    public function test_skipLogsDoesNotReadSourcePropertyKeys(): void
    {
        $definition = new PluginDefinition(self::PLUGIN_NAME);
        $definition->addTable(TableDefinition::forSiteKeys('mtest_import', 'property', 'date', function () {
            throw new \RuntimeException('Raw property data was excluded');
        }));
        $definition->addNote('Imported property data');
        $definitions = new PluginDefinitions();
        $definitions->add($definition);
        $migration = new PluginDataMigration(new PluginDataMigrator($definitions), false);
        $request = new Request();
        $request->sourceIdSite = 1;
        $request->targetIdSite = 2;
        $this->assertSame([], $migration->validateStructure($this->targetDb));
        $migration->migrate($request, $this->targetDb);
        $this->assertSame(0, (int) $this->targetDb->fetchOne('SELECT COUNT(*) FROM ' . self::TARGET_PREFIX . 'mtest_import'));
    }

    /** @dataProvider getSiteLogCases */
    public function test_siteLogUsesDeclaredAutoIncrementIndex(bool $primaryKey, bool $dryRun): void
    {
        $table = 'mtest_site_log';
        $source = Common::prefixTable($table);
        $target = $this->targetDb->prefixTable($table);
        $index = $primaryKey ? 'PRIMARY KEY (idrow)' : 'UNIQUE KEY row_sequence (idrow)';
        $schema = "(idrow INT UNSIGNED NOT NULL AUTO_INCREMENT, idsite INT UNSIGNED NOT NULL, iditem INT UNSIGNED NOT NULL, server_time DATETIME NOT NULL, value INT NOT NULL, $index) ENGINE=InnoDB";
        Db::query("CREATE TABLE $source $schema");
        Db::query("CREATE TABLE $target $schema");
        try {
            // More than one page, gaps in the sequence, another site, and a missing referenced item.
            $values = [];
            $bind = [];
            for ($i = 1; $i <= 1001; ++$i) {
                $values[] = '(?, 1, 7, ?, ?)';
                array_push($bind, $i * 2, '2013-01-01 01:00:00', $i);
            }
            Db::query("INSERT INTO $source (idrow, idsite, iditem, server_time, value) VALUES " . implode(', ', $values), $bind);
            Db::query("INSERT INTO $source VALUES (2003, 99, 7, '2013-01-01 01:00:00', -1), (2004, 1, 999, '2013-01-01 01:00:00', -2)");
            Db::query("INSERT INTO $target VALUES (5000, 99, 79, '2013-01-01 01:00:00', -3)");
            Db::get()->insert(Common::prefixTable('mtest_item'), ['iditem' => 7, 'idsite' => 1, 'name' => 'Mapped item']);
            $this->targetDb->insert('mtest_item', ['iditem' => 79, 'idsite' => 99, 'name' => 'Unrelated target item']);
            $definition = new PluginDefinition(self::PLUGIN_NAME);
            $definition->addTable(TableDefinition::forSite('mtest_item')->autoIncrement('iditem'));
            $definition->addTable(TableDefinition::forSiteLog($table, 'server_time')->autoIncrement('idrow')->references('iditem', 'mtest_item'));
            $definitions = new PluginDefinitions();
            $definitions->add($definition);
            $migrator = new PluginDataMigrator($definitions);
            $this->assertSame([], $migrator->validate($this->targetDb, true));
            $request = new Request();
            $request->sourceIdSite = 1;
            $request->targetIdSite = 2;
            $request->idMaps->set(TableDefinition::SITE, 1, 2);
            $sourceBefore = Db::fetchAll("SELECT * FROM $source ORDER BY idrow");
            $targetBefore = Db::fetchAll("SELECT * FROM $target ORDER BY idrow");
            if ($dryRun) {
                $this->targetDb->enableDryRun();
            }
            $migrator->migrateSiteData($request, $this->targetDb);

            $counts = $migrator->migrateSiteLogData($request, $this->targetDb, new LogActionMigration());

            $this->assertSame(['migrated' => 1001, 'skipped' => 1, 'existing' => 0, 'duplicate' => 0, 'present' => 0], $counts[$table]);
            $this->assertSame([], $request->idMaps->getAll($table), 'Raw row IDs must not accumulate across batches.');
            if ($dryRun) {
                $this->assertSame($targetBefore, Db::fetchAll("SELECT * FROM $target ORDER BY idrow"));
            } else {
                $rows = Db::fetchAll("SELECT * FROM $target WHERE idsite = 2 ORDER BY idrow");
                $this->assertCount(1001, $rows);
                $this->assertEquals(range(1, 1001), array_column($rows, 'value'));
                $this->assertEquals([80], array_values(array_unique(array_column($rows, 'iditem'))));
                $this->assertGreaterThan(5000, (int) $rows[0]['idrow']);
                $this->assertSame($targetBefore, Db::fetchAll("SELECT * FROM $target WHERE idsite = 99 ORDER BY idrow"));
            }
            $this->assertSame($sourceBefore, Db::fetchAll("SELECT * FROM $source ORDER BY idrow"));
        } finally {
            Db::query("DROP TABLE $source");
            Db::query("DROP TABLE $target");
        }
    }

    public static function getSiteLogCases(): array
    {
        return ['primary key' => [true, false], 'unique index' => [false, false], 'primary dry run' => [true, true], 'unique dry run' => [false, true]];
    }

    public function test_dateRangeFiltersSiteLogsInUtcAndPropertyDataByCalendarDay(): void
    {
        $idSite = SitesAPI::getInstance()->addSite('Source', ['https://example.com'], timezone: 'Europe/Bucharest');
        $definition = new PluginDefinition(self::PLUGIN_NAME);
        $definition->addTable(TableDefinition::forSiteLog('mtest_log', 'server_time')->autoIncrement('idrow'));
        $definition->addTable(TableDefinition::forSiteKeys('mtest_import', 'property', 'date', [$this, 'getSiteKeys']));
        $definitions = new PluginDefinitions();
        $definitions->add($definition);
        $migrator = new PluginDataMigrator($definitions);
        foreach (['2024-01-22 21:59:59', '2024-01-22 22:00:00', '2024-01-23 21:59:59', '2024-01-23 22:00:00'] as $time) {
            Db::get()->insert(Common::prefixTable('mtest_log'), ['idsite' => $idSite, 'server_time' => $time, 'data' => $time]);
        }
        foreach (['2024-01-22', '2024-01-23', '2024-01-24'] as $date) {
            Db::get()->insert(Common::prefixTable('mtest_import'), ['property' => 'https://example.com/', 'date' => $date, 'data' => 'source']);
        }
        Db::get()->insert(Common::prefixTable('mtest_log'), ['idsite' => $idSite + 1, 'server_time' => '2024-01-23 12:00:00', 'data' => 'other site']);
        Db::get()->insert(Common::prefixTable('mtest_import'), ['property' => 'https://other.example/', 'date' => '2024-01-23', 'data' => 'other property']);
        $this->targetDb->insert('mtest_import', ['property' => 'https://example.com/', 'date' => '2024-01-23', 'data' => 'destination']);
        $request = new Request();
        $request->sourceIdSite = $idSite;
        $request->targetIdSite = 100;
        $request->startDate = '2024-01-23';
        $request->endDate = '2024-01-23';
        $request->idMaps->set(TableDefinition::SITE, $idSite, 100);

        $counts = $migrator->migrateSiteLogData($request, $this->targetDb, new LogActionMigration());

        $this->assertSame(2, $counts['mtest_log']['migrated']);
        $this->assertEquals([
            ['idsite' => 100, 'server_time' => '2024-01-22 22:00:00'],
            ['idsite' => 100, 'server_time' => '2024-01-23 21:59:59'],
        ], $this->targetDb->fetchAll('SELECT idsite, server_time FROM ' . $this->targetDb->quoteTable('mtest_log') . ' ORDER BY idrow'));
        $this->assertSame(1, $counts['mtest_import']['present']);
        $this->assertSame(0, $counts['mtest_import']['migrated']);
        $this->assertSame([
            ['property' => 'https://example.com/', 'date' => '2024-01-23', 'data' => 'destination'],
        ], $this->targetDb->fetchAll('SELECT * FROM ' . $this->targetDb->quoteTable('mtest_import')));
    }

    public function test_validate_refusesTheFeaturesWhereTheyAreNotSupported()
    {
        $definition = new PluginDefinition(self::PLUGIN_NAME);
        // a unique column the rows aren't matched by
        $definition->addTable(TableDefinition::shared('mtest_shared', 'idshared', ['hash'])->keepsUnique('name'));
        // rows of several sites without identity, and of a table that doesn't belong to the site
        $definition->addTable(TableDefinition::forSite('mtest_item')->autoIncrement('iditem')->includesRowsOfSeveralSites('idsite = 0'));
        $definition->addTable(TableDefinition::childOf('mtest_item_part', 'mtest_item', 'iditem')->identifiedBy('name')
            ->includesRowsOfSeveralSites('iditem = 0'));
        $definition->identifiesSubcategoriesBy('MTest_Items', 'mtest_shared');
        $definition->translatesArchiveRowColumnNames('/^MTest_items$/', 'mtest_item_part', '/^part_(\d+)$/');
        $definition->translatesArchiveRowColumnNames('/^MTest_items$/', 'mtest_shared', '/^shared_(\d+$/');
        // rows stored per key without the key in the primary key
        $definition->addTable(TableDefinition::forSiteKeys('mtest_import_by_date', 'property', 'date', [$this, 'getSiteKeys']));
        // a step migrating tables that don't exist
        $definition->migratesSiteWith(function () {
            return [];
        }, 'mtest_missing');
        Db::query('DROP TABLE ' . self::TARGET_PREFIX . 'mtest_import');
        $definition->migratesSiteWith(function () {
            return [];
        }, 'mtest_import');

        $this->assertEquals([
            'The table "mtest_shared" of plugin Migration keeps the column "name" unique, which is only supported for shared tables matching rows by this column.',
            'The table "mtest_item" of plugin Migration includes rows of several sites, which is only supported for tables belonging to the site through a site column that have an auto increment or unique key column and identity columns.',
            'The table "mtest_item_part" of plugin Migration includes rows of several sites, which is only supported for tables belonging to the site through a site column that have an auto increment or unique key column and identity columns.',
            'The table "mtest_import_by_date" of plugin Migration is stored per key of the site, its primary key needs to include the column "property".',
            'The table "mtest_missing" of plugin Migration does not exist in the source DB. Please make sure the plugin is installed correctly.',
            'The table "testprefix_mtest_import" of plugin Migration does not exist in the target DB. Please install and activate the plugin on the target Matomo, or use --skip-plugin-data=Migration to not migrate its data.',
            'The IDs in the column names of report rows of plugin Migration must refer to a site or shared table having an auto increment or unique key column, "mtest_item_part" is not one.',
            'The archive record pattern "/^MTest_items$/" or column pattern "/^shared_(\d+$/" of plugin Migration is not a valid regular expression.',
            'The reporting pages of the category "MTest_Items" of plugin Migration must refer to a site table having an auto increment or unique key column, "mtest_shared" is not one.',
        ], $this->validate($definition));
    }

    /**
     * @dataProvider getQuotedIdentifierCases
     */
    public function test_migrateSiteData_quotesNamesAndPreservesSiteIsolation(bool $viaLink, int $rowCount): void
    {
        $table = 'mtest_quoted`table';
        $linkTable = 'mtest_quoted`link';
        $idColumn = 'item`id';
        $nameColumn = 'report.value';
        $id = Db::get()->quoteIdentifier([$idColumn]);
        $name = Db::get()->quoteIdentifier([$nameColumn]);
        $source = Db::get()->quoteIdentifier([Common::prefixTable($table)]);
        $sourceLink = Db::get()->quoteIdentifier([Common::prefixTable($linkTable)]);
        $target = Db::get()->quoteIdentifier([self::TARGET_PREFIX . $table]);
        $targetLink = Db::get()->quoteIdentifier([self::TARGET_PREFIX . $linkTable]);
        $created = [];
        try {
            foreach ([$source, $target] as $quotedTable) {
                Db::query("CREATE TABLE $quotedTable ($id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, idsite INT UNSIGNED, $name VARCHAR(100))");
                $created[] = $quotedTable;
            }
            foreach ([$sourceLink, $targetLink] as $quotedTable) {
                Db::query("CREATE TABLE $quotedTable ($id INT UNSIGNED, idsite INT UNSIGNED)");
                $created[] = $quotedTable;
            }
            $value = "report' OR 1=1 -- ";
            for ($row = 1; $row <= $rowCount; $row++) {
                Db::query("INSERT INTO $source ($id, idsite, $name) VALUES (?, ?, ?)", [$row, 1, $value]);
                Db::query("INSERT INTO $sourceLink ($id, idsite) VALUES (?, ?)", [$row, 1]);
            }
            Db::query("INSERT INTO $source ($id, idsite, $name) VALUES (?, ?, ?)", [$rowCount + 1, 2, 'other site']);
            Db::query("INSERT INTO $sourceLink ($id, idsite) VALUES (?, ?)", [$rowCount + 1, 2]);
            Db::query("INSERT INTO $target ($id, idsite, $name) VALUES (?, ?, ?)", [10000, 3, 'existing']);

            $definition = new PluginDefinition(self::PLUGIN_NAME);
            $items = $viaLink ? TableDefinition::forSiteVia($table, $linkTable, $idColumn) : TableDefinition::forSite($table);
            $definition->addTable($items->autoIncrement($idColumn));
            if ($viaLink) {
                $definition->addTable(TableDefinition::forSite($linkTable)->references($idColumn, $table));
            }
            $definitions = new PluginDefinitions();
            $definitions->add($definition);
            $migrator = new PluginDataMigrator($definitions);
            $this->assertSame([], $migrator->validate($this->targetDb, true));
            $request = new Request();
            $request->sourceIdSite = 1;
            $request->targetIdSite = 42;
            $request->idMaps->set('site', 1, 42);
            $counts = $migrator->migrateSiteData($request, $this->targetDb);

            $this->assertSame($rowCount, $counts[$table]['migrated']);
            $this->assertSame($rowCount, (int) Db::fetchOne("SELECT COUNT(*) FROM $target WHERE idsite = ? AND $name = ?", [42, $value]));
            $this->assertSame($rowCount + 1, (int) Db::fetchOne("SELECT COUNT(*) FROM $target"));
            $this->assertSame('existing', Db::fetchOne("SELECT $name FROM $target WHERE $id = ?", [10000]));
            $this->assertSame(10001, (int) $request->idMaps->get($table, 1));
            $this->assertSame($rowCount + 1, (int) Db::fetchOne("SELECT COUNT(*) FROM $source"));
            if ($viaLink) {
                $this->assertSame($rowCount, (int) Db::fetchOne("SELECT COUNT(*) FROM $targetLink WHERE idsite = ? AND $id > ?", [42, 10000]));
            }
        } finally {
            foreach (array_reverse($created) as $quotedTable) {
                Db::query("DROP TABLE $quotedTable");
            }
        }
    }

    public function getQuotedIdentifierCases(): array
    {
        return [
            'batched parents' => [false, 2],
            'paginated parent' => [false, 1001],
            'site link table' => [true, 2],
        ];
    }

    /**
     * @dataProvider getUniqueNamesAtColumnLimit
     */
    public function test_copySharedRowKeepsCollisionSuffixWithinColumnWidth(string $character): void
    {
        // Mirrors the real CustomReports name constraint, including the unique index.
        $source = Db::get()->quoteIdentifier([Common::prefixTable('mtest_shared')]);
        $target = $this->targetDb->quoteTable('mtest_shared');
        foreach ([$source, $target] as $table) {
            Db::query("ALTER TABLE $table ADD UNIQUE unique_name (`name`)");
        }
        $name = str_repeat($character, 50);
        $occupiedSuffix = str_repeat($character, 46) . ' (2)';
        $expectedName = str_repeat($character, 46) . ' (3)';
        Db::query("INSERT INTO $source (idshared, hash, name) VALUES (?, ?, ?)", [7, 111, $name]);
        Db::query("INSERT INTO $target (idshared, hash, name) VALUES (?, ?, ?)", [20, 222, $name]);
        Db::query("INSERT INTO $target (idshared, hash, name) VALUES (?, ?, ?)", [21, 333, $occupiedSuffix]);

        $tableDefinition = TableDefinition::shared('mtest_shared', 'idshared', ['hash', 'name'])->keepsUnique('name');
        $definition = new PluginDefinition(self::PLUGIN_NAME);
        $definition->addTable($tableDefinition);
        $definitions = new PluginDefinitions();
        $definitions->add($definition);
        $targetId = null;
        // A separate migration must reuse the matching suffixed row, not create another metric.
        for ($migration = 0; $migration < 2; $migration++) {
            $migrator = new PluginDataMigrator($definitions);
            $this->assertSame([], $migrator->validate($this->targetDb, true));
            $resolver = new \Piwik\Plugins\Migration\PluginData\IdResolver(
                new \Piwik\Plugins\Migration\PluginData\IdMaps(),
                $migrator,
                new \Piwik\Plugins\Migration\Migrations\LogActionMigration(),
                $this->targetDb
            );
            $copiedId = $migrator->copySharedRow($tableDefinition, 7, $resolver, $this->targetDb);
            if ($targetId !== null) {
                $this->assertSame((int) $targetId, (int) $copiedId);
            }
            $targetId = $copiedId;
        }

        $this->assertSame($expectedName, $this->targetDb->fetchOne("SELECT name FROM $target WHERE idshared = ?", [$targetId]));
        $this->assertSame(50, (int) $this->targetDb->fetchOne("SELECT CHAR_LENGTH(name) FROM $target WHERE idshared = ?", [$targetId]));
        $this->assertSame(3, (int) $this->targetDb->fetchOne("SELECT COUNT(*) FROM $target"));
        $this->assertSame($name, $this->targetDb->fetchOne("SELECT name FROM $target WHERE idshared = ?", [20]));
        $this->assertSame($occupiedSuffix, $this->targetDb->fetchOne("SELECT name FROM $target WHERE idshared = ?", [21]));
        $this->assertSame($name, Db::fetchOne("SELECT name FROM $source WHERE idshared = ?", [7]));
    }

    public function getUniqueNamesAtColumnLimit(): array
    {
        return ['ASCII' => ['x'], 'multibyte UTF-8' => ["\u{00E9}"]];
    }

    /**
     * @return string[]
     */
    public function getSiteKeys(int $idSite): array
    {
        return ['https://example.com/'];
    }

    /**
     * @return string[]
     */
    private function validate(PluginDefinition $definition): array
    {
        $definitions = new PluginDefinitions();
        $definitions->add($definition);
        return (new PluginDataMigrator($definitions))->validate($this->targetDb, true);
    }
}
