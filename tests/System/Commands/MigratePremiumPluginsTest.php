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
use Piwik\Plugins\Migration\tests\Fixtures\MigrationFixture;
use Piwik\Plugins\Migration\tests\Fixtures\PremiumPluginsFixture;
use Piwik\Tests\Framework\Mock\FakeAccess;
use Piwik\Tests\Framework\TestCase\ConsoleCommandTestCase;

/**
 * Checks the data of the premium plugins with a built-in definition is migrated with every reference pointing to the
 * migrated rows, for the premium plugins installed in the test environment. The source and target data are compared
 * with all IDs replaced by what they refer to, as the IDs differ on the target.
 *
 * @group Migration
 * @group MigratePremiumPluginsTest
 * @group Plugins
 */
class MigratePremiumPluginsTest extends ConsoleCommandTestCase
{
    /**
     * @var PremiumPluginsFixture
     */
    public static $fixture = null; // initialized below class definition

    private const TARGET_PREFIX = MigrationFixture::TARGET_DB_PREFIX;

    public function setUp(): void
    {
        parent::setUp();
        if (getenv('MIGRATION_REQUIRE_PREMIUM_PLUGINS') === '1') {
            $this->assertEquals(
                PremiumPluginsFixture::getPremiumPluginsWithDefinition(),
                $this->getPremiumPlugins(),
                'This run requires every supported premium plugin to be installed and activated.'
            );
        }
        if (empty($this->getPremiumPlugins())) {
            $this->markTestSkipped('No premium plugin with a built-in definition is installed');
        }
    }

    public function test_runMigration_migratesThePremiumPluginData()
    {
        $result = $this->runCommand();
        $output = $this->applicationTester->getDisplay();
        $this->assertEquals(0, $result, $output);

        if (PremiumPluginsFixture::isPluginActivated('HeatmapSessionRecording')) {
            $this->assertStringContainsString('Migrated 5 rows of site_hsr at', $output);
            $this->assertStringContainsString('Migrated 3 rows of log_hsr at', $output);
            $this->assertStringContainsString('Migrated 4 rows of log_hsr_site at', $output);
            $this->assertStringContainsString('Migrated 9 rows of log_hsr_event at', $output);
            // one of the two texts of the initial DOM is on the target already
            $this->assertEquals(1 + 2, $this->fetchOne('SELECT COUNT(*) FROM %slog_hsr_blob'));
        }
        if (PremiumPluginsFixture::isPluginActivated('Funnels')) {
            // including the deleted funnel, whose reports exist
            $this->assertStringContainsString('Migrated 3 rows of funnel at', $output);
            $this->assertStringContainsString('Migrated 5 rows of funnel_steps at', $output);
            $this->assertStringContainsString('Migrated 7 rows of log_funnel at', $output);
        }
        if (PremiumPluginsFixture::isPluginActivated('MultiChannelConversionAttribution')) {
            $this->assertStringContainsString('Migrated 3 rows of goal_attribution at', $output);
        }
        if (PremiumPluginsFixture::isPluginActivated('FormAnalytics')) {
            // including the form the tracker created
            $this->assertStringContainsString('Migrated 3 rows of site_form at', $output);
            $this->assertStringContainsString('Migrated 2 rows of log_form at', $output);
            $this->assertStringContainsString('Migrated 3 rows of log_form_page at', $output);
            $this->assertStringContainsString('Migrated 2 rows of log_form_field at', $output);
        }
        if (PremiumPluginsFixture::isPluginActivated('AdvertisingConversionExport')) {
            $this->assertStringContainsString('Migrated 1 rows of site_conversion_export at', $output);
            $this->assertStringContainsString('Migrated 1 rows of log_clickid at', $output);
            $this->assertStringContainsString('Note: The advertising platforms fetch the conversions', $output);
            // the hashed token stays valid if the target uses the same salt
            $this->assertEquals(
                Db::fetchOne("SELECT access_token FROM site_conversion_export WHERE idsite = 1"),
                $this->fetchOne('SELECT access_token FROM %ssite_conversion_export WHERE idsite = ' . $this->getMaxTargetIdSite())
            );
        }
        if (PremiumPluginsFixture::isPluginActivated('CrashAnalytics')) {
            $this->assertStringContainsString('Migrated 3 rows of log_crash at', $output);
            $this->assertStringContainsString('Migrated 3 rows of log_crash_group at', $output);
            $this->assertStringContainsString('Migrated 3 rows of log_crash_event at', $output);
            // two crashes have the same stack trace
            $this->assertEquals(2, $this->fetchOne('SELECT COUNT(*) FROM %slog_crash_stack'));
        }
        if (PremiumPluginsFixture::isPluginActivated('SEOWebVitals')) {
            $this->assertStringContainsString('Migrated 4 rows of log_webvitals_report at', $output);
        }
        if (PremiumPluginsFixture::isPluginActivated('CustomReports')) {
            // the reports of the site, of all websites and of several websites, one has the name of a report of the site
            $numReports = 5 + 2 + (PremiumPluginsFixture::isPluginActivated('Funnels') ? 1 : 0);
            $this->assertStringContainsString("Migrated $numReports rows of custom_reports, skipped 1 rows of all or several sites, as the site has another row with the same identity, eg name at", $output);
            $this->assertStringContainsString('Note: The custom reports of all websites', $output);
            // the metrics the reports use
            $this->assertEquals(['Download rate', 'Pages per visit'], array_column(Db::fetchAll('SELECT name FROM ' . self::TARGET_PREFIX . 'custom_metrics ORDER BY name'), 'name'));
        }
        if (PremiumPluginsFixture::isPluginActivated('SearchEngineKeywordsPerformance')) {
            // the data of the properties of the site, but not the day the target imported already
            $this->assertStringContainsString('Migrated 2 rows of google_stats, 1 rows the target had already at', $output);
            $this->assertStringContainsString('Migrated 3 rows of bing_stats at', $output);
            $this->assertStringContainsString('Migrated 2 rows of yandex_stats at', $output);
            $this->assertEquals(0, $this->fetchOne("SELECT COUNT(*) FROM %sgoogle_stats WHERE url = 'sc-domain:other.example.com'"));
            $this->assertStringContainsString('Note: The accounts of the search consoles', $output);
        }
        if (PremiumPluginsFixture::isPluginActivated('MediaAnalytics')) {
            $this->assertStringContainsString('Migrated 2 rows of log_media at', $output);
            $this->assertStringContainsString('Migrated 2 rows of log_media_plays at', $output);
        }
        if (PremiumPluginsFixture::isPluginActivated('AbTesting')) {
            $this->assertStringContainsString('Migrated 1 rows of experiments at', $output);
            $this->assertStringContainsString('Migrated 1 rows of experiments_strategy at', $output);
            $this->assertStringContainsString('Migrated 2 rows of experiments_variations at', $output);
            $this->assertStringContainsString('Migrated 3 rows of log_abtesting at', $output);
        }
        $this->assertTargetHasSameDataAsSource($this->getMaxTargetIdSite());
    }

    /**
     * @depends test_runMigration_migratesThePremiumPluginData
     */
    public function test_runMigration_translatesIdsThatAreTakenOnTheTarget()
    {
        $result = $this->runCommand();
        $this->assertEquals(0, $result, $this->applicationTester->getDisplay());
        $this->assertTargetHasSameDataAsSource($this->getMaxTargetIdSite());

        if (PremiumPluginsFixture::isPluginActivated('AdvertisingConversionExport')) {
            // the token the first migration copied is unique, the copy gets another one
            $tokens = array_column(Db::fetchAll('SELECT access_token FROM ' . self::TARGET_PREFIX . 'site_conversion_export'), 'access_token');
            $this->assertEquals(count($tokens), count(array_unique($tokens)));
            $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $this->fetchOne('SELECT access_token FROM %ssite_conversion_export WHERE idsite = ' . $this->getMaxTargetIdSite()));
        }
        if (PremiumPluginsFixture::isPluginActivated('HeatmapSessionRecording')) {
            // the texts copied by the first migration are reused
            $this->assertEquals(1 + 2, $this->fetchOne('SELECT COUNT(*) FROM %slog_hsr_blob'));
        }
        if (PremiumPluginsFixture::isPluginActivated('SearchEngineKeywordsPerformance')) {
            // the first migration copied them
            $this->assertStringContainsString('Migrated 0 rows of google_stats, 3 rows the target had already at', $this->applicationTester->getDisplay());
        }
        if (PremiumPluginsFixture::isPluginActivated('CustomReports')) {
            // the metric about the site the first migration copied is about the site it migrated to, the metric that
            // isn't about a site is used
            $this->assertEquals(
                ['Download rate' => '{{site' . ($this->getMaxTargetIdSite() - 1) . '_goal_1_conversion}} / {{nb_visits}}',
                    'Download rate (2)' => '{{site' . $this->getMaxTargetIdSite() . '_goal_1_conversion}} / {{nb_visits}}', 'Pages per visit' => '{{sum_actions}} / {{nb_visits}}'],
                array_column(Db::fetchAll('SELECT name, formula FROM ' . self::TARGET_PREFIX . 'custom_metrics ORDER BY name'), 'formula', 'name')
            );
        }
    }


    public function test_runMigration_skipPluginData_doesNotMigrateTheirData()
    {
        $plugins = $this->getPremiumPlugins();
        $result = $this->runCommand(['--skip-plugin-data' => implode(',', $plugins)]);
        $output = $this->applicationTester->getDisplay();
        $this->assertEquals(0, $result, $output);
        $idSite = $this->getMaxTargetIdSite();

        if (in_array('HeatmapSessionRecording', $plugins, true)) {
            $this->assertStringContainsString('The table site_hsr has data of this site that is not migrated', $output);
            $this->assertStringContainsString('The table log_hsr has data of this site that is not migrated', $output);
            $this->assertEquals(0, $this->fetchOne("SELECT COUNT(*) FROM %ssite_hsr WHERE idsite = $idSite"));
            $this->assertEquals(0, $this->fetchOne("SELECT COUNT(*) FROM %slog_hsr WHERE idsite = $idSite"));
        }
    }

    public function test_runMigration_rollUps_aggregateTheSitesTheirSitesWereMigratedTo()
    {
        if (!PremiumPluginsFixture::isPluginActivated('RollUpReporting')) {
            $this->markTestSkipped('Roll-Up Reporting is not installed');
        }
        $shopsRollUp = (int) Db::fetchOne("SELECT idsite FROM site WHERE name = 'Shops roll-up'");
        $allWebsitesRollUp = (int) Db::fetchOne("SELECT idsite FROM site WHERE name = 'All websites roll-up'");

        // the roll-ups of the site are not migrated yet
        $this->assertEquals(0, $this->runCommand(), $this->applicationTester->getDisplay());
        $this->assertStringContainsString(sprintf('The site belongs to the roll-ups "Shops roll-up" (ID %s), "All websites roll-up" (ID %s) of the source Matomo, which were not migrated yet', $shopsRollUp, $allWebsitesRollUp), $this->applicationTester->getDisplay());
        $site = $this->getMaxTargetIdSite();

        // the roll-up gets the site it was migrated to, and its settings like the plugin syncs them, as on the source
        Db::query("UPDATE site SET ecommerce = 0, ts_created = '2020-01-01 00:00:00' WHERE idsite = ?", [$shopsRollUp]);
        $this->assertEquals(0, $this->runCommand(['--source-idsite' => $shopsRollUp]), $this->applicationTester->getDisplay());
        $this->assertStringContainsString('The roll-up aggregates the sites "Other site" (ID 2) of the source Matomo, which were not migrated yet', $this->applicationTester->getDisplay());
        $targetShopsRollUp = $this->getMaxTargetIdSite();
        $this->assertEquals([$site], $this->getRollUpSites($targetShopsRollUp));
        $this->assertEquals(
            ['type' => 'rollup', 'ecommerce' => '1', 'ts_created' => $this->fetchOne("SELECT ts_created FROM %ssite WHERE idsite = $site")],
            Db::fetchRow('SELECT `type`, ecommerce, ts_created FROM ' . self::TARGET_PREFIX . 'site WHERE idsite = ?', [$targetShopsRollUp])
        );

        // the roll-up of all websites aggregates the sites of the target
        $this->assertEquals(0, $this->runCommand(['--source-idsite' => $allWebsitesRollUp]), $this->applicationTester->getDisplay());
        $this->assertStringContainsString('Note: The roll-up of all websites aggregates all sites of the target Matomo.', $this->applicationTester->getDisplay());
        $targetAllWebsitesRollUp = $this->getMaxTargetIdSite();
        $this->assertEquals($targetAllWebsitesRollUp, $this->fetchOne("SELECT option_value FROM %soption WHERE option_name = 'rollup_all_sites_idsite_$targetAllWebsitesRollUp'"));
        $this->assertEquals(
            array_map('intval', array_column(Db::fetchAll('SELECT idsite FROM ' . self::TARGET_PREFIX . "site WHERE `type` <> 'rollup' ORDER BY idsite"), 'idsite')),
            $this->getRollUpSites($targetAllWebsitesRollUp)
        );

        // a roll-up of all websites of the target only, which the plugin adds new sites to
        $rollUp = Db::fetchRow('SELECT * FROM site WHERE idsite = ?', [$allWebsitesRollUp]);
        unset($rollUp['idsite']);
        $targetRollUp = self::$fixture->targetDb->insert('site', array_merge($rollUp, ['name' => 'Target roll-up']));
        self::$fixture->targetDb->insert('option', ['option_name' => 'rollup_all_sites_idsite_' . $targetRollUp, 'option_value' => $targetRollUp, 'autoload' => 0]);

        // a site migrated afterwards is added to the roll-ups
        $this->assertEquals(0, $this->runCommand(), $this->applicationTester->getDisplay());
        $output = $this->applicationTester->getDisplay();
        $this->assertStringNotContainsString('which were not migrated yet', $output);
        $this->assertStringContainsString(sprintf(
            'Note: The site was added to the roll-ups %s, %s, %s of the target Matomo. To include its data in their reports, execute there "./console core:invalidate-report-data --sites=%s,%s,%s --dates=',
            $targetShopsRollUp,
            $targetAllWebsitesRollUp,
            $targetRollUp,
            $targetShopsRollUp,
            $targetAllWebsitesRollUp,
            $targetRollUp
        ), $output);
        $newSite = $this->getMaxTargetIdSite();
        $this->assertEquals([$site, $newSite], $this->getRollUpSites($targetShopsRollUp));
        $this->assertContains($newSite, $this->getRollUpSites($targetAllWebsitesRollUp));
        $this->assertEquals([$newSite], $this->getRollUpSites($targetRollUp));

        // a roll-up the target changed into a website doesn't get the sites migrated afterwards
        Db::query('UPDATE ' . self::TARGET_PREFIX . "site SET `type` = 'website' WHERE idsite = ?", [$targetShopsRollUp]);
        $this->assertEquals(0, $this->runCommand(), $this->applicationTester->getDisplay());
        $this->assertStringContainsString(sprintf('The site belongs to the roll-ups "Shops roll-up" (ID %s) of the source Matomo, which were not migrated yet', $shopsRollUp), $this->applicationTester->getDisplay());
        $this->assertEquals([$site, $newSite], $this->getRollUpSites($targetShopsRollUp));
    }

    /**
     * The roll-ups a site belongs to are other sites, which test_runMigration_rollUps_aggregateTheSitesTheirSitesWereMigratedTo
     * checks
     *
     * @return array<string, array<int, mixed>>
     */
    private function getRollUpReportingData(string $prefix, int $idSite): array
    {
        return [];
    }

    /**
     * @return int[]
     */
    private function getRollUpSites(int $idRollUp): array
    {
        return array_map('intval', array_column(Db::fetchAll('SELECT idsite FROM ' . self::TARGET_PREFIX . 'site_rollup WHERE parent_idsite = ? ORDER BY idsite', [$idRollUp]), 'idsite'));
    }

    /**
     * @param bool $siteDataOnly whether to only compare the data of the site, not the data of the visits and reports
     */
    private function assertTargetHasSameDataAsSource(int $targetIdSite, bool $siteDataOnly = false): void
    {
        foreach ($this->getPremiumPlugins() as $plugin) {
            $getData = [$this, 'get' . $plugin . 'Data'];
            $source = $getData('', 1);
            $target = $getData(self::TARGET_PREFIX, $targetIdSite);
            foreach ($source as $table => $rows) {
                if ($siteDataOnly && preg_match('/^(log|archive)_/', $table)) {
                    continue;
                }
                $this->assertNotEmpty($rows, $table);
                $this->assertEquals($rows, $target[$table], $table);
            }
        }
    }

    /**
     * The data of a site with the IDs replaced by what they refer to
     *
     * @return array<string, array<int, mixed>>
     */
    private function getFunnelsData(string $prefix, int $idSite): array
    {
        $funnel = "COALESCE(f.name, CONCAT('goal ', g.name))";
        $funnelJoin = "LEFT JOIN {$prefix}goal g ON g.idsite = f.idsite AND g.idgoal = f.idgoal";
        $funnelNames = [];
        foreach (Db::fetchAll("SELECT f.idfunnel, $funnel AS funnel FROM {$prefix}funnel f $funnelJoin WHERE f.idsite = ?", [$idSite]) as $row) {
            $funnelNames[$row['idfunnel']] = $row['funnel'];
        }

        $data = [];
        $data['funnel'] = $this->withoutIds(Db::fetchAll("SELECT f.*, g.name AS goal FROM {$prefix}funnel f $funnelJoin
            WHERE f.idsite = ? ORDER BY f.name, goal", [$idSite]), ['idfunnel', 'idgoal']);
        $data['funnel_steps'] = Db::fetchAll("SELECT $funnel AS funnel, s.position, s.name, s.pattern_type,
                IF(s.pattern_type = 'goal_equals', (SELECT CONCAT('goal ', sg.name) FROM {$prefix}goal sg WHERE sg.idsite = f.idsite AND sg.idgoal = s.pattern), s.pattern) AS pattern,
                s.required
            FROM {$prefix}funnel_steps s JOIN {$prefix}funnel f ON f.idfunnel = s.idfunnel $funnelJoin
            WHERE f.idsite = ? ORDER BY funnel, s.position", [$idSite]);
        $data['log_funnel'] = Db::fetchAll("SELECT CONCAT(HEX(v.idvisitor), ' ', v.visit_first_action_time) AS visit, $funnel AS funnel, l.step_position,
                a.name AS action, ap.name AS action_prev, an.name AS action_next, lva.server_time AS action_time, l.min_step, l.max_step, l.created_time
            FROM {$prefix}log_funnel l
            JOIN {$prefix}log_visit v ON v.idvisit = l.idvisit AND v.idsite = l.idsite
            LEFT JOIN {$prefix}funnel f ON f.idfunnel = l.idfunnel AND f.idsite = l.idsite $funnelJoin
            LEFT JOIN {$prefix}log_action a ON a.idaction = l.idaction
            LEFT JOIN {$prefix}log_action ap ON ap.idaction = l.idaction_prev
            LEFT JOIN {$prefix}log_action an ON an.idaction = l.idaction_next
            LEFT JOIN {$prefix}log_link_visit_action lva ON lva.idlink_va = l.idlink_va AND lva.idvisit = l.idvisit
            WHERE l.idsite = ? ORDER BY visit, funnel, l.step_position", [$idSite]);
        $goalNames = array_column(Db::fetchAll("SELECT idgoal, name FROM {$prefix}goal WHERE idsite = ?", [$idSite]), 'name', 'idgoal');
        $data['segment'] = $this->getSegments($prefix, $idSite, 'funnels_name', $funnelNames);
        $data = array_merge($data, $this->getArchiveRecords($prefix, $idSite, 'Funnels_', function ($name) use ($funnelNames) {
            return preg_replace_callback('/^(Funnels_(?:entries|exits|flow|funnel_sum_entries|funnel_sum_exits|funnel_nb_conversions)_)(\d+)(?=_|$)/', function ($matches) use ($funnelNames) {
                return $matches[1] . ($funnelNames[$matches[2]] ?? 'missing');
            }, $name);
        }));

        return $data;
    }

    /**
     * The data of a site with the IDs replaced by what they refer to
     *
     * @return array<string, array<int, mixed>>
     */
    private function getFormAnalyticsData(string $prefix, int $idSite): array
    {
        $formNames = array_column(Db::fetchAll("SELECT idsiteform, name FROM {$prefix}site_form WHERE idsite = ?", [$idSite]), 'name', 'idsiteform');
        $formView = "CONCAT(HEX(v.idvisitor), ' ', v.visit_first_action_time, ' ', f.name, ' ', l.first_idformview)";
        $formViewJoin = "JOIN {$prefix}log_visit v ON v.idvisit = l.idvisit AND v.idsite = l.idsite
            LEFT JOIN {$prefix}site_form f ON f.idsiteform = l.idsiteform AND f.idsite = l.idsite";

        $data = [];
        $data['site_form'] = $this->withoutIds(Db::fetchAll("SELECT f.*, g.name AS goal FROM {$prefix}site_form f
            LEFT JOIN {$prefix}goal g ON g.idsite = f.idsite AND g.idgoal = f.idgoal WHERE f.idsite = ? ORDER BY f.name", [$idSite]), ['idsiteform', 'idgoal']);
        $data['log_form'] = $this->withoutIds(Db::fetchAll("SELECT l.*, $formView AS form_view FROM {$prefix}log_form l $formViewJoin
            WHERE l.idsite = ? ORDER BY form_view", [$idSite]), ['idlogform', 'idsiteform']);
        $data['log_form_page'] = $this->withoutIds(Db::fetchAll("SELECT p.*, $formView AS form_view, a.name AS url
            FROM {$prefix}log_form_page p JOIN {$prefix}log_form l ON l.idlogform = p.idlogform $formViewJoin
            LEFT JOIN {$prefix}log_action a ON a.idaction = p.idaction_url
            WHERE l.idsite = ? ORDER BY form_view, url", [$idSite]), ['idlogformpage', 'idlogform']);
        $data['log_form_field'] = $this->withoutIds(Db::fetchAll("SELECT ff.*, $formView AS form_view, a.name AS url
            FROM {$prefix}log_form_field ff JOIN {$prefix}log_form l ON l.idlogform = ff.idlogform $formViewJoin
            LEFT JOIN {$prefix}log_form_page p ON p.idlogformpage = ff.idlogformpage AND p.idlogform = ff.idlogform
            LEFT JOIN {$prefix}log_action a ON a.idaction = p.idaction_url
            WHERE l.idsite = ? ORDER BY form_view, ff.field_name, ff.idformview", [$idSite]), ['idlogform', 'idlogformpage']);
        $data['segment'] = $this->getSegments($prefix, $idSite, 'form_name', $formNames);
        $data = array_merge($data, $this->getArchiveRecords($prefix, $idSite, 'FormAnalytics_', function ($name) use ($formNames) {
            return preg_replace_callback('/^(FormAnalytics_[a-z_]*?_)(\d+)(?=_|$)/', function ($matches) use ($formNames) {
                return $matches[1] . ($formNames[$matches[2]] ?? 'missing');
            }, $name);
        }));

        return $data;
    }

    /**
     * The data of a site with the IDs replaced by what they refer to
     *
     * @return array<string, array<int, mixed>>
     */
    private function getAbTestingData(string $prefix, int $idSite): array
    {
        $goalNames = array_column(Db::fetchAll("SELECT idgoal, name FROM {$prefix}goal WHERE idsite = ?", [$idSite]), 'name', 'idgoal');
        $replaceGoals = function (string $text) use ($goalNames) {
            return preg_replace_callback('/_goal_(\d+)/', function ($matches) use ($goalNames) {
                return '_goal_' . ($goalNames[$matches[1]] ?? 'missing');
            }, $text);
        };
        $experimentNames = array_column(Db::fetchAll("SELECT idexperiment, name FROM {$prefix}experiments WHERE idsite = ?", [$idSite]), 'name', 'idexperiment');
        $variationNames = array_column(Db::fetchAll("SELECT v.idvariation, CONCAT(e.name, ' ', v.name) AS name FROM {$prefix}experiments_variations v
            JOIN {$prefix}experiments e ON e.idexperiment = v.idexperiment WHERE e.idsite = ?", [$idSite]), 'name', 'idvariation');

        $data = [];
        $data['experiments'] = array_map(function ($row) use ($replaceGoals) {
            $row['success_metrics'] = $replaceGoals($row['success_metrics']);
            return $row;
        }, $this->withoutIds(Db::fetchAll("SELECT * FROM {$prefix}experiments WHERE idsite = ? ORDER BY name", [$idSite]), ['idexperiment']));
        $data['experiments_strategy'] = array_map(function ($row) use ($replaceGoals) {
            $row['metric'] = $replaceGoals($row['metric']);
            return $row;
        }, Db::fetchAll("SELECT e.name AS experiment, s.metric, s.strategy FROM {$prefix}experiments_strategy s
            JOIN {$prefix}experiments e ON e.idexperiment = s.idexperiment WHERE e.idsite = ?", [$idSite]));
        sort($data['experiments_strategy']);
        $data['experiments_variations'] = $this->withoutIds(Db::fetchAll("SELECT v.*, e.name AS experiment FROM {$prefix}experiments_variations v
            JOIN {$prefix}experiments e ON e.idexperiment = v.idexperiment WHERE e.idsite = ? ORDER BY experiment, v.name", [$idSite]), ['idvariation', 'idexperiment']);
        $data['log_abtesting'] = Db::fetchAll("SELECT CONCAT(HEX(v.idvisitor), ' ', v.visit_first_action_time) AS visit, e.name AS experiment,
                IF(l.idvariation = 0, 'original', va.name) AS variation, l.entered, l.server_time, HEX(l.idvisitor) AS visitor
            FROM {$prefix}log_abtesting l
            JOIN {$prefix}log_visit v ON v.idvisit = l.idvisit AND v.idsite = l.idsite
            LEFT JOIN {$prefix}experiments e ON e.idexperiment = l.idexperiment AND e.idsite = l.idsite
            LEFT JOIN {$prefix}experiments_variations va ON va.idvariation = l.idvariation AND va.idexperiment = l.idexperiment
            WHERE l.idsite = ? ORDER BY visit", [$idSite]);
        $data['archive_blob_2013_01 AbTesting_'] = $this->getReportRows($prefix, $idSite, 'AbTesting_', function ($name) use ($experimentNames, $replaceGoals) {
            return $replaceGoals((string) preg_replace_callback('/^(AbTesting_[a-z_]*?_)(\d+)(?=_|$)/', function ($matches) use ($experimentNames) {
                return $matches[1] . ($experimentNames[$matches[2]] ?? 'missing');
            }, $name));
        }, function ($label) use ($variationNames) {
            return preg_replace_callback('/^(\d+)(?=_|$)/', function ($matches) use ($variationNames) {
                return $matches[1] === '0' ? 'original' : ($variationNames[$matches[1]] ?? 'missing');
            }, $label);
        });

        return $data;
    }

    /**
     * The data of a site with the IDs replaced by what they refer to
     *
     * @return array<string, array<int, mixed>>
     */
    private function getMediaAnalyticsData(string $prefix, int $idSite): array
    {
        $visit = "CONCAT(HEX(v.idvisitor), ' ', v.visit_first_action_time)";
        return [
            'log_media' => $this->withoutIds(Db::fetchAll("SELECT m.*, $visit AS visit FROM {$prefix}log_media m
                JOIN {$prefix}log_visit v ON v.idvisit = m.idvisit AND v.idsite = m.idsite WHERE m.idsite = ? ORDER BY visit, m.idview", [$idSite])),
            'log_media_plays' => $this->withoutIds(Db::fetchAll("SELECT p.*, $visit AS visit FROM {$prefix}log_media_plays p
                JOIN {$prefix}log_visit v ON v.idvisit = p.idvisit WHERE v.idsite = ? ORDER BY visit, p.idview", [$idSite])),
        ];
    }

    /**
     * The data of a site with the IDs replaced by what they refer to
     *
     * @return array<string, array<int, mixed>>
     */
    private function getSEOWebVitalsData(string $prefix, int $idSite): array
    {
        return [
            // Stored per website, independently of visits.
            'webvitals_report' => $this->withoutIds(Db::fetchAll("SELECT * FROM {$prefix}log_webvitals_report
                WHERE idsite = ? ORDER BY date, strategy, url", [$idSite]), ['idlogwebvitalsreport']),
        ];
    }

    /**
     * The data of a site with the IDs replaced by what they refer to
     *
     * @return array<string, array<int, mixed>>
     */
    private function getCrashAnalyticsData(string $prefix, int $idSite): array
    {
        $crashNames = array_column(Db::fetchAll("SELECT idlogcrash, message FROM {$prefix}log_crash WHERE idsite = ?", [$idSite]), 'message', 'idlogcrash');

        $data = [];
        $data['log_crash'] = $this->withoutIds(Db::fetchAll("SELECT c.*, g.message AS group_crash, c.group_idlogcrash IS NOT NULL AS has_group
            FROM {$prefix}log_crash c LEFT JOIN {$prefix}log_crash g ON g.idlogcrash = c.group_idlogcrash AND g.idsite = c.idsite
            WHERE c.idsite = ? ORDER BY c.message", [$idSite]), ['idlogcrash', 'group_idlogcrash']);
        // the group is shown as the crash with the lowest ID, which references itself
        $this->assertEquals([null, 'TypeError: a is undefined', 'TypeError: a is undefined'], array_column($data['log_crash'], 'group_crash'));
        $data['log_crash_group'] = $this->withoutIds(Db::fetchAll("SELECT g.*, c.message AS crash FROM {$prefix}log_crash_group g
            JOIN {$prefix}log_crash c ON c.idlogcrash = g.idlogcrash WHERE c.idsite = ? ORDER BY crash", [$idSite]), ['idlogcrash']);
        $data['log_crash_event'] = $this->withoutIds(Db::fetchAll("SELECT e.*, CONCAT(HEX(v.idvisitor), ' ', v.visit_first_action_time) AS visit, c.message AS crash,
                s.hash, s.compressed, HEX(s.value) AS stack, ar.name AS resource_uri, au.name AS url, an.name AS page_title
            FROM {$prefix}log_crash_event e
            JOIN {$prefix}log_visit v ON v.idvisit = e.idvisit AND v.idsite = e.idsite
            LEFT JOIN {$prefix}log_crash c ON c.idlogcrash = e.idlogcrash AND c.idsite = e.idsite
            LEFT JOIN {$prefix}log_crash_stack s ON s.idlogcrashstack = e.idlogcrashstack
            LEFT JOIN {$prefix}log_action ar ON ar.idaction = e.idaction_resource_uri
            LEFT JOIN {$prefix}log_action au ON au.idaction = e.idaction_url
            LEFT JOIN {$prefix}log_action an ON an.idaction = e.idaction_name
            WHERE e.idsite = ? ORDER BY visit, e.server_time, crash", [$idSite]), ['idlogcrashevent', 'idlogcrash', 'idlogcrashstack', 'idaction_resource_uri', 'idaction_name']);
        $data['segment'] = $this->getSegments($prefix, $idSite, 'crashId', $crashNames);
        $data['archive_blob_2013_01 CrashAnalytics_'] = $this->getReportRows($prefix, $idSite, 'CrashAnalytics_', function ($name) {
            return $name;
        }, function ($label) {
            return $label;
        }, ['idlogcrash' => function ($idLogCrash) use ($crashNames) {
            return ($crashNames[$idLogCrash] ?? 'missing') . ' ' . gettype($idLogCrash);
        }]);
        if ($prefix === '') {
            $this->assertContains('ReferenceError: c is not defined string', array_column(array_column($data['archive_blob_2013_01 CrashAnalytics_'], 5), 'idlogcrash'));
        }

        return $data;
    }

    /**
     * The data of a site with the IDs replaced by what they refer to
     *
     * @return array<string, array<int, mixed>>
     */
    private function getAdvertisingConversionExportData(string $prefix, int $idSite): array
    {
        $goalNames = array_column(Db::fetchAll("SELECT idgoal, name FROM {$prefix}goal WHERE idsite = ?", [$idSite]), 'name', 'idgoal');
        $data = [];
        // the access token is compared by the tests
        $data['site_conversion_export'] = array_map(function ($row) use ($goalNames) {
            $parameters = json_decode($row['parameters'], true);
            foreach ($parameters['goals'] as $i => $goal) {
                $parameters['goals'][$i]['idgoal'] = ($goalNames[$goal['idgoal']] ?? 'missing') . ' ' . gettype($goal['idgoal']);
            }
            $parameters['segment'] = preg_replace_callback('/(visitConvertedGoalId==)(\d+)/', function ($matches) use ($goalNames) {
                return $matches[1] . ($goalNames[$matches[2]] ?? 'missing');
            }, $parameters['segment']);
            $row['parameters'] = $parameters;
            return $row;
        }, $this->withoutIds(Db::fetchAll("SELECT * FROM {$prefix}site_conversion_export WHERE idsite = ? ORDER BY name", [$idSite]), ['idexport', 'access_token']));
        $this->assertEquals('Download Software integer', $data['site_conversion_export'][0]['parameters']['goals'][0]['idgoal']);
        $data['log_clickid'] = Db::fetchAll("SELECT CONCAT(HEX(v.idvisitor), ' ', v.visit_first_action_time) AS visit, HEX(c.idvisitor) AS visitor, c.adclickid,
                c.adprovider, c.server_time
            FROM {$prefix}log_clickid c JOIN {$prefix}log_visit v ON v.idvisit = c.idvisit WHERE v.idsite = ? ORDER BY visit", [$idSite]);
        return $data;
    }

    /**
     * The data of a site with the IDs replaced by what they refer to
     *
     * @return array<string, array<int, mixed>>
     */
    private function getCustomReportsData(string $prefix, int $idSite): array
    {
        $goalNames = array_column(Db::fetchAll("SELECT idgoal, name FROM {$prefix}goal WHERE idsite = ?", [$idSite]), 'name', 'idgoal');
        $dimensionNames = array_column(Db::fetchAll("SELECT idcustomdimension, name FROM {$prefix}custom_dimensions WHERE idsite = ?", [$idSite]), 'name', 'idcustomdimension');
        $funnelNames = PremiumPluginsFixture::isPluginActivated('Funnels')
            ? array_column(Db::fetchAll("SELECT idfunnel, name FROM {$prefix}funnel WHERE idsite = ?", [$idSite]), 'name', 'idfunnel') : [];

        // on the source, the reports of all websites and of several websites are shown for the site too
        $reports = Db::fetchAll("SELECT * FROM {$prefix}custom_reports WHERE idsite = ? OR (? = '' AND (idsite = 0 OR (idsite = -1 AND FIND_IN_SET(?, multiple_idsites))))
            ORDER BY name, idsite DESC", [$idSite, $prefix, $idSite]);
        $siteReportNames = array_column(array_filter($reports, function ($report) use ($idSite) {
            return $report['idsite'] == $idSite;
        }), 'name');
        // the site can't have a report of several sites having the name of a report of the site
        $reports = array_values(array_filter($reports, function ($report) use ($idSite, $siteReportNames) {
            return $report['idsite'] == $idSite || !in_array($report['name'], $siteReportNames, true);
        }));
        $reportNames = array_column($reports, 'name', 'idcustomreport');

        $replaceGoalsAndDimensions = function (string $metric) use ($goalNames, $dimensionNames) {
            $metric = preg_replace_callback('/^goal_(\d+)_/', function ($matches) use ($goalNames) {
                return 'goal ' . ($goalNames[$matches[1]] ?? 'missing') . '_';
            }, $metric);
            return preg_replace_callback('/customdimension_customdimension(\d+)$/', function ($matches) use ($dimensionNames) {
                return 'dimension ' . ($dimensionNames[$matches[1]] ?? 'missing');
            }, (string) $metric);
        };
        $metrics = [];
        foreach (Db::fetchAll("SELECT * FROM {$prefix}custom_metrics ORDER BY name") as $metric) {
            $id = $metric['idcustommetric'];
            // a copy of a metric whose name the target has for another metric gets a number appended
            $metric['name'] = preg_replace('/ \(\d+\)$/', '', $metric['name']);
            // the site a metric is about is named in its formula
            $metric['formula'] = preg_replace_callback('/\{\{site(\d+)_([a-zA-Z0-9_]+)\}\}/', function ($matches) use ($idSite, $replaceGoalsAndDimensions) {
                return '{{' . ($matches[1] == $idSite ? 'this site' : 'site ' . $matches[1]) . ' ' . $replaceGoalsAndDimensions($matches[2]) . '}}';
            }, $metric['formula']);
            unset($metric['idcustommetric']);
            $metrics['custom_metric_' . $id] = $metric;
        }
        $replaceMetric = function (string $metric) use ($metrics, $replaceGoalsAndDimensions) {
            return isset($metrics[$metric]) ? 'custom metric ' . $metrics[$metric]['name'] . ' ' . $metrics[$metric]['formula'] : $replaceGoalsAndDimensions($metric);
        };

        $data = [];
        $data['custom_reports'] = array_map(function ($report) use ($prefix, $reportNames, $goalNames, $dimensionNames, $funnelNames, $replaceMetric) {
            $report['dimensions'] = preg_replace_callback('/^CustomDimension\.CustomDimension(\d+)$/', function ($matches) use ($dimensionNames) {
                return 'dimension ' . ($dimensionNames[$matches[1]] ?? 'missing');
            }, json_decode($report['dimensions'], true));
            $report['metrics'] = array_map($replaceMetric, json_decode($report['metrics'], true));
            $report['segment_filter'] = preg_replace_callback('/(visitConvertedGoalId==)(\d+)/', function ($matches) use ($goalNames) {
                return $matches[1] . ($goalNames[$matches[2]] ?? 'missing');
            }, $report['segment_filter']);
            $pages = ['CustomReports_CustomReports' => $reportNames, 'Goals_Goals' => $goalNames, 'Funnels_Funnels' => $funnelNames];
            if (isset($pages[$report['category']]) && $report['subcategory'] !== '') {
                $report['subcategory'] = 'page of ' . ($pages[$report['category']][$report['subcategory']] ?? 'missing');
            } elseif (preg_match('/^customdimension(\d+)$/', $report['subcategory'], $matches)) {
                $report['subcategory'] = 'page of dimension ' . ($dimensionNames[$matches[1]] ?? 'missing');
            }
            if ($prefix === '') {
                // copied as reports of the site
                $report['multiple_idsites'] = $report['multiple_idsites'] === null ? null : '';
            }
            unset($report['idcustomreport'], $report['idsite']);
            return $report;
        }, $reports);
        $data['custom_metrics'] = array_values(array_filter($metrics, function ($metric) use ($data) {
            return in_array('custom metric ' . $metric['name'] . ' ' . $metric['formula'], array_merge(...array_column($data['custom_reports'], 'metrics')), true);
        }));

        // the records of the reports of the site
        $replaceReport = function (string $name) use ($reportNames) {
            return preg_replace_callback('/^(CustomReports_customreport_)(\d+)(?=_)/', function ($matches) use ($reportNames) {
                return $matches[1] . ($reportNames[$matches[2]] ?? 'missing');
            }, $name);
        };
        foreach ($this->getArchiveRecords($prefix, $idSite, 'CustomReports_', $replaceReport) as $archiveTable => $records) {
            $data[$archiveTable] = array_values(array_filter($records, function ($record) {
                return strpos($record[3], 'missing') === false;
            }));
        }
        $data['archive_blob_2013_01 CustomReports_'] = array_values(array_filter($this->getReportRows($prefix, $idSite, 'CustomReports_', $replaceReport, function ($label) {
            return $label;
        }, [], function ($column) use ($replaceMetric) {
            return $replaceMetric($column);
        }), function ($row) {
            return strpos($row[3], 'missing') === false;
        }));
        if ($prefix === '') {
            $this->assertContains('custom metric Pages per visit {{sum_actions}} / {{nb_visits}}', array_keys(array_merge(...array_column($data['archive_blob_2013_01 CustomReports_'], 5))));
        }

        return $data;
    }

    /**
     * The data imported for the properties named by the source site's settings.
     *
     * @return array<string, array<int, mixed>>
     */
    private function getSearchEngineKeywordsPerformanceData(string $prefix, int $idSite): array
    {
        $settings = array_column(Db::fetchAll("SELECT setting_name, setting_value FROM site_setting WHERE idsite = 1 AND plugin_name = 'SearchEngineKeywordsPerformance'"), 'setting_value', 'setting_name');
        $encryption = new \Piwik\Plugins\SearchEngineKeywordsPerformance\Encryption();
        $data = [];
        foreach (['google_stats' => 'searchconsoleurl', 'bing_stats' => 'bingsiteurl', 'yandex_stats' => 'yandexAccountAndHostId'] as $table => $setting) {
            [, $property] = explode('##', $encryption->decryptString($settings[$setting]));
            $data[$table] = Db::fetchAll("SELECT url, `date`, type, HEX(data) AS data FROM {$prefix}$table WHERE url = ? ORDER BY `date`, type", [$property]);
        }
        $this->assertEquals('0000-00-00', $data['bing_stats'][0]['date']);
        return $data;
    }

    /**
     * The rows of the reports of a plugin stored in blob archive records, with the IDs the names of the records and the
     * labels of the rows hold replaced by what they refer to
     *
     * @param callable(string): string $replaceIdsInName
     * @param callable(string): string $replaceIdsInLabel
     * @param array<string, callable(mixed): mixed> $replaceIdsInColumns
     * @param (callable(string): string)|null $replaceIdsInColumnNames
     * @return array<int, array<int, mixed>>
     */
    private function getReportRows(string $prefix, int $idSite, string $namePrefix, callable $replaceIdsInName, callable $replaceIdsInLabel, array $replaceIdsInColumns = [], ?callable $replaceIdsInColumnNames = null): array
    {
        $rows = [];
        foreach (Db::fetchAll("SELECT name, period, date1, date2, value FROM {$prefix}archive_blob_2013_01 WHERE idsite = ? AND name LIKE ?", [$idSite, $namePrefix . '%']) as $record) {
            $data = @gzuncompress($record['value']);
            $data = $data === false ? $record['value'] : $data;
            $tables = strpos($record['name'], '_chunk_') !== false ? unserialize($data) : [$data];
            foreach ($tables as $serializedRows) {
                foreach (unserialize($serializedRows) as $idRow => $row) {
                    $columns = $row[Row::COLUMNS];
                    if ($idRow >= 0 && isset($columns['label'])) {
                        $columns['label'] = $replaceIdsInLabel((string) $columns['label']);
                    }
                    foreach ($replaceIdsInColumns as $column => $replaceIds) {
                        if (array_key_exists($column, $columns)) {
                            $columns[$column] = $replaceIds($columns[$column]);
                        }
                    }
                    if ($replaceIdsInColumnNames !== null) {
                        $columns = array_combine(array_map($replaceIdsInColumnNames, array_map('strval', array_keys($columns))), $columns);
                    }
                    $rows[] = [$record['period'], $record['date1'], $record['date2'], $replaceIdsInName($record['name']), $idRow < 0 ? $idRow : 0, $columns];
                }
            }
        }
        sort($rows);
        return $rows;
    }

    /**
     * The data of a site with the IDs replaced by what they refer to
     *
     * @return array<string, array<int, mixed>>
     */
    private function getMultiChannelConversionAttributionData(string $prefix, int $idSite): array
    {
        return [
            'goal_attribution' => Db::fetchAll("SELECT g.name AS goal FROM {$prefix}goal_attribution ga
                LEFT JOIN {$prefix}goal g ON g.idsite = ga.idsite AND g.idgoal = ga.idgoal WHERE ga.idsite = ? ORDER BY goal", [$idSite]),
        ];
    }

    /**
     * The segments of a site using a segment of a plugin, with the IDs it selects replaced by the names of the rows
     *
     * @param array<int|string, string> $names
     * @return array<int, array<string, mixed>>
     */
    private function getSegments(string $prefix, int $idSite, string $segment, array $names): array
    {
        return array_map(function ($row) use ($segment, $names) {
            $this->assertEquals(md5(urldecode($row['definition'])), $row['hash'], 'the archives of a segment are found by its hash');
            $row['definition'] = preg_replace_callback('/(' . $segment . '(?:==|!=))(\d+)/', function ($matches) use ($names) {
                return $matches[1] . ($names[$matches[2]] ?? 'missing');
            }, $row['definition']);
            unset($row['hash']);
            return $row;
        }, Db::fetchAll("SELECT name, definition, hash, deleted FROM {$prefix}segment WHERE enable_only_idsite = ? AND definition LIKE ? ORDER BY name", [$idSite, $segment . '%']));
    }

    /**
     * The archive records of a plugin, with the IDs their names contain replaced by what they refer to
     *
     * @param callable(string): string $replaceIds
     * @return array<string, array<int, array<int, mixed>>>
     */
    private function getArchiveRecords(string $prefix, int $idSite, string $namePrefix, callable $replaceIds): array
    {
        $data = [];
        foreach (['archive_numeric_2013_01', 'archive_blob_2013_01'] as $archiveTable) {
            $records = [];
            foreach (Db::fetchAll("SELECT name, period, date1, date2, value FROM {$prefix}$archiveTable WHERE idsite = ? AND name LIKE ?", [$idSite, $namePrefix . '%']) as $row) {
                $records[] = [$row['period'], $row['date1'], $row['date2'], $replaceIds($row['name']), $row['value']];
            }
            sort($records);
            $data[$archiveTable . ' ' . $namePrefix] = $records;
        }
        return $data;
    }

    /**
     * The data of a site with the IDs replaced by what they refer to
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function getHeatmapSessionRecordingData(string $prefix, int $idSite): array
    {
        $recording = "CONCAT(HEX(v.idvisitor), ' ', v.visit_first_action_time, ' ', l.idhsrview)";

        $data = [];
        $data['site_hsr'] = $this->withoutIds(Db::fetchAll("SELECT h.*, s.name AS repeat_source, h.repeat_source_idsitehsr IS NOT NULL AS has_repeat_source
            FROM {$prefix}site_hsr h LEFT JOIN {$prefix}site_hsr s ON s.idsitehsr = h.repeat_source_idsitehsr AND s.idsite = h.idsite
            WHERE h.idsite = ? ORDER BY h.name, h.idsitehsr", [$idSite]), ['repeat_source_idsitehsr']);
        $data['log_hsr'] = $this->withoutIds(Db::fetchAll("SELECT l.*, $recording AS recording, a.name AS url
            FROM {$prefix}log_hsr l
            JOIN {$prefix}log_visit v ON v.idvisit = l.idvisit AND v.idsite = l.idsite
            LEFT JOIN {$prefix}log_action a ON a.idaction = l.idaction_url
            WHERE l.idsite = ? ORDER BY recording", [$idSite]));
        $data['log_hsr_site'] = Db::fetchAll("SELECT $recording AS recording, h.name AS hsr
            FROM {$prefix}log_hsr_site ls
            JOIN {$prefix}log_hsr l ON l.idloghsr = ls.idloghsr
            JOIN {$prefix}log_visit v ON v.idvisit = l.idvisit AND v.idsite = l.idsite
            LEFT JOIN {$prefix}site_hsr h ON h.idsitehsr = ls.idsitehsr AND h.idsite = l.idsite
            WHERE l.idsite = ? ORDER BY recording, hsr", [$idSite]);
        $data['log_hsr_event'] = $this->withoutIds(Db::fetchAll("SELECT e.*, $recording AS recording, s.name AS selector, s.type AS selector_type,
                b.hash, b.compressed, HEX(b.value) AS text
            FROM {$prefix}log_hsr_event e
            JOIN {$prefix}log_hsr l ON l.idloghsr = e.idloghsr
            JOIN {$prefix}log_visit v ON v.idvisit = l.idvisit AND v.idsite = l.idsite
            LEFT JOIN {$prefix}log_action s ON s.idaction = e.idselector
            LEFT JOIN {$prefix}log_hsr_blob b ON b.idhsrblob = e.idhsrblob
            WHERE l.idsite = ? ORDER BY recording, e.time_since_load, e.event_type", [$idSite]), ['idselector', 'idhsrblob']);

        // the copy of a heatmap references it, also when it was deleted
        $this->assertEquals(
            [['name' => 'Deleted heatmap', 'status' => 'deleted', 'repeat_source' => null], ['name' => 'Products heatmap', 'status' => 'ended', 'repeat_source' => null]],
            array_values(array_map(function ($row) {
                return ['name' => $row['name'], 'status' => $row['status'], 'repeat_source' => $row['repeat_source']];
            }, array_filter($data['site_hsr'], function ($row) {
                return in_array($row['name'], ['Deleted heatmap', 'Products heatmap'], true);
            })))
        );
        $this->assertEquals(['Deleted heatmap', 'Products heatmap'], array_values(array_filter(array_column($data['site_hsr'], 'repeat_source'))));

        return $data;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param string[] $otherIdColumns
     * @return array<int, array<string, mixed>>
     */
    private function withoutIds(array $rows, array $otherIdColumns = []): array
    {
        return array_map(function ($row) use ($otherIdColumns) {
            foreach (array_merge(['idsite', 'idvisit', 'idaction_url', 'idloghsr', 'idsitehsr', 'idhsrevent'], $otherIdColumns) as $column) {
                unset($row[$column]);
            }
            return $row;
        }, $rows);
    }

    /**
     * @return string[]
     */
    private function getPremiumPlugins(): array
    {
        return PremiumPluginsFixture::getActivatedPremiumPlugins();
    }

    private function prefix(string $table): string
    {
        return self::TARGET_PREFIX . $table;
    }

    private function getMaxTargetIdSite(): int
    {
        return (int) $this->fetchOne('SELECT MAX(idsite) FROM %ssite');
    }

    /**
     * @return mixed
     */
    private function fetchOne(string $sql)
    {
        return Db::fetchOne(sprintf($sql, self::TARGET_PREFIX));
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

MigratePremiumPluginsTest::$fixture = new PremiumPluginsFixture();
