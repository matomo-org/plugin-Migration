<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\tests\System\Commands;

use Piwik\Access;
use Piwik\DataTable\Row;
use Piwik\Db;
use Piwik\Plugins\Migration\PluginData\PluginDefinitions;
use Piwik\Plugins\Migration\tests\Fixtures\MigrationFixture;
use Piwik\Plugins\Migration\tests\Fixtures\PluginDataFixture;
use Piwik\Tests\Framework\Mock\FakeAccess;
use Piwik\Tests\Framework\TestCase\ConsoleCommandTestCase;

/**
 * Checks the data of plugin tables is migrated with every reference pointing to the migrated rows. The test compares
 * the source and target data with all IDs replaced by what they refer to, as the IDs differ on the target.
 *
 * The migration is run several times into the same target, so the IDs of the later runs collide with the earlier ones.
 *
 * @group Migration
 * @group MigratePluginDataTest
 * @group Plugins
 */
class MigratePluginDataTest extends ConsoleCommandTestCase
{
    /**
     * @var PluginDataFixture
     */
    public static $fixture = null; // initialized below class definition

    private const SOURCE_PREFIX = '';
    private const TARGET_PREFIX = MigrationFixture::TARGET_DB_PREFIX;

    public function test_runMigration_migratesPluginData()
    {
        $result = $this->runCommand();
        $output = $this->applicationTester->getDisplay();
        $this->assertEquals(0, $result, $output);

        // the plugins whose data references reports last, as they may be about the data of the others
        $this->assertStringContainsString('Found 1 plugins with data to migrate: Migration at', $output);
        $this->assertStringContainsString('Migrated 2 rows of mtest_heatmap at', $output);
        // also the reports of all websites and several websites, but not the one having the name of a report of the site
        $this->assertStringContainsString('Migrated 4 rows of mtest_report, skipped 1 rows of all or several sites, as the site has another row with the same identity, eg name at', $output);
        $this->assertStringContainsString('Migrated 1 rows of mtest_container at', $output);
        $this->assertStringContainsString('Migrated 3 rows of mtest_container_item at', $output);
        $this->assertStringContainsString('Migrated 3 rows of log_mtest at', $output);
        $this->assertStringContainsString('Migrated 4 rows of log_mtest_heatmap, skipped 1 rows referencing data that was not migrated at', $output);
        $this->assertStringContainsString('Migrated 2509 rows of log_mtest_event at', $output);
        $this->assertStringContainsString('Copied 2 flags of the site at', $output);
        $this->assertEquals(['alpha', 'beta'], array_column($this->fetchAll('SELECT flag FROM %smtest_site_flag WHERE idsite = 1 ORDER BY flag'), 'flag'));
        // the data of the property of the site, but not the day the target imported already
        $this->assertStringContainsString('Migrated 153 rows of mtest_import, 1 rows the target had already at', $output);
        $this->assertEquals(0, $this->fetchOne("SELECT COUNT(*) FROM %smtest_import WHERE property = 'https://other.example.com/'"));
        $this->assertStringContainsString('Skipped 1 archive records of plugin data that was not migrated', $output);
        $this->assertStringContainsString('Skipped 2 archive records of plugin data that was not migrated', $output);
        $this->assertStringContainsString('Changed 2 segments selecting plugin data by ID to select it by its ID on the target at', $output);
        $this->assertStringNotContainsString('mtest', $this->getNotMigratedLines($output));

        $this->assertTargetHasSameDataAsSource(1);

        $sourceHash = Db::fetchOne("SELECT hash FROM segment WHERE enable_only_idsite = 1 AND name = 'Heatmap visitors'");
        $targetHash = $this->fetchOne("SELECT hash FROM %ssegment WHERE enable_only_idsite = 1 AND name = 'Heatmap visitors'");
        $this->assertNotEquals($sourceHash, $targetHash);
        $this->assertEquals(17, Db::fetchOne(
            'SELECT visits.value FROM ' . self::TARGET_PREFIX . 'archive_numeric_2013_01 done '
            . 'JOIN ' . self::TARGET_PREFIX . 'archive_numeric_2013_01 visits ON visits.idarchive = done.idarchive '
            . 'WHERE done.idsite = 1 AND done.name = ? AND visits.name = ?',
            ['done' . $targetHash . '.all', 'nb_visits']
        ), 'Historical segment reports must remain discoverable after the plugin IDs in their filter change.');
        $this->assertEquals(0, Db::fetchOne(
            'SELECT COUNT(*) FROM ' . self::TARGET_PREFIX . 'archive_numeric_2013_01 WHERE idsite = 1 AND name = ?',
            ['done' . $sourceHash . '.all']
        ));

        // a random key is kept when the target doesn't have it yet
        $this->assertEquals(PluginDataFixture::CONTAINER_KEY, $this->fetchOne('SELECT idcontainer FROM %smtest_container WHERE idsite = 1'));
        // the blob the target had already is reused, the other one copied
        $this->assertEquals(2, $this->fetchOne('SELECT COUNT(*) FROM %smtest_blob'));
        // the metric the target has with another formula is copied with another name, the one it has already is used
        $this->assertEquals(
            ['Bounce rate', 'Clicks per visit', 'Conversion rate', 'Conversion rate (2)'],
            $this->fetchCol('SELECT name FROM %smtest_metric ORDER BY name')
        );
        $this->assertEquals('{{nb_conversions}} / {{nb_visits}}', $this->fetchOne("SELECT formula FROM %smtest_metric WHERE name = 'Conversion rate (2)'"));
        // the IDs in a JSON list keep their type
        $this->assertMatchesRegularExpression('/^\["\d+"\]$/', $this->fetchOne("SELECT heatmap_ids FROM %smtest_container_item WHERE name = 'Deleted heatmap' AND idsite = 1"));
    }

    /**
     * @depends test_runMigration_migratesPluginData
     */
    public function test_runMigration_translatesIdsThatAreTakenOnTheTarget()
    {
        $result = $this->runCommand();
        $output = $this->applicationTester->getDisplay();
        $this->assertEquals(0, $result, $output);

        $this->assertTargetHasSameDataAsSource(2);
        $this->assertStringContainsString('Migrated 0 rows of mtest_import, 154 rows the target had already at', $output);

        $newKey = $this->fetchOne('SELECT idcontainer FROM %smtest_container WHERE idsite = 2');
        $this->assertNotEquals(PluginDataFixture::CONTAINER_KEY, $newKey);
        $this->assertEquals(8, strlen($newKey));
        $this->assertEquals(2, $this->fetchOne('SELECT COUNT(*) FROM %smtest_blob'));
        // the metrics copied before are used
        $this->assertEquals(4, $this->fetchOne('SELECT COUNT(*) FROM %smtest_metric'));
        $this->assertEquals(2, $this->fetchOne('SELECT COUNT(*) FROM %slog_action WHERE type = 42'));
    }

    /**
     * @depends test_runMigration_translatesIdsThatAreTakenOnTheTarget
     */
    public function test_runMigration_skipPluginData_doesNotMigrateItsDataNorArchiveRecordsReferencingIt()
    {
        $result = $this->runCommand(['--skip-plugin-data' => 'Migration']);
        $output = $this->applicationTester->getDisplay();
        $this->assertEquals(0, $result, $output);

        $this->assertStringContainsString('Found 0 plugins with data to migrate at', $output);
        $notMigrated = $this->getNotMigratedLines($output);
        foreach (['mtest_heatmap', 'mtest_report', 'mtest_container', 'mtest_container_item', 'log_mtest', 'mtest_site_flag'] as $table) {
            $this->assertStringContainsString('The table ' . $table . ' has data', $notMigrated);
        }

        foreach (['mtest_heatmap', 'mtest_report', 'mtest_container', 'mtest_container_item', 'log_mtest', 'mtest_site_flag'] as $table) {
            $this->assertEquals(0, $this->fetchOne("SELECT COUNT(*) FROM %s$table WHERE idsite = 3"), $table);
        }
        $this->assertEquals(['MTest_heatmap_count'], $this->fetchCol("SELECT name FROM %sarchive_numeric_2013_01 WHERE idsite = 3 AND name LIKE 'MTest%%'"));
        // the report about the heatmaps is copied without the rows of the heatmaps and the columns of the metrics
        $this->assertEquals(['MTest_heatmaps', 'MTest_heatmaps_chunk_0_99'], $this->fetchCol("SELECT name FROM %sarchive_blob_2013_01 WHERE idsite = 3 AND name LIKE 'MTest%%'"));
        $this->assertEquals([
            ['MTest_heatmaps', 0, 'Not a heatmap', 'string', 2, null, []],
            ['MTest_heatmaps', 0, 'Page with a heatmap', 'string', 4, null, []],
            ['MTest_heatmaps', 0, 'Page with a deleted heatmap', 'string', 6, null, []],
            ['MTest_heatmaps', -1, -1, 'integer', 21, null, []],
            ['MTest_heatmaps_chunk_0_99', 0, 'other', 'string', 1, null, []],
        ], $this->getHeatmapReportRows(self::TARGET_PREFIX, 3, [], []));
    }

    public function test_runMigration_failsForUnknownPluginToSkip()
    {
        $result = $this->runCommand(['--skip-plugin-data' => 'Migration,UnknownPlugin']);
        $this->assertEquals(1, $result);
        $this->assertStringContainsString('There is no plugin data definition for the plugin "UnknownPlugin" given in --skip-plugin-data. Plugins having one: HeatmapSessionRecording, Funnels, MultiChannelConversionAttribution, FormAnalytics, AbTesting, MediaAnalytics, SEOWebVitals, CrashAnalytics, AdvertisingConversionExport, CustomReports, SearchEngineKeywordsPerformance, RollUpReporting, Migration', $this->applicationTester->getDisplay());
    }

    public function test_runMigration_failsWhenTableHasColumnThatMayHoldIdsTheDefinitionDoesNotKnow()
    {
        foreach ([self::SOURCE_PREFIX, self::TARGET_PREFIX] as $prefix) {
            Db::query("ALTER TABLE {$prefix}mtest_heatmap ADD COLUMN `idfoo` INT NULL, ADD COLUMN `bar_ids` TEXT NULL, ADD COLUMN `source_idfoo` INT NULL");
        }
        try {
            $result = $this->runCommand();
        } finally {
            foreach ([self::SOURCE_PREFIX, self::TARGET_PREFIX] as $prefix) {
                Db::query("ALTER TABLE {$prefix}mtest_heatmap DROP COLUMN `idfoo`, DROP COLUMN `bar_ids`, DROP COLUMN `source_idfoo`");
            }
        }

        $this->assertEquals(1, $result);
        $this->assertStringContainsString('The definition of the table "mtest_heatmap" of plugin Migration does not say how to migrate the column(s) idfoo, bar_ids, source_idfoo.', $this->applicationTester->getDisplay());
    }

    public function test_runMigration_failsWhenTargetDoesNotHaveThePluginTables()
    {
        Db::query('RENAME TABLE ' . self::TARGET_PREFIX . 'log_mtest_event TO ' . self::TARGET_PREFIX . 'log_mtest_event_renamed');
        try {
            $result = $this->runCommand();
        } finally {
            Db::query('RENAME TABLE ' . self::TARGET_PREFIX . 'log_mtest_event_renamed TO ' . self::TARGET_PREFIX . 'log_mtest_event');
        }

        $this->assertEquals(1, $result);
        $this->assertStringContainsString('The table "targetdb_log_mtest_event" of plugin Migration does not exist in the target DB. Please install and activate the plugin on the target Matomo, or use --skip-plugin-data=Migration to not migrate its data.', $this->applicationTester->getDisplay());
    }

    public function test_runMigration_doesNotNeedTheVisitTablesWhenSkippingLogs()
    {
        Db::query('RENAME TABLE ' . self::TARGET_PREFIX . 'log_mtest_event TO ' . self::TARGET_PREFIX . 'log_mtest_event_renamed');
        try {
            $result = $this->runCommand(['--skip-logs' => true, '--dry-run' => true]);
        } finally {
            Db::query('RENAME TABLE ' . self::TARGET_PREFIX . 'log_mtest_event_renamed TO ' . self::TARGET_PREFIX . 'log_mtest_event');
        }

        $this->assertEquals(0, $result, $this->applicationTester->getDisplay());
    }

    public function test_runMigration_dryRun_doesNotInsertPluginData()
    {
        $countsBefore = $this->getTargetRowCounts();
        $result = $this->runCommand(['--dry-run' => true]);
        $output = $this->applicationTester->getDisplay();
        $this->assertEquals(0, $result, $output);
        $this->assertStringContainsString('Migrated 2509 rows of log_mtest_event at', $output);
        $this->assertEquals($countsBefore, $this->getTargetRowCounts());
    }

    public function test_runMigration_whenItFails_rollsBackAndOnlyReportsTheTargetSiteIfItExists()
    {
        $countsBefore = $this->getTargetRowCounts();
        PluginDataFixture::$failMigratingReports = true;
        try {
            $result = $this->runCommand();
            $output = $this->applicationTester->getDisplay();
            $this->assertEquals(1, $result);
            $this->assertStringContainsString('Failing on purpose', $output);
            $this->assertStringNotContainsString('was created with part of the data', $output);
            $this->assertEquals($countsBefore, $this->getTargetRowCounts());
        } finally {
            PluginDataFixture::$failMigratingReports = false;
        }
    }


    private function assertTargetHasSameDataAsSource(int $targetIdSite): void
    {
        $source = $this->getSemanticData(self::SOURCE_PREFIX, 1);
        $target = $this->getSemanticData(self::TARGET_PREFIX, $targetIdSite);

        // the reference to the deleted heatmap is removed
        $this->assertEquals([['name' => 'Homepage heatmap', 'idgoal' => '2', 'copy_of' => 'Product heatmap', 'copy_of_is_null' => '0'],
            ['name' => 'Product heatmap', 'idgoal' => null, 'copy_of' => null, 'copy_of_is_null' => '0']], $source['mtest_heatmap']);
        $source['mtest_heatmap'][1]['copy_of_is_null'] = '1';
        // the report rows of the deleted heatmap are removed, the links to it from other rows too, and the columns of
        // the metric no migrated report uses
        $this->assertContains(['MTest_heatmaps', 0, 'missing', 'string', 1, null, []], $source['MTest_heatmaps']);
        $this->assertContains(['MTest_heatmaps', 0, 'Page with a heatmap', 'string', 4, 'Product heatmap integer', []], $source['MTest_heatmaps']);
        $this->assertContains(['MTest_heatmaps', -1, -1, 'integer', 21, null, ['Bounce rate {{bounce_count}} / {{nb_visits}}' => 0.75]], $source['MTest_heatmaps']);
        $source['MTest_heatmaps'] = array_values(array_filter(array_map(function ($row) {
            $row[5] = $row[5] === 'missing string' ? null : $row[5];
            unset($row[6]['Unused metric {{nb_clicks}} * 2']);
            return $row;
        }, $source['MTest_heatmaps']), function ($row) {
            return $row[2] !== 'missing';
        }));
        // the target keeps the data it imported already
        $source['mtest_import'] = array_map(function ($row) {
            $row['data'] = $row['data'] === 'source keywords' ? 'target keywords' : $row['data'];
            return $row;
        }, $source['mtest_import']);
        // a report on the page of the deleted heatmap gets a page of its own
        $source['mtest_report'] = array_map(function ($row) {
            $row['subcategory'] = $row['subcategory'] === 'page of missing' ? '' : $row['subcategory'];
            return $row;
        }, $source['mtest_report']);
        // the rows referencing the deleted heatmap are not migrated
        $source['log_mtest_heatmap'] = array_values(array_filter($source['log_mtest_heatmap'], function ($row) {
            return $row['heatmap'] !== null;
        }));
        foreach ($source as $table => $rows) {
            $this->assertNotEmpty($rows, $table);
            $this->assertEquals($rows, $target[$table], $table);
        }
    }

    /**
     * The plugin data of a site with the IDs replaced by what they refer to
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function getSemanticData(string $prefix, int $idSite): array
    {
        $heatmapNames = [];
        foreach (Db::fetchAll("SELECT idheatmap, name FROM {$prefix}mtest_heatmap WHERE idsite = ?", [$idSite]) as $row) {
            $heatmapNames[$row['idheatmap']] = $row['name'];
        }
        $visit = "CONCAT(HEX(v.idvisitor), ' ', v.visit_first_action_time)";

        $data = [];
        $data['mtest_heatmap'] = Db::fetchAll("SELECT h.name, h.idgoal, c.name AS copy_of, h.copy_of_idheatmap IS NULL AS copy_of_is_null
            FROM {$prefix}mtest_heatmap h LEFT JOIN {$prefix}mtest_heatmap c ON c.idheatmap = h.copy_of_idheatmap AND c.idsite = h.idsite
            WHERE h.idsite = ? ORDER BY h.name", [$idSite]);
        $metricNames = $this->getMetricNames($prefix);
        $reports = Db::fetchAll("SELECT r.*, s.definition, s.enable_only_idsite = r.idsite AS segment_of_site, r.idsite = ? AS is_of_site
            FROM {$prefix}mtest_report r LEFT JOIN {$prefix}segment s ON s.idsegment = r.idsegment
            WHERE r.idsite = ? OR (? = '' AND r.idsite = 0 AND (r.idsites IS NULL OR FIND_IN_SET(?, r.idsites))) ORDER BY r.name, r.idsite DESC", [$idSite, $idSite, $prefix, $idSite]);
        // the site can't have a report of several websites having the name of a report of the site
        $siteReportNames = array_column(array_filter($reports, function ($report) {
            return $report['is_of_site'];
        }), 'name');
        $reports = array_values(array_filter($reports, function ($report) use ($siteReportNames) {
            return $report['is_of_site'] || !in_array($report['name'], $siteReportNames, true);
        }));
        $reportNames = array_column($reports, 'name', 'idreport');
        $data['mtest_report'] = array_map(function ($row) use ($heatmapNames, $metricNames, $reportNames) {
            $row['filter'] = preg_replace_callback('/mtestHeatmap==(\d+)/', function ($matches) use ($heatmapNames) {
                return 'mtestHeatmap==' . ($heatmapNames[$matches[1]] ?? 'missing');
            }, (string) $row['filter']);
            $row['metrics'] = array_map(function ($idMetric) use ($metricNames) {
                return $metricNames[$idMetric] ?? 'missing';
            }, json_decode((string) $row['metrics'], true) ?: []);
            $pages = ['MTest_Reports' => $reportNames, 'MTest_Heatmaps' => $heatmapNames];
            $row['subcategory'] = $row['subcategory'] === '' ? '' : 'page of ' . ($pages[$row['category']][$row['subcategory']] ?? 'missing');
            // copied as a report of the site
            $row['idsites'] = null;
            unset($row['idreport'], $row['idsite'], $row['idsegment'], $row['is_of_site']);
            return $row;
        }, $reports);
        $reportsByName = array_column($data['mtest_report'], null, 'name');
        $this->assertEquals('mtestHeatmap==Homepage heatmap;visitConvertedGoalId==1', $reportsByName['Report on segment']['filter']);
        // a report on the page of a report added after it, and one on its own page
        $this->assertEquals(['page of Report without segment', 'page of Report without segment'], [$reportsByName['Report on segment']['subcategory'], $reportsByName['Report without segment']['subcategory']]);
        $data['segment'] = array_map(function ($row) use ($heatmapNames) {
            $this->assertEquals(md5(urldecode($row['definition'])), $row['hash']);
            $row['definition'] = preg_replace_callback('/(mtestHeatmap(?:==|!=|%3D%3D|%21%3D))(\d+)/', function ($matches) use ($heatmapNames) {
                return $matches[1] . ($heatmapNames[$matches[2]] ?? 'missing');
            }, $row['definition']);
            unset($row['hash']);
            return $row;
        }, Db::fetchAll("SELECT name, definition, hash FROM {$prefix}segment WHERE enable_only_idsite = ? AND name LIKE 'Heatmap%' ORDER BY name", [$idSite]));
        $data['mtest_container'] = Db::fetchAll("SELECT name FROM {$prefix}mtest_container WHERE idsite = ?", [$idSite]);
        $data['mtest_container_item'] = array_map(function ($row) use ($heatmapNames) {
            $ids = $row['heatmap_ids'] === '' ? [] : json_decode($row['heatmap_ids'], true);
            $row['heatmap_ids'] = array_values(array_filter(array_map(function ($id) use ($heatmapNames) {
                return $heatmapNames[$id] ?? null;
            }, $ids)));
            return $row;
        }, Db::fetchAll("SELECT c.name AS container, i.name, i.heatmap_ids FROM {$prefix}mtest_container_item i
            JOIN {$prefix}mtest_container c ON c.idcontainer = i.idcontainer AND c.idsite = i.idsite WHERE i.idsite = ? ORDER BY i.name", [$idSite]));
        $data['log_mtest'] = Db::fetchAll("SELECT $visit AS visit, a.name AS url, a.type, lva.server_time AS action_time, m.idpageview, m.server_time
            FROM {$prefix}log_mtest m
            JOIN {$prefix}log_visit v ON v.idvisit = m.idvisit AND v.idsite = m.idsite
            LEFT JOIN {$prefix}log_action a ON a.idaction = m.idaction_url
            LEFT JOIN {$prefix}log_link_visit_action lva ON lva.idlink_va = m.idlink_va AND lva.idvisit = m.idvisit AND lva.idaction_url = m.idaction_url
            WHERE m.idsite = ? ORDER BY visit, m.idpageview", [$idSite]);
        $data['log_mtest_heatmap'] = Db::fetchAll("SELECT $visit AS visit, m.idpageview, h.name AS heatmap
            FROM {$prefix}log_mtest_heatmap mh
            JOIN {$prefix}log_mtest m ON m.idlogmtest = mh.idlogmtest
            JOIN {$prefix}log_visit v ON v.idvisit = m.idvisit
            LEFT JOIN {$prefix}mtest_heatmap h ON h.idheatmap = mh.idheatmap AND h.idsite = m.idsite
            WHERE m.idsite = ? ORDER BY visit, m.idpageview, heatmap", [$idSite]);
        $data['log_mtest_event'] = Db::fetchAll("SELECT $visit AS visit, m.idpageview, s.name AS selector, s.type AS selector_type,
                HEX(b.value) AS blob_value, b.hash, e.x, e.y
            FROM {$prefix}log_mtest_event e
            JOIN {$prefix}log_mtest m ON m.idlogmtest = e.idlogmtest
            JOIN {$prefix}log_visit v ON v.idvisit = m.idvisit
            LEFT JOIN {$prefix}log_action s ON s.idaction = e.idselector
            LEFT JOIN {$prefix}mtest_blob b ON b.idblob = e.idblob
            WHERE m.idsite = ? ORDER BY visit, m.idpageview, e.x, e.y", [$idSite]);

        $data['MTest_heatmaps'] = $this->getHeatmapReportRows($prefix, $idSite, $heatmapNames, $metricNames);
        // the data of the property of the site, its main URL
        $property = Db::fetchOne("SELECT main_url FROM {$prefix}site WHERE idsite = ?", [$idSite]);
        $data['mtest_import'] = Db::fetchAll("SELECT `date`, type, data FROM {$prefix}mtest_import WHERE property = ? ORDER BY `date`, type", [$property]);

        foreach (['archive_numeric_2013_01', 'archive_blob_2013_01'] as $archiveTable) {
            $records = [];
            foreach (Db::fetchAll("SELECT name, value FROM {$prefix}$archiveTable WHERE idsite = ? AND name LIKE 'MTest%' AND name NOT LIKE 'MTest\\_heatmaps%'", [$idSite]) as $row) {
                $name = preg_replace_callback('/^MTest_heatmap_(\d+)(?=_|$)/', function ($matches) use ($heatmapNames) {
                    return 'MTest_heatmap_' . ($heatmapNames[$matches[1]] ?? 'deleted');
                }, $row['name']);
                if (strpos($name, 'deleted') === false) {
                    $records[$name] = $row['value'];
                }
            }
            ksort($records);
            $data[$archiveTable] = [$records];
        }

        return $data;
    }

    /**
     * @return array<int|string, string> the metrics by ID, named by their name, without a number appended to make it
     *         unique, and their formula
     */
    private function getMetricNames(string $prefix): array
    {
        $names = [];
        foreach (Db::fetchAll("SELECT idmetric, name, formula FROM {$prefix}mtest_metric") as $row) {
            $names[$row['idmetric']] = preg_replace('/ \(\d+\)$/', '', $row['name']) . ' ' . $row['formula'];
        }
        return $names;
    }

    /**
     * The rows of the report about the heatmaps and of its subtable, with the heatmap IDs of the labels replaced by the
     * names of the heatmaps, and the columns of metrics by their names
     *
     * @param array<int|string, string> $heatmapNames
     * @param array<int|string, string> $metricNames
     * @return array<int, array<int, mixed>>
     */
    private function getHeatmapReportRows(string $prefix, int $idSite, array $heatmapNames, array $metricNames): array
    {
        $rows = [];
        foreach (Db::fetchAll("SELECT name, value FROM {$prefix}archive_blob_2013_01 WHERE idsite = ? AND name LIKE 'MTest\\_heatmaps%' ORDER BY name", [$idSite]) as $record) {
            $data = gzuncompress($record['value']);
            $tables = strpos($record['name'], '_chunk_') !== false ? unserialize($data) : [$data];
            foreach ($tables as $serializedRows) {
                foreach (unserialize($serializedRows) as $idRow => $row) {
                    $label = $row[Row::COLUMNS]['label'];
                    $name = $idRow < 0 ? $label : preg_replace_callback('/^(\d+)(?=_|$)/', function ($matches) use ($heatmapNames) {
                        return $heatmapNames[$matches[1]] ?? 'missing';
                    }, (string) $label);
                    $idHeatmap = $row[Row::COLUMNS]['idheatmap'] ?? null;
                    $heatmap = $idHeatmap === null ? null : ($heatmapNames[$idHeatmap] ?? 'missing') . ' ' . gettype($idHeatmap);
                    $metrics = [];
                    foreach ($row[Row::COLUMNS] as $column => $value) {
                        if (preg_match('/^metric_(\d+)$/', $column, $matches)) {
                            $metrics[$metricNames[$matches[1]] ?? 'missing'] = $value;
                        }
                    }
                    ksort($metrics);
                    $rows[] = [$record['name'], $idRow < 0 ? $idRow : 0, $name, gettype($label), $row[Row::COLUMNS]['nb_clicks'], $heatmap, $metrics];
                }
            }
        }
        $this->assertGreaterThanOrEqual(2, count(array_unique(array_column($rows, 0))), 'the report and its chunk');
        return $rows;
    }

    /**
     * @return array<string, int>
     */
    private function getTargetRowCounts(): array
    {
        $counts = [];
        foreach (['site', 'mtest_heatmap', 'mtest_metric', 'mtest_report', 'mtest_import', 'mtest_site_flag', 'mtest_container', 'mtest_container_item', 'mtest_blob', 'log_mtest', 'log_mtest_heatmap', 'log_mtest_event'] as $table) {
            $counts[$table] = (int) $this->fetchOne("SELECT COUNT(*) FROM %s$table");
        }
        return $counts;
    }

    private function getNotMigratedLines(string $output): string
    {
        return implode("\n", array_filter(explode("\n", $output), function ($line) {
            return strpos($line, 'that is not migrated') !== false;
        }));
    }

    /**
     * @return mixed
     */
    private function fetchOne(string $sql)
    {
        return Db::fetchOne(sprintf($sql, self::TARGET_PREFIX));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchAll(string $sql): array
    {
        return Db::fetchAll(sprintf($sql, self::TARGET_PREFIX));
    }

    /**
     * @return array<int, mixed>
     */
    private function fetchCol(string $sql): array
    {
        return array_column(Db::fetchAll(sprintf($sql, self::TARGET_PREFIX)), 'name');
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runCommand(array $options = []): int
    {
        $config = Db::getDatabaseConfig();
        return $this->applicationTester->run(array_merge([
            'command' => 'migration:measurable',
            '--source-idsite' => 1,
            '--target-db-host' => $config['host'],
            '--target-db-username' => $config['username'],
            '--target-db-password' => $config['password'],
            '--target-db-name' => $config['dbname'],
            '--target-db-port' => $config['port'] ?? '3306',
            '--target-db-prefix' => self::TARGET_PREFIX,
            '--no-interaction' => true,
        ], $options));
    }

    public static function provideContainerConfigBeforeClass()
    {
        return [
            Access::class => new FakeAccess(),
            'observers.global' => \Piwik\DI::add([
                ['Migration.addPluginDefinitions', \Piwik\DI::value(function (PluginDefinitions $definitions) {
                    $definitions->add(PluginDataFixture::getPluginDefinition());
                })],
            ]),
        ];
    }

    public static function getOutputPrefix()
    {
        return '';
    }

    public static function getPathToTestDirectory()
    {
        return dirname(__FILE__) . '/..';
    }
}

MigratePluginDataTest::$fixture = new PluginDataFixture();
