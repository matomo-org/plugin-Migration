<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\tests\Integration;

use Piwik\Common;
use Piwik\Config;
use Piwik\Db;
use Piwik\Plugins\Migration\Migrations;
use Piwik\Plugins\Migration\Migrations\PluginDataMigration;
use Piwik\Plugins\Migration\Migrations\Request;
use Piwik\Plugins\Migration\PluginData\Definitions\AbTesting;
use Piwik\Plugins\Migration\PluginData\Definitions\Funnels;
use Piwik\Plugins\Migration\PluginData\PluginDataMigrator;
use Piwik\Plugins\Migration\PluginData\PluginDefinition;
use Piwik\Plugins\Migration\PluginData\PluginDefinitions;
use Piwik\Plugins\Migration\TargetDb;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * Uses the real built-in definitions with synthetic tables, without requiring licensed plugins.
 *
 * @group Migration
 * @group ExistingPluginConfigurationTest
 * @group Plugins
 */
class ExistingPluginConfigurationTest extends IntegrationTestCase
{
    private const SOURCE_PREFIX = 'configuration_source_';
    private const TARGET_PREFIX = 'configuration_target_';

    private const TABLES = [
        'funnel' => 'idfunnel INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, idsite INT UNSIGNED NOT NULL, idgoal INT NOT NULL DEFAULT 0, name VARCHAR(50) NULL, created_date DATETIME NOT NULL, revision INT NOT NULL DEFAULT 0',
        'funnel_steps' => 'idfunnel INT UNSIGNED NOT NULL, position INT NOT NULL, name VARCHAR(50) NOT NULL, pattern VARCHAR(100) NOT NULL, pattern_type VARCHAR(20) NOT NULL, required INT NOT NULL, PRIMARY KEY (idfunnel, position)',
        'experiments' => 'idexperiment INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, idsite INT UNSIGNED NOT NULL, name VARCHAR(50) NOT NULL, success_metrics TEXT NOT NULL, percentage_participants INT NOT NULL DEFAULT 100',
        'experiments_variations' => 'idvariation INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, idexperiment INT UNSIGNED NOT NULL, name VARCHAR(50) NOT NULL, percentage INT NULL, deleted INT NOT NULL DEFAULT 0',
    ];

    /** @var TargetDb */
    private $targetDb;

    /** @var string[] */
    private $createdTables = [];

    /** @var string */
    private $originalPrefix;

    public function setUp(): void
    {
        parent::setUp();
        $this->originalPrefix = Common::prefixTable('');
        $this->setSourcePrefix(self::SOURCE_PREFIX);
        $this->targetDb = new TargetDb(['tables_prefix' => self::TARGET_PREFIX]);
        foreach (self::TABLES as $table => $columns) {
            foreach ([Common::prefixTable($table), self::TARGET_PREFIX . $table] as $prefixedTable) {
                Db::query('CREATE TABLE `' . $prefixedTable . '` (' . $columns . ')');
                $this->createdTables[] = $prefixedTable;
            }
        }
    }

    public function tearDown(): void
    {
        try {
            $this->setSourcePrefix($this->originalPrefix);
            foreach (array_reverse($this->createdTables) as $table) {
                Db::query('DROP TABLE IF EXISTS `' . $table . '`');
            }
        } finally {
            // PHPUnit retains test instances; release the secondary connection explicitly.
            if ($this->targetDb !== null) {
                $this->targetDb->getDb()->closeConnection();
                $this->targetDb = null;
            }
            parent::tearDown();
        }
    }

    private function setSourcePrefix(string $prefix): void
    {
        $database = Config::getInstance()->database;
        $database['tables_prefix'] = $prefix;
        Config::getInstance()->database = $database;
    }

    /** @dataProvider getChildMembershipCases */
    public function test_reusedConfigurationPreservesItsCompleteChildSet(string $plugin, int $sourceCount, int $targetCount, bool $dryRun): void
    {
        $isFunnel = $plugin === 'Funnels';
        $parentTable = $isFunnel ? 'funnel' : 'experiments';
        $childTable = $isFunnel ? 'funnel_steps' : 'experiments_variations';
        $parentId = $isFunnel ? 'idfunnel' : 'idexperiment';
        $parent = $isFunnel ? ['idgoal' => 0, 'created_date' => '2020-01-01 00:00:00', 'revision' => 0]
            : ['success_metrics' => '[]', 'percentage_participants' => 100];
        Db::get()->insert(Common::prefixTable($parentTable), array_merge($parent, [$parentId => 7, 'idsite' => 1, 'name' => 'Existing definition']));
        $this->targetDb->insert($parentTable, array_merge($parent, [$parentId => 79, 'idsite' => 2, 'name' => 'Existing definition']));
        // This parent is inserted before any child conflict. The real migration runner must roll it back too.
        Db::get()->insert(Common::prefixTable($parentTable), array_merge($parent, [$parentId => 1, 'idsite' => 1, 'name' => 'New definition']));
        for ($position = 1; $position <= max($sourceCount, $targetCount); ++$position) {
            $child = $isFunnel ? ['position' => $position, 'pattern' => '/page-' . $position, 'pattern_type' => 'url_equals', 'required' => 1]
                : ['percentage' => 25, 'deleted' => 0];
            $child['name'] = 'Child ' . $position;
            if ($position <= $sourceCount) {
                $source = array_merge($child, [$parentId => 7]);
                if (!$isFunnel) {
                    $source['idvariation'] = 100 + $position;
                }
                Db::get()->insert(Common::prefixTable($childTable), $source);
            }
            if ($position <= $targetCount) {
                $target = array_merge($child, [$parentId => 79]);
                if (!$isFunnel) {
                    $target['idvariation'] = 200 + $position;
                }
                $this->targetDb->insert($childTable, $target);
            }
        }
        $newChild = $isFunnel ? ['position' => 1, 'name' => 'New step', 'pattern' => '/new', 'pattern_type' => 'url_equals', 'required' => 1]
            : ['idvariation' => 500, 'name' => 'New variation', 'percentage' => 25, 'deleted' => 0];
        Db::get()->insert(Common::prefixTable($childTable), array_merge($newChild, [$parentId => 1]));

        $beforeParent = $this->targetDb->fetchAll('SELECT * FROM ' . $this->targetDb->quoteTable($parentTable));
        $beforeChildren = $this->targetDb->fetchAll('SELECT * FROM ' . $this->targetDb->quoteTable($childTable) . ' ORDER BY name');
        $definition = new PluginDefinition('Migration');
        foreach (($isFunnel ? Funnels::get() : AbTesting::get())->getTables() as $table) {
            if (in_array($table->getTable(), [$parentTable, $childTable], true)) {
                $definition->addTable($table);
            }
        }
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
        $exception = null;
        try {
            (new Migrations())->migrate([new PluginDataMigration($migrator, false)], $request, $this->targetDb);
        } catch (\RuntimeException $e) {
            $exception = $e;
        }

        if ($sourceCount > $targetCount) {
            $this->assertNotNull($exception, 'A source-only child must not be appended to existing destination configuration.');
            $this->assertStringContainsString('Cannot migrate ' . $childTable, $exception->getMessage());
            $this->assertStringContainsString('existing destination ' . $parentTable . ' ID 79', $exception->getMessage());
            $this->assertSame($beforeParent, $this->targetDb->fetchAll('SELECT * FROM ' . $this->targetDb->quoteTable($parentTable)));
        } else {
            $this->assertNull($exception);
            $this->assertSame($sourceCount < $targetCount, $request->archivesNotCopiedReason !== null);
            $this->assertEquals(79, $request->idMaps->get($parentTable, 7));
            if (!$isFunnel && $sourceCount > 0) {
                $this->assertEquals(201, $request->idMaps->get($childTable, 101));
            }
            $this->assertSame($beforeParent[0], $this->targetDb->fetchRow('SELECT * FROM ' . $this->targetDb->quoteTable($parentTable) . ' WHERE ' . $parentId . ' = 79'));
            $this->assertEquals($dryRun ? 1 : 2, $this->targetDb->fetchOne('SELECT COUNT(*) FROM ' . $this->targetDb->quoteTable($parentTable)));
            $newTargetId = $request->idMaps->get($parentTable, 1);
            $this->assertEquals($dryRun ? 0 : 1, $this->targetDb->fetchOne('SELECT COUNT(*) FROM ' . $this->targetDb->quoteTable($childTable) . ' WHERE ' . $parentId . ' = ?', [$newTargetId]));
        }
        $this->assertSame($beforeChildren, $this->targetDb->fetchAll('SELECT * FROM ' . $this->targetDb->quoteTable($childTable) . ' WHERE ' . $parentId . ' = 79 ORDER BY name'));
    }

    public function test_sourceParentsDeduplicatedToANewDefinitionCanBothAddChildren(): void
    {
        foreach ([7 => 1, 8 => 2] as $idFunnel => $position) {
            Db::get()->insert(Common::prefixTable('funnel'), [
                'idfunnel' => $idFunnel, 'idsite' => 1, 'idgoal' => 0, 'name' => 'Same source identity',
                'created_date' => '2020-01-01 00:00:00', 'revision' => 0,
            ]);
            Db::get()->insert(Common::prefixTable('funnel_steps'), [
                'idfunnel' => $idFunnel, 'position' => $position, 'name' => 'Step ' . $position,
                'pattern' => '/page-' . $position, 'pattern_type' => 'url_equals', 'required' => 1,
            ]);
        }
        $definition = new PluginDefinition('Migration');
        foreach (Funnels::get()->getTables() as $table) {
            if (in_array($table->getTable(), ['funnel', 'funnel_steps'], true)) {
                $definition->addTable($table);
            }
        }
        $definitions = new PluginDefinitions();
        $definitions->add($definition);
        $migrator = new PluginDataMigrator($definitions);
        $this->assertSame([], $migrator->validate($this->targetDb, true));
        $request = new Request();
        $request->sourceIdSite = 1;
        $request->targetIdSite = 2;
        $request->isExistingTargetSite = true;
        $request->idMaps->set('site', 1, 2);

        (new Migrations())->migrate([new PluginDataMigration($migrator, false)], $request, $this->targetDb);

        $targetId = $request->idMaps->get('funnel', 7);
        $this->assertNotNull($targetId);
        $this->assertEquals($targetId, $request->idMaps->get('funnel', 8));
        $this->assertEquals(1, $this->targetDb->fetchOne('SELECT COUNT(*) FROM ' . $this->targetDb->quoteTable('funnel')));
        $this->assertEquals([
            ['idfunnel' => $targetId, 'position' => 1, 'name' => 'Step 1'],
            ['idfunnel' => $targetId, 'position' => 2, 'name' => 'Step 2'],
        ], $this->targetDb->fetchAll('SELECT idfunnel, position, name FROM ' . $this->targetDb->quoteTable('funnel_steps') . ' ORDER BY position'));
        $this->assertNull($request->archivesNotCopiedReason);
    }

    public static function getChildMembershipCases(): array
    {
        $cases = [];
        foreach (['Funnels', 'AbTesting'] as $plugin) {
            foreach ([false, true] as $dryRun) {
                foreach (['source-only child' => [3, 2], 'target-only child' => [2, 3], 'no source children' => [0, 2], 'equivalent children' => [2, 2]] as $name => [$source, $target]) {
                    $cases[$plugin . ' ' . $name . ($dryRun ? ' dry run' : '')] = [$plugin, $source, $target, $dryRun];
                }
            }
        }
        return $cases;
    }
}
