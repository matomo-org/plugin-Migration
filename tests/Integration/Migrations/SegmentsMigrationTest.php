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
        $this->definitions = new PluginDefinitions();
        $this->definitions->add($definition);
    }

    public function tearDown(): void
    {
        foreach (['mtest_segment_item', 'mtest_segment_report'] as $table) {
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
