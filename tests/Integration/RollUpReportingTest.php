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
use Piwik\Option;
use Piwik\Plugins\Migration\Migrations\Request;
use Piwik\Plugins\Migration\PluginData\Definitions\RollUpReporting;
use Piwik\Plugins\Migration\TargetDb;
use Piwik\SettingsPiwik;
use Piwik\Site;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * Exercises the migration definition with synthetic membership tables; no licensed plugin is needed.
 *
 * @group Migration
 * @group RollUpReportingTest
 * @group Plugins
 */
class RollUpReportingTest extends IntegrationTestCase
{
    private const TARGET_PREFIX = 'migration_rollup_target_';
    private const OTHER_PREFIX = 'migration_rollup_other_';

    private TargetDb $targetDb;
    private string $originalPrefix;
    private string $originalDatabase;
    private ?string $otherDatabase = null;

    /**
     * @var string[]
     */
    private array $createdTables = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->originalPrefix = Common::prefixTable('');
        $this->originalDatabase = (string) Db::fetchOne('SELECT DATABASE()');
        $sourceRollUp = Common::prefixTable('site_rollup');
        Db::query("CREATE TABLE `$sourceRollUp` (parent_idsite INT UNSIGNED NOT NULL, idsite INT UNSIGNED NOT NULL, PRIMARY KEY (parent_idsite, idsite))");
        $this->createdTables[] = $sourceRollUp;

        $this->targetDb = new TargetDb(['tables_prefix' => self::TARGET_PREFIX]);
        foreach (['site', 'site_rollup', 'option', 'plugin_setting'] as $table) {
            $sourceTable = Common::prefixTable($table);
            $targetTable = $this->targetDb->prefixTable($table);
            Db::query("CREATE TABLE `$targetTable` LIKE `$sourceTable`");
            $this->createdTables[] = $targetTable;
        }
    }

    public function tearDown(): void
    {
        $this->restoreSource();
        foreach (array_reverse($this->createdTables) as $table) {
            Db::query("DROP TABLE IF EXISTS `$table`");
        }
        if ($this->otherDatabase !== null) {
            Db::query("DROP DATABASE `{$this->otherDatabase}`");
        }
        parent::tearDown();
    }

    /**
     * @dataProvider getIndependentSources
     */
    public function test_mappingsKeepSourcesWithTheSameSaltSeparate(string $sourceKind): void
    {
        $sourceSite = $this->createSourceSite('Source A website');
        $sourceRollUp = $this->createSourceSite('Source A roll-up', false, 'rollup');
        $this->addSourceMembership($sourceRollUp, $sourceSite);
        $otherSite = Db::fetchRow('SELECT * FROM ' . Common::prefixTable('site') . ' WHERE idsite = ?', [$sourceSite]);
        $otherSite['name'] = 'Source B website';

        [$targetA] = $this->migrateRollUpStep($sourceSite);
        $this->switchToOtherSource($sourceKind);
        try {
            Db::get()->insert(Common::prefixTable('site'), $otherSite);
            [$targetB] = $this->migrateRollUpStep($sourceSite);
        } finally {
            $this->restoreSource();
        }
        [$targetRollUp] = $this->migrateRollUpStep($sourceRollUp);

        $this->assertNotSame($targetA, $targetB);
        $this->assertSame([$targetA], $this->targetMembers($targetRollUp));
        // Reusing a target site must keep the same source identity and must not duplicate the membership.
        $this->migrateRollUpStep($sourceSite, $targetA);
        $this->assertSame([$targetA], $this->targetMembers($targetRollUp));
    }

    public function getIndependentSources(): array
    {
        return [['prefix'], ['database']];
    }

    public function test_lateDescendantResynchronizesAllAncestorSettingsAndReports(): void
    {
        $d = $this->createSourceSite('D: non-ecommerce website', false, 'website', '2015-01-01');
        $c = $this->createSourceSite('C: ecommerce website', true, 'website', '2010-01-01');
        $a = $this->createSourceSite('A: ancestor roll-up', true, 'rollup', '2020-01-01');
        $b = $this->createSourceSite('B: nested roll-up', true, 'rollup', '2020-01-01');
        $this->addSourceMembership($a, $b);
        $this->addSourceMembership($a, $d);
        $this->addSourceMembership($b, $c);

        [$targetD] = $this->migrateRollUpStep($d);
        [$targetA] = $this->migrateRollUpStep($a);
        $this->assertSame(['ecommerce' => 0, 'sitesearch' => 0, 'ts_created' => '2015-01-01 00:00:00'], $this->targetSettings($targetA));
        [$targetB] = $this->migrateRollUpStep($b);
        [$targetC, $messages] = $this->migrateRollUpStep($c);

        $this->assertSame([$targetD, $targetB], $this->targetMembers($targetA));
        $this->assertSame([$targetC], $this->targetMembers($targetB));
        $expected = ['ecommerce' => 1, 'sitesearch' => 1, 'ts_created' => '2010-01-01 00:00:00'];
        $this->assertSame($expected, $this->targetSettings($targetB));
        $this->assertSame($expected, $this->targetSettings($targetA));
        $this->assertStringContainsString("--sites=$targetB,$targetA --dates=", implode("\n", $messages));

        // Existing memberships need the same propagation when another migration changes the member site's settings.
        $this->targetDb->update('site', ['ecommerce' => 0, 'sitesearch' => 0], ['idsite' => $targetC]);
        $this->migrateRollUpStep($c, $targetC);
        $this->assertSame(0, $this->targetSettings($targetA)['ecommerce']);
        $this->assertSame(0, $this->targetSettings($targetA)['sitesearch']);
    }

    public function test_legacyMappingsAreNotReusedAndCanBeReestablishedExplicitly(): void
    {
        $sourceSite = $this->createSourceSite('Previously migrated website');
        $sourceRollUp = $this->createSourceSite('Roll-up', false, 'rollup');
        $this->addSourceMembership($sourceRollUp, $sourceSite);
        $targetSite = $this->copySite($sourceSite);
        $legacyName = 'MigrationSourceSite_' . substr(hash('sha256', 'Migration' . SettingsPiwik::getSalt()), 0, 16) . '_' . $sourceSite;
        $this->targetDb->insert('option', ['option_name' => $legacyName, 'option_value' => $targetSite, 'autoload' => 0]);

        [$targetRollUp, $messages] = $this->migrateRollUpStep($sourceRollUp);

        $this->assertSame([], $this->targetMembers($targetRollUp));
        $this->assertStringContainsString('cannot be reused safely', implode("\n", $messages));
        $this->assertStringContainsString('Reconfigure those roll-up memberships', implode("\n", $messages));
        $this->assertStringContainsString('after verifying the source and target site IDs', implode("\n", $messages));

        $this->migrateRollUpStep($sourceSite, $targetSite);
        [, $messages] = $this->migrateRollUpStep($sourceRollUp, $targetRollUp);
        $this->assertSame([$targetSite], $this->targetMembers($targetRollUp));
        $this->assertStringNotContainsString('cannot be reused safely', implode("\n", $messages));
        $this->assertSame(2, (int) $this->targetDb->fetchOne('SELECT COUNT(*) FROM ' . self::TARGET_PREFIX . 'site'));
    }

    public function test_nestedRollUpCyclesTerminateAndSynchronizeEachAncestorOnce(): void
    {
        $c = $this->createSourceSite('Website', true);
        $a = $this->createSourceSite('A: roll-up', false, 'rollup');
        $b = $this->createSourceSite('B: roll-up', false, 'rollup');
        $this->addSourceMembership($a, $b);
        $this->addSourceMembership($b, $a);
        $this->addSourceMembership($b, $c);

        [$targetA] = $this->migrateRollUpStep($a);
        [$targetB] = $this->migrateRollUpStep($b);
        [$targetC, $messages] = $this->migrateRollUpStep($c);

        $this->assertSame([$targetB], $this->targetMembers($targetA));
        $this->assertSame([$targetA, $targetC], $this->targetMembers($targetB));
        $this->assertSame(1, $this->targetSettings($targetA)['ecommerce']);
        $this->assertSame(1, $this->targetSettings($targetB)['ecommerce']);
        $this->assertStringContainsString("--sites=$targetB,$targetA --dates=", implode("\n", $messages));
    }

    private function switchToOtherSource(string $sourceKind): void
    {
        $prefix = $this->originalPrefix;
        $database = $this->originalDatabase;
        if ($sourceKind === 'database') {
            $database .= '_rollup_other';
            Db::query("CREATE DATABASE `$database`");
            $this->otherDatabase = $database;
        } else {
            $prefix = self::OTHER_PREFIX;
        }
        foreach (['site', 'site_rollup', 'option', 'plugin_setting'] as $table) {
            $sourceTable = Common::prefixTable($table);
            $otherTable = $prefix . $table;
            Db::query("CREATE TABLE `$database`.`$otherTable` LIKE `{$this->originalDatabase}`.`$sourceTable`");
            if ($this->otherDatabase === null) {
                $this->createdTables[] = $otherTable;
            }
        }
        // Deliberately keep the configured database name: identity must use the actual source connection.
        Db::query("USE `$database`");
        $this->setSourcePrefix($prefix);
    }

    private function restoreSource(): void
    {
        Db::query("USE `{$this->originalDatabase}`");
        $this->setSourcePrefix($this->originalPrefix);
    }

    private function createSourceSite(string $name, bool $ecommerce = false, string $type = 'website', string $date = '2013-01-01'): int
    {
        $id = (int) Fixture::createWebsite($date . ' 00:00:00', $ecommerce ? 1 : 0, $name);
        Db::query('UPDATE ' . Common::prefixTable('site') . ' SET `type` = ?, sitesearch = ?, ts_created = ? WHERE idsite = ?', [$type, $ecommerce ? 1 : 0, $date . ' 00:00:00', $id]);
        return $id;
    }

    private function addSourceMembership(int $parent, int $child): void
    {
        Db::get()->insert(Common::prefixTable('site_rollup'), ['parent_idsite' => $parent, 'idsite' => $child]);
    }

    private function copySite(int $sourceId): int
    {
        $row = Db::fetchRow('SELECT * FROM ' . Common::prefixTable('site') . ' WHERE idsite = ?', [$sourceId]);
        unset($row['idsite']);
        return (int) $this->targetDb->insert('site', $row);
    }

    /**
     * @return array{int, string[]}
     */
    private function migrateRollUpStep(int $sourceId, ?int $targetId = null): array
    {
        $targetId = $targetId ?? $this->copySite($sourceId);
        $request = new Request();
        $request->sourceIdSite = $sourceId;
        $request->targetIdSite = $targetId;
        return [$targetId, RollUpReporting::migrateRollUps($request, $this->targetDb)];
    }

    private function targetMembers(int $id): array
    {
        return array_map('intval', $this->targetDb->fetchCol('SELECT idsite FROM ' . self::TARGET_PREFIX . 'site_rollup WHERE parent_idsite = ? ORDER BY idsite', [$id]));
    }

    private function targetSettings(int $id): array
    {
        $settings = $this->targetDb->fetchRow('SELECT ecommerce, sitesearch, ts_created FROM ' . self::TARGET_PREFIX . 'site WHERE idsite = ?', [$id]);
        $settings['ecommerce'] = (int) $settings['ecommerce'];
        $settings['sitesearch'] = (int) $settings['sitesearch'];
        return $settings;
    }

    private function setSourcePrefix(string $prefix): void
    {
        $config = Config::getInstance();
        $database = $config->database;
        $database['tables_prefix'] = $prefix;
        $config->database = $database;
        Option::clearCache();
        Site::clearCache();
    }
}
