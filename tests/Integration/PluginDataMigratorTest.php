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
use Piwik\Plugins\Migration\Migrations\Request;
use Piwik\Plugins\Migration\PluginData\PluginDataMigrator;
use Piwik\Plugins\Migration\PluginData\PluginDefinition;
use Piwik\Plugins\Migration\PluginData\PluginDefinitions;
use Piwik\Plugins\Migration\PluginData\TableDefinition;
use Piwik\Plugins\Migration\TargetDb;
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
