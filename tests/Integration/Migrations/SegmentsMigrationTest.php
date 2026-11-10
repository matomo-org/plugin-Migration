<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\tests\Integration\Migrations;

use Piwik\Common;
use Piwik\Db;
use Piwik\Plugins\Migration\Migrations;
use Piwik\Plugins\Migration\Migrations\LogActionMigration;
use Piwik\Plugins\Migration\Migrations\PluginDataMigration;
use Piwik\Plugins\Migration\Migrations\Request;
use Piwik\Plugins\Migration\Migrations\SegmentsMigration;
use Piwik\Plugins\Migration\PluginData\IdMaps;
use Piwik\Plugins\Migration\PluginData\PluginDataMigrator;
use Piwik\Plugins\Migration\PluginData\PluginDefinition;
use Piwik\Plugins\Migration\PluginData\PluginDefinitions;
use Piwik\Plugins\Migration\PluginData\TableDefinition;
use Piwik\Plugins\Migration\TargetDb;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * Segments must refer to the migrated plugin rows, including when source and target IDs overlap.
 * The synthetic plugin tables keep this coverage independent of licensed plugins.
 *
 * @group Migration
 * @group SegmentsMigrationTest
 * @group Plugins
 */
class SegmentsMigrationTest extends IntegrationTestCase
{
    private const TARGET_PREFIX = 'segment_target_';
    /** @var TargetDb */
    private $targetDb;

    /** @var PluginDefinitions */
    private $definitions;

    /** @var PluginDataMigrator */
    private $migrator;

    public function setUp(): void
    {
        parent::setUp();
        $this->targetDb = new TargetDb(['tables_prefix' => self::TARGET_PREFIX]);
        $tables = [
            'mtest_segment_item' => 'iditem INT UNSIGNED NOT NULL AUTO_INCREMENT, idsite INT UNSIGNED NOT NULL, name VARCHAR(50) NOT NULL, PRIMARY KEY (iditem)',
            'mtest_segment_report' => 'idreport INT UNSIGNED NOT NULL AUTO_INCREMENT, idsite INT UNSIGNED NOT NULL, idsegment INT UNSIGNED NOT NULL, name VARCHAR(50) NOT NULL, PRIMARY KEY (idreport)',
            'mtest_segment_visit' => 'idrow INT UNSIGNED NOT NULL AUTO_INCREMENT, idsite INT UNSIGNED NOT NULL, idvisit INT UNSIGNED NOT NULL, idsegment INT UNSIGNED NOT NULL, PRIMARY KEY (idrow)',
        ];
        foreach ($tables as $table => $columns) {
            Db::query('CREATE TABLE `' . Common::prefixTable($table) . '` (' . $columns . ')');
            Db::query('CREATE TABLE `' . self::TARGET_PREFIX . $table . '` (' . $columns . ')');
        }
        Db::query('CREATE TABLE `' . self::TARGET_PREFIX . 'segment` LIKE `' . Common::prefixTable('segment') . '`');

        $definition = new PluginDefinition('Migration');
        $definition->addTable(TableDefinition::forSite('mtest_segment_item')->autoIncrement('iditem')->identifiedBy('name'));
        $definition->addTable(TableDefinition::forSite('mtest_segment_report')->autoIncrement('idreport')->identifiedBy('name')->references('idsegment', TableDefinition::SEGMENT));
        $definition->identifiesSegmentsBy('mtestItem', 'mtest_segment_item');
        $definition->addTable(TableDefinition::childOf('mtest_segment_visit', TableDefinition::VISIT, 'idvisit')->autoIncrement('idrow')->references('idsegment', TableDefinition::SEGMENT));
        $this->definitions = new PluginDefinitions();
        $this->definitions->add($definition);
    }

    public function tearDown(): void
    {
        foreach (['mtest_segment_item', 'mtest_segment_report', 'mtest_segment_visit'] as $table) {
            Db::query('DROP TABLE IF EXISTS `' . Common::prefixTable($table) . '`');
            Db::query('DROP TABLE IF EXISTS `' . self::TARGET_PREFIX . $table . '`');
        }
        Db::query('DROP TABLE IF EXISTS `' . self::TARGET_PREFIX . 'segment`');
        parent::tearDown();
    }

    public function test_copyToSite_quotesTargetPrefixWhenReusingSegments(): void
    {
        $targetDb = new TargetDb(['tables_prefix' => 'segment_quoted`_']);
        $targetTable = $targetDb->quoteTable('segment');
        Db::query('CREATE TABLE ' . $targetTable . ' LIKE ' . $this->targetDb->quoteTable('segment'));
        try {
            $existing = $this->segment(500, 2, 'Existing target segment', 'visitCount==3');
            $targetDb->insert('segment', $existing);
            $source = $this->segment(100, 1, 'Source segment', 'visitCount==3');

            [$id, $copied] = SegmentsMigration::copyToSite($source, 2, new IdMaps(), $targetDb, [0, 2]);

            $this->assertEquals(500, $id);
            $this->assertFalse($copied);
            $this->assertSame(1, (int) $targetDb->fetchOne('SELECT COUNT(*) FROM ' . $targetTable));
            $this->assertSame(0, (int) $this->targetDb->fetchOne('SELECT COUNT(*) FROM ' . $this->targetDb->quoteTable('segment')));
        } finally {
            Db::query('DROP TABLE ' . $targetTable);
        }
    }

    /**
     * @dataProvider getSegmentScopes
     */
    public function test_migrate_doesNotReuseUntranslatedSegment(int $sourceSite, int $targetSite, bool $encoded): void
    {
        $this->insertSource('mtest_segment_item', ['iditem' => 3, 'idsite' => 1, 'name' => 'Migrated item']);
        $this->targetDb->insert('mtest_segment_item', ['iditem' => 3, 'idsite' => 2, 'name' => 'Unrelated target item']);
        $this->targetDb->insert('mtest_segment_item', ['iditem' => 6, 'idsite' => 99, 'name' => 'Another site item']);
        $this->insertSource('segment', $this->segment(100, $sourceSite, 'Source segment', $this->definition(3, $encoded)));
        $this->targetDb->insert('segment', $this->segment(500, $targetSite, 'Existing target segment', $this->definition(3, $encoded)));
        $this->insertSource('mtest_segment_report', ['idreport' => 10, 'idsite' => 1, 'idsegment' => 100, 'name' => 'Migrated report']);

        $request = $this->request();
        $this->migrate($request);

        $this->assertEquals(7, $request->idMaps->get('mtest_segment_item', 3));
        $this->assertSame($this->definition(3, $encoded), $this->targetDb->fetchOne('SELECT definition FROM segment_target_segment WHERE idsegment = 500'));
        $this->assertSame(
            ['Migrated report' => $this->definition(7, $encoded)],
            $this->reportDefinitions(),
            'The source item became target item 7; the migrated report must not reuse the existing filter for unrelated target item 3.'
        );
    }

    /**
     * @dataProvider getSourceSegmentScopes
     */
    public function test_migrate_keepsDistinctTranslatedSegments(int $sourceSite, bool $encoded): void
    {
        $this->insertSource('mtest_segment_item', ['iditem' => 1, 'idsite' => 1, 'name' => 'First item']);
        $this->insertSource('mtest_segment_item', ['iditem' => 2, 'idsite' => 1, 'name' => 'Second item']);
        $this->targetDb->insert('mtest_segment_item', ['iditem' => 1, 'idsite' => 99, 'name' => 'Another site item']);
        $this->insertSource('segment', $this->segment(101, $sourceSite, 'First segment', $this->definition(1, $encoded)));
        $this->insertSource('segment', $this->segment(102, $sourceSite, 'Second segment', $this->definition(2, $encoded)));
        $this->insertSource('mtest_segment_report', ['idreport' => 10, 'idsite' => 1, 'idsegment' => 101, 'name' => 'First report']);
        $this->insertSource('mtest_segment_report', ['idreport' => 11, 'idsite' => 1, 'idsegment' => 102, 'name' => 'Second report']);

        $request = $this->request();
        $this->migrate($request);

        $this->assertEquals(2, $request->idMaps->get('mtest_segment_item', 1));
        $this->assertEquals(3, $request->idMaps->get('mtest_segment_item', 2));
        $this->assertSame(
            ['First report' => $this->definition(2, $encoded), 'Second report' => $this->definition(3, $encoded)],
            $this->reportDefinitions(),
            'The first translated definition must not merge with the second copy while it still contains its source ID.'
        );
    }

    /** @dataProvider getUnresolvedSegmentCases */
    public function test_unresolvedPluginSegmentsFailWithoutChangingTarget(bool $skipPlugin, bool $dryRun, string $definition, bool $global): void
    {
        if ($skipPlugin) {
            $this->insertSource('mtest_segment_item', ['iditem' => 79, 'idsite' => 1, 'name' => 'Skipped item']);
        }
        $this->targetDb->insert('mtest_segment_item', ['iditem' => 79, 'idsite' => 99, 'name' => 'Another site item']);
        $this->targetDb->insert('segment', $this->segment(500, 2, 'Existing segment', $definition));
        $this->insertSource('segment', $this->segment(100, $global ? 0 : 1, 'Source segment', $definition));
        $definitions = $this->definitions;
        if ($global) {
            // Another migrated plugin can pull in a global segment even if this plugin is skipped.
            $reports = new PluginDefinition('CoreHome');
            $reports->addTable(TableDefinition::forSite('mtest_segment_report')->autoIncrement('idreport')->references('idsegment', TableDefinition::SEGMENT));
            $definitions = new PluginDefinitions();
            $items = new PluginDefinition('Migration');
            $items->addTable(TableDefinition::forSite('mtest_segment_item')->autoIncrement('iditem'));
            $items->identifiesSegmentsBy('mtestItem', 'mtest_segment_item');
            $definitions->add($items);
            $definitions->add($reports);
            $this->insertSource('mtest_segment_report', ['idreport' => 10, 'idsite' => 1, 'idsegment' => 100, 'name' => 'Report with global segment']);
        }
        $sourceBefore = Db::fetchAll('SELECT * FROM ' . Common::prefixTable('segment') . ' ORDER BY idsegment');
        $targetBefore = $this->targetDb->getDb()->fetchAll('SELECT * FROM segment_target_segment ORDER BY idsegment');
        if ($dryRun) {
            $this->targetDb->enableDryRun();
        }
        $migrator = new PluginDataMigrator($definitions, $skipPlugin ? ['Migration'] : []);
        $failure = null;
        try {
            (new Migrations())->migrate([new SegmentsMigration(), new PluginDataMigration($migrator, false)], $this->request(), $this->targetDb);
        } catch (\RuntimeException $error) {
            $failure = $error;
        }
        $this->assertNotNull($failure, 'A missing mapping must not keep a source ID that selects unrelated destination data.');
        $this->assertStringContainsString('missing or excluded plugin data', $failure->getMessage());
        $this->assertStringContainsString('mtestItem', $failure->getMessage());
        $this->assertStringContainsString('79', $failure->getMessage());
        $this->assertSame($targetBefore, $this->targetDb->getDb()->fetchAll('SELECT * FROM segment_target_segment ORDER BY idsegment'));
        $this->assertSame($sourceBefore, Db::fetchAll('SELECT * FROM ' . Common::prefixTable('segment') . ' ORDER BY idsegment'));
        $this->assertSame(0, (int) $this->targetDb->fetchOne('SELECT COUNT(*) FROM segment_target_mtest_segment_report'));
        $this->assertSame(1, (int) $this->targetDb->fetchOne('SELECT COUNT(*) FROM segment_target_mtest_segment_item'));
    }

    /** @dataProvider getDeletedSegmentCases */
    public function test_deletedPluginSegmentsDoNotBlockMigration(bool $global, bool $dryRun, bool $skipPlugin, bool $hasItem, bool $encoded): void
    {
        if ($hasItem) {
            $this->insertSource('mtest_segment_item', ['iditem' => 79, 'idsite' => 1, 'name' => 'Source item']);
        }
        $this->targetDb->insert('mtest_segment_item', ['iditem' => 79, 'idsite' => 99, 'name' => 'Unrelated target item']);
        $definition = $this->definition(79, $encoded);
        $deleted = $this->segment(100, $global ? 0 : 1, 'Deleted source segment', $definition);
        $deleted['deleted'] = 1;
        $this->insertSource('segment', $deleted);
        $this->insertSource('segment', $this->segment(101, 1, 'Live source segment', 'visitCount==1'));
        $this->targetDb->insert('segment', $this->segment(500, 2, 'Live target segment', $definition));
        $this->insertSource('mtest_segment_report', ['idreport' => 10, 'idsite' => 1, 'idsegment' => 100, 'name' => 'Report with deleted segment']);

        // References from another plugin still pull in a global segment when the selected plugin is skipped.
        $items = new PluginDefinition('Migration');
        $items->addTable(TableDefinition::forSite('mtest_segment_item')->autoIncrement('iditem'));
        $items->identifiesSegmentsBy('mtestItem', 'mtest_segment_item');
        $reports = new PluginDefinition('CoreHome');
        $reports->addTable(TableDefinition::forSite('mtest_segment_report')->autoIncrement('idreport')->references('idsegment', TableDefinition::SEGMENT));
        $definitions = new PluginDefinitions();
        $definitions->add($items);
        $definitions->add($reports);
        $sourceBefore = Db::fetchAll('SELECT * FROM ' . Common::prefixTable('segment') . ' ORDER BY idsegment');
        $targetBefore = $this->targetDb->getDb()->fetchAll('SELECT * FROM segment_target_segment ORDER BY idsegment');
        if ($dryRun) {
            $this->targetDb->enableDryRun();
        }
        $request = $this->request();
        $migrator = new PluginDataMigrator($definitions, $skipPlugin ? ['Migration'] : []);
        (new Migrations())->migrate([new SegmentsMigration(), new PluginDataMigration($migrator, false)], $request, $this->targetDb);

        $this->assertSame($sourceBefore, Db::fetchAll('SELECT * FROM ' . Common::prefixTable('segment') . ' ORDER BY idsegment'));
        $this->assertNotNull($request->idMaps->get(TableDefinition::SEGMENT, 100));
        if ($dryRun) {
            $this->assertSame($targetBefore, $this->targetDb->getDb()->fetchAll('SELECT * FROM segment_target_segment ORDER BY idsegment'));
            $this->assertSame(0, (int) $this->targetDb->fetchOne('SELECT COUNT(*) FROM segment_target_mtest_segment_report'));
            return;
        }
        $copy = $this->targetDb->fetchRow('SELECT * FROM segment_target_segment WHERE idsegment = ?', [$request->idMaps->get(TableDefinition::SEGMENT, 100)]);
        $this->assertSame(1, (int) $copy['deleted']);
        $this->assertSame($definition, $copy['definition']);
        $this->assertSame($deleted['hash'], $copy['hash']);
        $this->assertSame(['Report with deleted segment' => $definition], $this->reportDefinitions());
        $this->assertSame($targetBefore[0], $this->targetDb->fetchRow('SELECT * FROM segment_target_segment WHERE idsegment = 500'));
        $this->assertSame('visitCount==1', $this->targetDb->fetchOne('SELECT definition FROM segment_target_segment WHERE idsegment = ?', [$request->idMaps->get(TableDefinition::SEGMENT, 101)]));
    }

    public static function getDeletedSegmentCases(): array
    {
        return [
            'missing site item' => [false, false, false, false, false],
            'missing site item dry run' => [false, true, false, false, false],
            'missing global item' => [true, false, false, false, false],
            'missing global item dry run' => [true, true, false, false, false],
            'skipped plugin' => [false, false, true, true, false],
            'skipped global plugin dry run' => [true, true, true, true, false],
            'encoded site' => [false, false, false, false, true],
            'encoded global' => [true, false, false, false, true],
            'mapped site item stays deleted' => [false, false, false, true, false],
            'mapped global item stays deleted' => [true, false, false, true, false],
        ];
    }

    public function test_visitBatchKeepsSiteSegmentMappingAfterCopyingGlobalSegment(): void
    {
        $this->insertSource('segment', $this->segment(100, 0, 'Global segment', 'visitCount==2'));
        $this->insertSource('segment', $this->segment(101, 1, 'Site segment', 'visitCount==3'));
        $this->targetDb->insert('segment', $this->segment(500, 99, 'Other site segment', 'visitCount==4'));
        $this->insertSource('mtest_segment_visit', ['idrow' => 1, 'idsite' => 1, 'idvisit' => 50, 'idsegment' => 100]);
        $this->insertSource('mtest_segment_visit', ['idrow' => 2, 'idsite' => 1, 'idvisit' => 50, 'idsegment' => 101]);
        $request = $this->request();
        $this->migrate($request);
        $siteSegmentId = $request->idMaps->get(TableDefinition::SEGMENT, 101);

        $this->migrator->migrateVisitData($request, $this->targetDb, [50 => 150], [], new LogActionMigration());

        $this->assertSame(['migrated' => 2, 'skipped' => 0, 'existing' => 0], $this->migrator->getVisitDataCounts()['mtest_segment_visit']);
        $rows = $this->targetDb->getDb()->fetchAll('SELECT v.idsite, v.idvisit, v.idsegment, s.definition FROM segment_target_mtest_segment_visit v JOIN segment_target_segment s ON v.idsegment = s.idsegment ORDER BY v.idrow');
        $this->assertCount(2, $rows);
        $this->assertSame(['visitCount==2', 'visitCount==3'], array_column($rows, 'definition'));
        $this->assertEquals([2, 2], array_column($rows, 'idsite'));
        $this->assertEquals([150, 150], array_column($rows, 'idvisit'));
        $this->assertEquals($siteSegmentId, $rows[1]['idsegment']);
        $this->assertSame($siteSegmentId, $request->idMaps->get(TableDefinition::SEGMENT, 101));
    }

    public static function getUnresolvedSegmentCases(): array
    {
        return [
            'skipped plugin' => [true, false, 'mtestItem==79', false],
            'skipped plugin dry run' => [true, true, 'mtestItem==79', false],
            'missing row' => [false, false, 'mtestItem!=79', false],
            'missing row dry run' => [false, true, 'mtestItem!=79', false],
            'encoded condition' => [true, false, 'visitCount>1;mtestItem%3D%3D79', false],
            'encoded definition dry run' => [true, true, urlencode('visitCount>1;mtestItem==79'), false],
            'global segment from another plugin' => [true, false, 'mtestItem==79', true],
            'global segment dry run' => [true, true, 'mtestItem==79', true],
        ];
    }

    public static function getSegmentScopes(): array
    {
        return [
            'site' => [1, 2, false],
            'global source, site target' => [0, 2, false],
            'global source and target' => [0, 0, false],
            'encoded site' => [1, 2, true],
        ];
    }

    public static function getSourceSegmentScopes(): array
    {
        return [
            'site' => [1, false],
            'global' => [0, false],
            'encoded site' => [1, true],
            'encoded global' => [0, true],
        ];
    }

    private function definition(int $id, bool $encoded): string
    {
        $definition = 'mtestItem==' . $id;
        return $encoded ? urlencode($definition) : $definition;
    }

    private function request(): Request
    {
        $request = new Request();
        $request->sourceIdSite = 1;
        $request->targetIdSite = 2;
        $request->idMaps->set(TableDefinition::SITE, 1, 2);
        return $request;
    }

    private function migrate(Request $request): void
    {
        $this->migrator = new PluginDataMigrator($this->definitions);
        (new SegmentsMigration())->migrate($request, $this->targetDb);
        $this->migrator->migrateSiteData($request, $this->targetDb);
        $this->migrator->pointSegmentsToTargetIds($request, $this->targetDb);
    }

    private function segment(int $id, int $site, string $name, string $definition): array
    {
        return [
            'idsegment' => $id,
            'name' => $name,
            'definition' => $definition,
            'hash' => md5(urldecode($definition)),
            'login' => 'test-user',
            'enable_all_users' => 0,
            'enable_only_idsite' => $site,
            'auto_archive' => 0,
            'ts_created' => '2013-01-01 00:00:00',
            'deleted' => 0,
        ];
    }

    private function insertSource(string $table, array $row): void
    {
        Db::get()->insert(Common::prefixTable($table), $row);
    }

    private function reportDefinitions(): array
    {
        $rows = $this->targetDb->getDb()->fetchAll(
            'SELECT r.name, s.definition FROM segment_target_mtest_segment_report r '
            . 'JOIN segment_target_segment s ON s.idsegment = r.idsegment ORDER BY r.name'
        );
        return array_column($rows, 'definition', 'name');
    }
}
