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
use Piwik\Plugin\Manager;
use Piwik\Plugins\CustomAlerts\Model;
use Piwik\Plugins\CustomDimensions\CustomDimensions;
use Piwik\Plugins\CustomDimensions\Dao\Configuration;
use Piwik\Plugins\Goals\API as GoalsAPI;
use Piwik\Plugins\Migration\Migrations;
use Piwik\Plugins\Migration\Migrations\CustomAlertsMigration;
use Piwik\Plugins\Migration\Migrations\PluginDataMigration;
use Piwik\Plugins\Migration\Migrations\Provider;
use Piwik\Plugins\Migration\Migrations\Request;
use Piwik\Plugins\Migration\PluginData\PluginDataMigrator;
use Piwik\Plugins\Migration\PluginData\PluginDefinition;
use Piwik\Plugins\Migration\PluginData\PluginDefinitions;
use Piwik\Plugins\Migration\PluginData\TableDefinition;
use Piwik\Plugins\Migration\TargetDb;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group Migration
 * @group CustomAlertsMigrationTest
 * @group Plugins
 */
class CustomAlertsMigrationTest extends IntegrationTestCase
{
    /** @var TargetDb */
    private $targetDb;

    /** @var Request */
    private $request;

    /** @var string[] */
    private $extraTables = [];

    public function setUp(): void
    {
        parent::setUp();
        Manager::getInstance()->activatePlugin('CustomAlerts');
        Model::install();
        $this->targetDb = new TargetDb(['tables_prefix' => 'alerts_target_']);
        foreach (['alert', 'alert_site', 'alert_triggered'] as $table) {
            Db::query('CREATE TABLE ' . $this->targetDb->prefixTable($table) . ' LIKE ' . Common::prefixTable($table));
        }
        $this->request = new Request();
        $this->request->sourceIdSite = 1;
        $this->request->targetIdSite = 10;
    }

    public function tearDown(): void
    {
        foreach ($this->extraTables as $table) {
            Db::query('DROP TABLE IF EXISTS ' . Db::get()->quoteIdentifier([$table]));
        }
        foreach (['alert', 'alert_site', 'alert_triggered'] as $table) {
            Db::query('DROP TABLE IF EXISTS ' . $this->targetDb->prefixTable($table));
        }
        parent::tearDown();
    }

    /** @dataProvider getSkipOptions */
    public function test_providerIncludesCustomAlerts($skipLogs, $skipArchives)
    {
        $classes = array_map('get_class', (new Provider())->getAllMigrations($skipLogs, $skipArchives));
        $this->assertContains(CustomAlertsMigration::class, $classes);
        $this->assertGreaterThan(array_search(Migrations\SiteMigration::class, $classes), array_search(CustomAlertsMigration::class, $classes));
        $this->assertGreaterThan(array_search(PluginDataMigration::class, $classes), array_search(CustomAlertsMigration::class, $classes));
    }

    public function getSkipOptions()
    {
        return [[false, false], [true, false], [false, true], [true, true]];
    }

    public function test_providerSkipsInactiveCustomAlerts()
    {
        Manager::getInstance()->deactivatePlugin('CustomAlerts');
        $classes = array_map('get_class', (new Provider())->getAllMigrations(false, false));
        $this->assertNotContains(CustomAlertsMigration::class, $classes);
    }

    public function test_migratesOnlySelectedSiteAndPreservesSnapshots()
    {
        $this->createAlert(1, [1], 'Single site');
        $this->createAlert(2, [1, 2], 'Shared alert');
        $this->createAlert(3, [2], 'Other site');
        $this->createHistory(1, 1);
        $this->createHistory(2, 1);
        $this->createHistory(2, 2);
        $this->createHistory(3, 2);
        $existing = $this->alertRow(100, 'Existing destination alert');
        $this->targetDb->insert('alert', $existing);
        $this->targetDb->insert('alert_site', ['idalert' => 100, 'idsite' => 20]);
        $this->targetDb->insert('alert_triggered', $this->historyRow($existing, 20));
        $sourceBefore = $this->allRows(false);
        $targetBefore = $this->allRows(true);

        (new CustomAlertsMigration())->migrate($this->request, $this->targetDb);

        $target = $this->allRows(true);
        $this->assertCount(3, $target['alert']);
        $this->assertCount(3, $target['alert_site']);
        $this->assertCount(3, $target['alert_triggered']);
        foreach ($targetBefore as $table => $rows) {
            $this->assertSame($rows[0], $target[$table][0], 'Existing destination data changed: ' . $table);
        }
        foreach ([1 => 101, 2 => 102] as $sourceId => $targetId) {
            $expected = $sourceBefore['alert'][$sourceId - 1];
            $expected['idalert'] = (string) $targetId;
            $this->assertEquals($expected, $target['alert'][$sourceId]);
            $this->assertEquals(['idalert' => $targetId, 'idsite' => 10], $target['alert_site'][$sourceId]);
            $history = $sourceBefore['alert_triggered'][$sourceId - 1];
            $history['idalert'] = (string) $targetId;
            $history['idsite'] = '10';
            unset($history['idtriggered']);
            $actual = $target['alert_triggered'][$sourceId];
            unset($actual['idtriggered']);
            $this->assertEquals($history, $actual);
        }
        $this->assertSame($sourceBefore, $this->allRows(false), 'Source data changed');
    }

    public function test_preservesHistoryAfterSiteWasRemovedFromAlert()
    {
        $this->createAlert(1, [2], 'No longer active for source site');
        $this->createHistory(1, 1);
        (new CustomAlertsMigration())->migrate($this->request, $this->targetDb);
        $target = $this->allRows(true);
        $this->assertCount(1, $target['alert']);
        $this->assertSame([], $target['alert_site']);
        $this->assertCount(1, $target['alert_triggered']);
        $this->assertEquals(10, $target['alert_triggered'][0]['idsite']);
        $this->assertEquals($target['alert'][0]['idalert'], $target['alert_triggered'][0]['idalert']);
    }

    public function test_runsWithUpstreamMigrationsAndKeepsSiteScopedReportIds()
    {
        $this->request->sourceIdSite = Fixture::createWebsite('2013-01-01 00:00:00');
        GoalsAPI::getInstance()->addGoal($this->request->sourceIdSite, 'Checkout', 'manually', '', '');
        $dimensions = new Configuration();
        $dimensions->configureNewDimension($this->request->sourceIdSite, 'First', CustomDimensions::SCOPE_VISIT, 1, true, [], true);
        $dimensions->configureNewDimension($this->request->sourceIdSite, 'Second', CustomDimensions::SCOPE_VISIT, 2, true, [], true);
        $this->createAlert(1, [$this->request->sourceIdSite]);
        $this->createHistory(1, $this->request->sourceIdSite);

        $tables = ['site', 'site_url', 'site_setting', 'goal', 'segment', 'annotations', 'custom_dimensions'];
        try {
            foreach ($tables as $table) {
                Db::query('CREATE TABLE ' . $this->targetDb->prefixTable($table) . ' LIKE ' . Common::prefixTable($table));
            }
            $site = Db::fetchRow('SELECT * FROM ' . Common::prefixTable('site') . ' WHERE idsite = ?', [$this->request->sourceIdSite]);
            $site['idsite'] = 50;
            $this->targetDb->insert('site', $site);
            (new Migrations())->migrate((new Provider())->getAllMigrations(true, true), $this->request, $this->targetDb);

            $this->assertEquals(51, $this->request->targetIdSite);
            $target = $this->allRows(true);
            $this->assertEquals(51, $target['alert_site'][0]['idsite']);
            $this->assertEquals(51, $target['alert_triggered'][0]['idsite']);
            $this->assertEquals('Goals_get_idGoal--1', $target['alert'][0]['report']);
            $this->assertEquals('goal_1_nb_conversions', $target['alert'][0]['metric']);
            $this->assertEquals('CustomDimensions_getCustomDimension_idDimension--2', $target['alert_triggered'][0]['report']);
            $this->assertEquals(1, Db::fetchOne('SELECT idgoal FROM alerts_target_goal WHERE idsite = 51'));
            $this->assertEquals([1, 2], Db::get()->fetchCol('SELECT idcustomdimension FROM alerts_target_custom_dimensions WHERE idsite = 51 ORDER BY idcustomdimension'));
        } finally {
            foreach ($tables as $table) {
                Db::query('DROP TABLE IF EXISTS ' . $this->targetDb->prefixTable($table));
            }
        }
    }

    public function test_dryRunDoesNotChangeEitherDatabase()
    {
        $this->createAlert(1, [1, 2]);
        $this->createHistory(1, 1);
        $source = $this->allRows(false);
        $target = $this->allRows(true);
        $this->targetDb->enableDryRun();
        (new CustomAlertsMigration())->migrate($this->request, $this->targetDb);
        $this->assertSame($source, $this->allRows(false));
        $this->assertSame($target, $this->allRows(true));
    }

    public function test_emptySiteDoesNotCopyOtherSitesAlerts()
    {
        $this->createAlert(1, [2]);
        $this->createHistory(1, 2);
        (new CustomAlertsMigration())->migrate($this->request, $this->targetDb);
        $this->assertSame(['alert' => [], 'alert_site' => [], 'alert_triggered' => []], $this->allRows(true));
    }

    public function test_missingHistoryDefinitionRollsBackMigration()
    {
        $this->createAlert(1, [1]);
        $this->createHistory(1, 1);
        Db::get()->insert(Common::prefixTable('alert_triggered'), $this->historyRow($this->alertRow(99, 'Missing definition'), 1));
        try {
            (new Migrations())->migrate([new CustomAlertsMigration()], $this->request, $this->targetDb);
            $this->fail('Expected the missing alert definition to stop the migration.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('its alert definition is missing', $e->getMessage());
        }
        $this->assertSame(['alert' => [], 'alert_site' => [], 'alert_triggered' => []], $this->allRows(true));
    }

    public function test_historyIsCopiedAcrossBatchBoundary()
    {
        $this->createAlert(1, [1]);
        for ($i = 0; $i < 1001; ++$i) {
            $this->createHistory(1, 1);
        }
        (new CustomAlertsMigration())->migrate($this->request, $this->targetDb);
        $this->assertEquals(1001, Db::fetchOne('SELECT COUNT(*) FROM alerts_target_alert_triggered WHERE idsite = 10'));
    }

    /** @dataProvider getAlertTables */
    public function test_validateStructureReportsMissingTargetTable($table)
    {
        Db::query('DROP TABLE ' . $this->targetDb->prefixTable($table));
        $errors = (new CustomAlertsMigration())->validateStructure($this->targetDb);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString($this->targetDb->prefixTable($table), $errors[0]);
    }

    public function getAlertTables()
    {
        return [['alert'], ['alert_site'], ['alert_triggered']];
    }

    public function test_validateStructureChecksHistoryColumns()
    {
        Db::query('ALTER TABLE alerts_target_alert_triggered DROP COLUMN metric');
        $errors = (new CustomAlertsMigration())->validateStructure($this->targetDb);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('metric', $errors[0]);
    }

    public function test_validateStructureAcceptsMatchingTables()
    {
        $this->assertSame([], (new CustomAlertsMigration())->validateStructure($this->targetDb));
    }

    public function test_translatesPremiumReportsAndMetricsInCurrentAlertsAndHistoricalSnapshots()
    {
        $migrator = $this->createPremiumReports();
        $this->createPremiumAlert(1, [1]);
        $this->createPremiumAlert(2, []); // its only connection to the site is a historical snapshot
        $this->createPremiumAlert(3, [2], 2);
        $this->targetDb->insert('alert', $this->alertRow(100, 'Existing destination alert'));
        $sourceBefore = $this->allRows(false);
        $this->request->idMaps->set('custom_metrics', 3, 203);
        $this->request->idMaps->set('custom_metrics', 4, 204);

        $this->migrateWithPremiumData($migrator);

        $target = $this->allRows(true);
        $this->assertCount(3, $target['alert']);
        $this->assertCount(1, $target['alert_site']);
        $this->assertCount(2, $target['alert_triggered']);
        $this->assertSame('Goals_get_idGoal--1', $target['alert'][0]['report']);
        foreach ([1, 2] as $offset) {
            $this->assertSame('CustomReports_getCustomReport_idCustomReport--101', $target['alert'][$offset]['report']);
            $this->assertSame('custom_metric_203', $target['alert'][$offset]['metric']);
            $expectedHistory = $sourceBefore['alert_triggered'][$offset - 1];
            $expectedHistory['idsite'] = '10';
            $expectedHistory['idalert'] = (string) (100 + $offset);
            $expectedHistory['report'] = 'CustomReports_getCustomReport_idCustomReport--102';
            $expectedHistory['metric'] = 'custom_metric_204';
            unset($expectedHistory['idtriggered']);
            $actualHistory = $target['alert_triggered'][$offset - 1];
            unset($actualHistory['idtriggered']);
            $this->assertEquals($expectedHistory, $actualHistory);
        }
        $this->assertSame($sourceBefore, $this->allRows(false));
        $this->assertSame([], $migrator->getTablesNotMigrated(1, $this->targetDb));
    }

    /** @dataProvider getDimensionMetricTargets */
    public function test_dimensionMetricsAreTranslatedBeforeReusingAlertsAndHistory(int $currentDimension, int $historicalDimension, ?string $existing, bool $dryRun): void
    {
        $migrator = $this->createPremiumReports();
        $this->createPremiumAlert(1, [1]);
        Db::get()->update(Common::prefixTable('alert'), ['metric' => 'nb_uniq_customdimension_customdimension2'], 'idalert = 1');
        Db::get()->update(Common::prefixTable('alert_triggered'), ['metric' => 'nb_uniq_customdimension_customdimension3'], 'idalert = 1');
        $this->request->isExistingTargetSite = true;
        $this->request->idMaps->set(TableDefinition::CUSTOM_DIMENSION, 2, $currentDimension);
        $this->request->idMaps->set(TableDefinition::CUSTOM_DIMENSION, 3, $historicalDimension);
        // Reusing the reports makes their destination IDs deterministic in dry runs too.
        foreach ([101 => 'Current report', 102 => 'Historical report'] as $id => $name) {
            $this->targetDb->insert('mtest_alert_report', ['idreport' => $id, 'idsite' => 10, 'name' => $name]);
        }
        $sourceBefore = $this->allRows(false);
        $expectedAlert = array_merge($sourceBefore['alert'][0], [
            'idalert' => 100, 'report' => 'CustomReports_getCustomReport_idCustomReport--101',
            'metric' => 'nb_uniq_customdimension_customdimension' . $currentDimension,
        ]);
        $expectedHistory = array_merge($sourceBefore['alert_triggered'][0], [
            'idalert' => 100, 'idsite' => 10, 'report' => 'CustomReports_getCustomReport_idCustomReport--102',
            'metric' => 'nb_uniq_customdimension_customdimension' . $historicalDimension,
        ]);
        unset($expectedHistory['idtriggered']);
        if ($existing !== null) {
            $alert = $expectedAlert;
            $history = $expectedHistory;
            if ($existing === 'conflicting') {
                $alert['metric'] = 'nb_uniq_customdimension_customdimension2';
                $history['metric'] = 'nb_uniq_customdimension_customdimension3';
            }
            $this->targetDb->insert('alert', $alert);
            $this->targetDb->insert('alert_site', ['idalert' => 100, 'idsite' => 10]);
            $this->targetDb->insert('alert_triggered', $history);
        }
        $targetBefore = $this->allRows(true);
        if ($dryRun) {
            $this->targetDb->enableDryRun();
        }
        $messages = [];
        $alerts = new CustomAlertsMigration($migrator);
        $alerts->onLog(function ($message) use (&$messages) {
            $messages[] = $message;
        });
        (new Migrations())->migrate([new PluginDataMigration($migrator, true), $alerts], $this->request, $this->targetDb);

        $added = $existing === 'matching' ? 0 : 1;
        $this->assertContains('Migrated ' . $added . ' custom alerts', $messages);
        $this->assertContains('Migrated ' . $added . ' triggered custom alerts', $messages);
        $target = $this->allRows(true);
        if ($dryRun) {
            $this->assertSame($targetBefore, $target, 'Dry runs must translate references for reuse decisions without writing.');
        } else {
            $this->assertCount(count($targetBefore['alert']) + $added, $target['alert']);
            $this->assertCount(count($targetBefore['alert_triggered']) + $added, $target['alert_triggered']);
            $actualAlert = end($target['alert']);
            $actualHistory = end($target['alert_triggered']);
            $expectedAlert['idalert'] = $actualAlert['idalert'];
            $expectedHistory['idalert'] = $actualAlert['idalert'];
            unset($actualHistory['idtriggered']);
            $this->assertEquals($expectedAlert, $actualAlert);
            $this->assertEquals($expectedHistory, $actualHistory, 'History has its own dimension ID, independent of the current alert.');
            if ($existing !== null) {
                $this->assertSame($targetBefore['alert'][0], $target['alert'][0], 'The existing destination alert must remain unchanged.');
                $this->assertSame($targetBefore['alert_triggered'][0], $target['alert_triggered'][0]);
            }
        }
        $this->assertSame($sourceBefore, $this->allRows(false));
    }

    public static function getDimensionMetricTargets(): array
    {
        return [
            'different current and historical dimensions' => [5, 8, null, false],
            'identity mappings' => [2, 3, null, false],
            'reuse translated alert and snapshot' => [5, 8, 'matching', false],
            'source metric conflicts with destination metric' => [5, 8, 'conflicting', false],
            'dry run reuses translated alert and snapshot' => [5, 8, 'matching', true],
            'dry run detects different destination metric' => [5, 8, 'conflicting', true],
        ];
    }

    /** @dataProvider getUnresolvedPremiumReferences */
    public function test_unresolvedPremiumReferencesRollBackTheCombinedMigration($table, $column, $value, $skipPlugin)
    {
        $migrator = $this->createPremiumReports($skipPlugin);
        $this->createPremiumAlert(1, [1]);
        $this->request->idMaps->set('custom_metrics', 3, 203);
        $this->request->idMaps->set('custom_metrics', 4, 204);
        if ($value !== null) {
            Db::get()->update(Common::prefixTable($table), [$column => $value], 'idalert = 1');
        }
        $sourceBefore = $this->allRows(false);
        try {
            $this->migrateWithPremiumData($migrator);
            $this->fail('Unresolved premium references must not be copied as source IDs.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('could not be migrated', $e->getMessage());
            $this->assertStringContainsString($column, $e->getMessage());
        }
        $this->assertSame(['alert' => [], 'alert_site' => [], 'alert_triggered' => []], $this->allRows(true));
        $this->assertSame(1, (int) $this->targetDb->fetchOne('SELECT COUNT(*) FROM alerts_target_mtest_alert_report'));
        $this->assertSame($sourceBefore, $this->allRows(false));
    }

    public function getUnresolvedPremiumReferences()
    {
        return [
            'missing current report' => ['alert', 'report', 'CustomReports_getCustomReport_idCustomReport--999', false],
            'missing historical report' => ['alert_triggered', 'report', 'CustomReports_getCustomReport_idCustomReport--999', false],
            'missing current metric' => ['alert', 'metric', 'custom_metric_999', false],
            'missing historical metric' => ['alert_triggered', 'metric', 'custom_metric_999', false],
            'excluded plugin' => ['alert', 'report', null, true],
        ];
    }

    public function test_dryRunResolvesPremiumReferencesWithoutWriting()
    {
        $migrator = $this->createPremiumReports();
        $this->createPremiumAlert(1, [1]);
        $this->request->idMaps->set('custom_metrics', 3, 203);
        $this->request->idMaps->set('custom_metrics', 4, 204);
        $sourceBefore = $this->allRows(false);
        $this->targetDb->enableDryRun();

        $this->migrateWithPremiumData($migrator);

        $this->assertNotNull($this->request->idMaps->get('mtest_alert_report', 7));
        $this->assertSame(['alert' => [], 'alert_site' => [], 'alert_triggered' => []], $this->allRows(true));
        $this->assertSame(1, (int) $this->targetDb->fetchOne('SELECT COUNT(*) FROM alerts_target_mtest_alert_report'));
        $this->assertSame($sourceBefore, $this->allRows(false));
    }

    /** @dataProvider getDuplicateReportModes */
    public function test_deduplicatedReportsResolveInAlertsAndHistory(bool $dryRun): void
    {
        $migrator = $this->createPremiumReports();
        foreach ([[77, 'Current report'], [78, 'Historical report']] as [$id, $name]) {
            Db::get()->insert(Common::prefixTable('mtest_alert_report'), ['idreport' => $id, 'idsite' => 0, 'name' => $name]);
        }
        $this->createAlert(1, [1]);
        Db::get()->update(Common::prefixTable('alert'), ['report' => 'CustomReports_getCustomReport_idCustomReport--77', 'metric' => 'nb_visits'], 'idalert = 1');
        $this->createHistory(1, 1);
        Db::get()->update(Common::prefixTable('alert_triggered'), ['report' => 'CustomReports_getCustomReport_idCustomReport--78', 'metric' => 'nb_visits'], 'idalert = 1');
        $sourceBefore = $this->allRows(false);
        if ($dryRun) {
            $this->targetDb->enableDryRun();
        }

        $this->migrateWithPremiumData($migrator);

        $this->assertNotNull($this->request->idMaps->get('mtest_alert_report', 77));
        $this->assertNotNull($this->request->idMaps->get('mtest_alert_report', 78));
        $target = $this->allRows(true);
        if ($dryRun) {
            $this->assertSame(['alert' => [], 'alert_site' => [], 'alert_triggered' => []], $target);
            $this->assertSame(1, (int) $this->targetDb->fetchOne('SELECT COUNT(*) FROM alerts_target_mtest_alert_report'));
        } else {
            $this->assertEquals($this->request->idMaps->get('mtest_alert_report', 7), $this->request->idMaps->get('mtest_alert_report', 77));
            $this->assertEquals($this->request->idMaps->get('mtest_alert_report', 9), $this->request->idMaps->get('mtest_alert_report', 78));
            $this->assertSame('CustomReports_getCustomReport_idCustomReport--101', $target['alert'][0]['report']);
            $this->assertSame('CustomReports_getCustomReport_idCustomReport--102', $target['alert_triggered'][0]['report']);
            $this->assertSame(3, (int) $this->targetDb->fetchOne('SELECT COUNT(*) FROM alerts_target_mtest_alert_report'));
            $this->assertSame('CustomReports_customreport_101_table', $migrator->getTargetArchiveRecordName('CustomReports_customreport_7_table', $this->request->idMaps));
            $this->assertNull($migrator->getTargetArchiveRecordName('CustomReports_customreport_77_table', $this->request->idMaps), 'A deduplicated report must not replace the site report archive.');
        }
        $this->assertSame($sourceBefore, $this->allRows(false));
    }

    public static function getDuplicateReportModes(): array
    {
        return ['real migration' => [false], 'dry run' => [true]];
    }

    private function createPremiumReports(bool $skipPlugin = false): PluginDataMigrator
    {
        $table = 'mtest_alert_report';
        foreach ([Common::prefixTable($table), $this->targetDb->prefixTable($table)] as $name) {
            Db::query('CREATE TABLE ' . Db::get()->quoteIdentifier([$name]) . ' (idreport INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, idsite INT UNSIGNED, name VARCHAR(100)) ENGINE=InnoDB');
            $this->extraTables[] = $name;
        }
        foreach ([[7, 1, 'Current report'], [9, 1, 'Historical report'], [99, 2, 'Other site report']] as $row) {
            Db::get()->insert(Common::prefixTable($table), array_combine(['idreport', 'idsite', 'name'], $row));
        }
        $this->targetDb->insert($table, ['idreport' => 100, 'idsite' => 77, 'name' => 'Existing destination report']);
        $definition = new PluginDefinition('Migration');
        $definition->addTable(TableDefinition::forSite($table)->autoIncrement('idreport')->identifiedBy('name')->includesRowsOfSeveralSites('idsite = 0'));
        $definition->identifiesReportsBy('idCustomReport', $table);
        $definition->renamesArchiveRecords('/^CustomReports_customreport_(\d+)(?=_)/', $table);
        $definitions = new PluginDefinitions();
        $definitions->add($definition);
        $this->request->idMaps->set('site', 1, 10);
        return new PluginDataMigrator($definitions, $skipPlugin ? ['Migration'] : []);
    }

    private function createPremiumAlert(int $id, array $sites, int $historySite = 1): void
    {
        $this->createAlert($id, $sites, 'Premium alert ' . $id);
        Db::get()->update(Common::prefixTable('alert'), [
            'report' => 'CustomReports_getCustomReport_idCustomReport--7', 'metric' => 'custom_metric_3',
        ], 'idalert = ' . $id);
        $this->createHistory($id, $historySite);
        Db::get()->update(Common::prefixTable('alert_triggered'), [
            'report' => 'CustomReports_getCustomReport_idCustomReport--9', 'metric' => 'custom_metric_4',
        ], 'idalert = ' . $id);
    }

    private function migrateWithPremiumData(PluginDataMigrator $migrator): void
    {
        (new Migrations())->migrate([
            new PluginDataMigration($migrator, true),
            new CustomAlertsMigration($migrator),
        ], $this->request, $this->targetDb);
    }

    private function createAlert($id, $sites, $name = 'Test alert')
    {
        Db::get()->insert(Common::prefixTable('alert'), $this->alertRow($id, $name));
        foreach ($sites as $site) {
            Db::get()->insert(Common::prefixTable('alert_site'), ['idalert' => $id, 'idsite' => $site]);
        }
    }

    private function alertRow($id, $name)
    {
        return [
            'idalert' => $id, 'name' => $name, 'login' => 'alert_owner', 'period' => 'day',
            'report' => 'Goals_get_idGoal--1', 'metric' => 'goal_1_nb_conversions',
            'metric_condition' => 'less_than', 'metric_matched' => 5, 'compared_to' => 7,
            'report_condition' => 'matches_exactly', 'report_matched' => 'Checkout',
            'email_me' => 1, 'additional_emails' => '["ops@example.com"]', 'phone_numbers' => '["0123456789"]',
        ];
    }

    private function createHistory($id, $site)
    {
        $alert = Db::fetchRow('SELECT * FROM ' . Common::prefixTable('alert') . ' WHERE idalert = ?', [$id]);
        Db::get()->insert(Common::prefixTable('alert_triggered'), $this->historyRow($alert, $site));
    }

    private function historyRow($alert, $site)
    {
        return array_merge($alert, [
            'idsite' => $site, 'ts_triggered' => '2013-01-24 00:00:00', 'ts_last_sent' => '2013-01-24 01:00:00',
            'value_old' => '20.125', 'value_new' => '4.500', 'name' => 'Name at time of trigger',
            'report' => 'CustomDimensions_getCustomDimension_idDimension--2', 'metric' => 'nb_visits',
        ]);
    }

    private function allRows($target)
    {
        $rows = [];
        foreach (['alert' => 'idalert', 'alert_site' => 'idalert, idsite', 'alert_triggered' => 'idtriggered'] as $table => $order) {
            $name = $target ? $this->targetDb->prefixTable($table) : Common::prefixTable($table);
            $rows[$table] = Db::fetchAll('SELECT * FROM ' . $name . ' ORDER BY ' . $order);
        }
        return $rows;
    }
}
