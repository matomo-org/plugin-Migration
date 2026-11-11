<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\tests\System\Commands;

use Piwik\Access;
use Piwik\DataAccess\ArchiveTableCreator;
use Piwik\Db;
use Piwik\Option;
use Piwik\Plugins\Migration\Migrations\ArchiveMigration;
use Piwik\Plugins\Migration\Migrations\Request;
use Piwik\Plugins\PrivacyManager\PrivacyManager;
use Piwik\Plugins\Migration\tests\Fixtures\MigrationFixture;
use Piwik\Config;
use Piwik\Tests\Framework\Mock\FakeAccess;
use Piwik\Tests\Framework\TestCase\ConsoleCommandTestCase;
use Piwik\Version;
use Piwik\Site;

/**
 * @group Migration
 * @group MigrateExistingSiteTest
 * @group Plugins
 */
class MigrateExistingSiteTest extends ConsoleCommandTestCase
{
    /**
     * @var MigrationFixture
     */
    public static $fixture = null; // initialized below class definition

    private function createTargetSite(string $name, string $timezone): int
    {
        $row = Db::fetchRow('SELECT * FROM site WHERE idsite = 1');
        unset($row['idsite']);
        $row['name'] = $name;
        $row['main_url'] = 'https://existing.example.com';
        $row['timezone'] = $timezone;
        $row['ts_created'] = '2020-01-01 00:00:00';
        return self::$fixture->targetDb->insert('site', $row);
    }

    private function getTargetSiteCounts(int $idSite): array
    {
        $targetDb = self::$fixture->targetDb;
        $count = function ($table, $column = 'idsite') use ($targetDb, $idSite) {
            if (!$targetDb->doesTableExist($targetDb->prefixTable($table))) {
                return 0;
            }
            return (int) $targetDb->getDb()->fetchOne('SELECT COUNT(*) FROM ' . $targetDb->prefixTable($table) . " WHERE $column = ?", [$idSite]);
        };
        return [
            'visits' => $count('log_visit'),
            'goals' => $count('goal'),
            'dimensions' => $count('custom_dimensions'),
            'segments' => $count('segment', 'enable_only_idsite'),
            'annotations' => $count('annotations'),
            'archives' => $count('archive_numeric_2013_01'),
        ];
    }

    private function runCommand(array $options = [])
    {
        $config = Db::getDatabaseConfig();
        $result = $this->applicationTester->run(array_merge([
            'command' => 'migration:measurable',
            '--source-idsite' => 1,
            '--target-db-host' => $config['host'],
            '--target-db-username' => $config['username'],
            '--target-db-password' => $config['password'],
            '--target-db-name' => $config['dbname'],
            '--target-db-prefix' => MigrationFixture::TARGET_DB_PREFIX,
            '--no-interaction' => true,
        ], $options));
        return $result;
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

    public function test_runMigration_targetIdSite_migratesIntoAnExistingSite()
    {
        $targetDb = self::$fixture->targetDb;
        $db = $targetDb->getDb();
        $siteTable = $targetDb->prefixTable('site');
        $idSite = $this->createTargetSite('Existing site', 'UTC');

        // Copied archive history may predate the first retained visit.
        $firstVisit = min(
            Db::fetchOne('SELECT MIN(visit_first_action_time) FROM log_visit WHERE idsite = 1'),
            Db::fetchOne('SELECT ts_created FROM site WHERE idsite = 1')
        );

        // into the site without data, the reports can be copied
        $result = $this->runCommand(['--target-idsite' => $idSite, '--target-site-is-offline' => true]);
        $output = $this->applicationTester->getDisplay();
        $this->assertEquals(0, $result, $output);
        $this->assertStringContainsString('Target site is the existing site ' . $idSite . ' at', $output);
        $this->assertStringContainsString('Changed the creation date of the target site to ' . $firstVisit . ' to include migrated history', $output);
        $this->assertStringNotContainsString('The reports are not copied', $output);

        $site = $db->fetchRow("SELECT * FROM $siteTable WHERE idsite = ?", [$idSite]);
        $this->assertEquals('Existing site', $site['name']);
        $this->assertEquals('https://existing.example.com', $site['main_url']);
        $this->assertEquals($firstVisit, $site['ts_created']);
        $this->assertEquals($idSite, $db->fetchOne("SELECT MAX(idsite) FROM $siteTable"), 'no site is created');
        $this->assertEquals(
            ['http://piwik.net', 'https://www.example1.com', 'https://www.example2.net'],
            $db->fetchCol('SELECT url FROM ' . $targetDb->prefixTable('site_url') . ' WHERE idsite = ? ORDER BY url', [$idSite])
        );
        $counts = $this->getTargetSiteCounts($idSite);
        // the segment of all websites a scheduled report uses is a segment of the site there
        $this->assertEquals(['visits' => 2, 'goals' => 4, 'dimensions' => 3, 'segments' => 2, 'annotations' => 1], array_diff_key($counts, ['archives' => 0]));
        $this->assertGreaterThan(0, $counts['archives']);

        // migrating again, eg another period, doesn't duplicate the configuration and doesn't copy the reports over the data
        $result = $this->runCommand(['--target-idsite' => $idSite]);
        $output = $this->applicationTester->getDisplay();
        $this->assertEquals(0, $result, $output);
        $this->assertStringContainsString('The reports are not copied, as the target site has data already, which the copied reports would hide.', $output);
        $this->assertStringContainsString('Warning: The existing target site has 2 visits in the period of the visits to migrate', $output);
        // the ecommerce order of the source site is in the target site already
        $this->assertStringContainsString('Skipped 1 ecommerce orders the target site has already at', $output);
        $this->assertEquals(1, self::$fixture->targetDb->getDb()->fetchOne('SELECT COUNT(DISTINCT idvisit) FROM ' . $targetDb->prefixTable('log_conversion_item') . " WHERE idsite = ? AND idorder = 'TestingOrder'", [$idSite]));
        $this->assertStringContainsString('core:invalidate-report-data --sites=' . $idSite . ' --dates=2013-01-23,2013-01-23"', $output);
        $this->assertEquals(array_merge($counts, ['visits' => 4]), $this->getTargetSiteCounts($idSite));
    }

    public function test_runMigration_targetIdSite_failsBeforeWritingAnythingWhenNoColumnIsLeftForADimension()
    {
        $targetDb = self::$fixture->targetDb;
        $idSite = $this->createTargetSite('Site using all action dimensions', 'UTC');
        $columns = $targetDb->getDb()->fetchCol('SHOW COLUMNS FROM ' . $targetDb->prefixTable('log_link_visit_action') . " LIKE 'custom\_dimension\_%'");
        foreach ($columns as $i => $column) {
            $targetDb->insert('custom_dimensions', ['idcustomdimension' => $i + 1, 'idsite' => $idSite, 'name' => 'Other ' . $i, 'index' => (int) substr($column, strlen('custom_dimension_')),
                'scope' => 'action', 'active' => 1, 'extractions' => '[]', 'case_sensitive' => 1]);
        }
        $siteBefore = $targetDb->getDb()->fetchRow('SELECT * FROM ' . $targetDb->prefixTable('site') . ' WHERE idsite = ?', [$idSite]);

        // without transaction, nothing would be rolled back
        $result = $this->runCommand(['--target-idsite' => $idSite, '--disable-db-transactions' => true]);
        $this->assertEquals(1, $result);
        $this->assertStringContainsString(
            preg_replace('/\s+/', '', 'The existing target site uses all custom dimensions of scope action that the target Matomo has, so the custom dimension "MyName2" of the source site cannot be added. '
                . 'Please add a custom dimension of this scope on the target Matomo by executing there "./console customdimensions:add-custom-dimension --scope=action".'),
            preg_replace('/\s+/', '', $this->applicationTester->getDisplay())
        );
        $this->assertStringNotContainsString('Processing SiteMigration', $this->applicationTester->getDisplay());
        $this->assertEquals($siteBefore, $targetDb->getDb()->fetchRow('SELECT * FROM ' . $targetDb->prefixTable('site') . ' WHERE idsite = ?', [$idSite]));
        $this->assertEquals(0, $targetDb->getDb()->fetchOne('SELECT COUNT(*) FROM ' . $targetDb->prefixTable('site_url') . ' WHERE idsite = ?', [$idSite]));
    }

    public function test_runMigration_targetIdSite_doesNotCopyReportsOfAnotherTimezone()
    {
        $idSite = $this->createTargetSite('Berlin site', 'Europe/Berlin');

        $result = $this->runCommand(['--target-idsite' => $idSite]);
        $output = $this->applicationTester->getDisplay();
        $this->assertEquals(0, $result, $output);
        $this->assertStringContainsString('The reports are not copied, as the target site has the timezone Europe/Berlin, the source site UTC.', $output);
        $this->assertEquals(0, $this->getTargetSiteCounts($idSite)['archives']);
        $this->assertEquals(2, $this->getTargetSiteCounts($idSite)['visits']);
    }

    /** @dataProvider getOptionsWithoutValues */
    public function test_newOptionsRequireValues(string $option, ?string $value): void
    {
        $targetDb = self::$fixture->targetDb;
        $sitesBefore = $targetDb->fetchAll('SELECT * FROM ' . $targetDb->quoteTable('site') . ' ORDER BY idsite');
        $visitsBefore = $targetDb->fetchAll('SELECT * FROM ' . $targetDb->quoteTable('log_visit') . ' ORDER BY idvisit');

        $result = $this->runCommand([$option => $value, '--skip-logs' => true, '--skip-archives' => true]);

        $this->assertSame(1, $result, $this->applicationTester->getDisplay());
        $this->assertStringContainsString($option, $this->applicationTester->getDisplay());
        $this->assertStringNotContainsString('Processing SiteMigration', $this->applicationTester->getDisplay());
        $this->assertSame($sitesBefore, $targetDb->fetchAll('SELECT * FROM ' . $targetDb->quoteTable('site') . ' ORDER BY idsite'));
        $this->assertSame($visitsBefore, $targetDb->fetchAll('SELECT * FROM ' . $targetDb->quoteTable('log_visit') . ' ORDER BY idvisit'));
    }

    public static function getOptionsWithoutValues(): array
    {
        return [
            'bare target site' => ['--target-idsite', null],
            'empty target site' => ['--target-idsite', ''],
            'bare start date' => ['--start-date', null],
            'empty start date' => ['--start-date', ''],
            'bare end date' => ['--end-date', null],
            'empty end date' => ['--end-date', ''],
        ];
    }

    public function test_runMigration_targetIdSite_failsWhenTheSiteDoesNotExist()
    {
        $result = $this->runCommand(['--target-idsite' => 9999]);
        $this->assertEquals(1, $result);
        $this->assertStringContainsString('The site 9999 given in --target-idsite does not exist in the target Matomo.', $this->applicationTester->getDisplay());
    }

    public function test_runMigration_dateRange_onlyMigratesTheVisitsAndReportsOfTheRange()
    {
        $targetDb = self::$fixture->targetDb;
        $db = $targetDb->getDb();

        $result = $this->runCommand(['--start-date' => '2013-01-23', '--end-date' => '2013-01-23']);
        $output = $this->applicationTester->getDisplay();
        $this->assertEquals(0, $result, $output);
        $this->assertStringContainsString('Found 2 visits at', $output);
        $idSite = (int) $db->fetchOne('SELECT MAX(idsite) FROM ' . $targetDb->prefixTable('site'));
        $this->assertStringContainsString('core:invalidate-report-data --sites=' . $idSite . ' --dates=2013-01-23 --periods=week,month,year', $output);
        $this->assertEquals(2, $db->fetchOne('SELECT COUNT(*) FROM ' . $targetDb->prefixTable('log_visit') . ' WHERE idsite = ?', [$idSite]));
        // only the reports of the day, the reports of the week, month and year include days that are not migrated
        $periods = $db->fetchAll('SELECT DISTINCT period, date1, date2 FROM ' . $targetDb->prefixTable('archive_numeric_2013_01') . ' WHERE idsite = ?', [$idSite]);
        $this->assertEquals([['period' => 1, 'date1' => '2013-01-23', 'date2' => '2013-01-23']], $periods);

        $result = $this->runCommand(['--start-date' => '2013-01-24']);
        $output = $this->applicationTester->getDisplay();
        $this->assertEquals(0, $result, $output);
        $this->assertStringContainsString('Found 0 visits at', $output);
        $idSite = (int) $db->fetchOne('SELECT MAX(idsite) FROM ' . $targetDb->prefixTable('site'));
        $this->assertEquals(0, $db->fetchOne('SELECT COUNT(*) FROM ' . $targetDb->prefixTable('archive_numeric_2013_01') . ' WHERE idsite = ?', [$idSite]));
        // the configuration of the site is migrated as a whole
        $this->assertEquals(4, $db->fetchOne('SELECT COUNT(*) FROM ' . $targetDb->prefixTable('goal') . ' WHERE idsite = ?', [$idSite]));
    }

    /** @dataProvider getVisitsCrossingTheStartDate */
    public function test_runMigration_dateRange_rebuildsTheEarlierDayOfAVisit(string $timezone, string $firstAction, string $lastAction)
    {
        FakeAccess::clearAccess(true);
        $idSite = MigrationFixture::createWebsite($firstAction, 0, 'Midnight visit', false, 1, null, null, $timezone);
        $tracker = MigrationFixture::getTracker($idSite, $firstAction);
        $tracker->setUrl('http://example.com/before-midnight');
        MigrationFixture::checkResponse($tracker->doTrackPageView('Before midnight'));
        $tracker->setForceVisitDateTime($lastAction);
        $tracker->setUrl('http://example.com/after-midnight');
        MigrationFixture::checkResponse($tracker->doTrackPageView('After midnight'));

        // The tracker splits visits at midnight, but historical/imported rows can span it. Join the tracked
        // actions into one stored visit to exercise that source data without inventing the action records.
        $visits = Db::fetchAll('SELECT * FROM log_visit WHERE idsite = ? ORDER BY idvisit', [$idSite]);
        $this->assertCount(2, $visits);
        $firstIdVisit = $visits[0]['idvisit'];
        $lastIdVisit = $visits[1]['idvisit'];
        Db::query('UPDATE log_link_visit_action SET idvisit = ? WHERE idvisit = ?', [$firstIdVisit, $lastIdVisit]);
        if (version_compare(Version::VERSION, '6.0.0-b6', '>=')) {
            Db::query('UPDATE log_page_view_time SET idvisit = ? WHERE idvisit = ?', [$firstIdVisit, $lastIdVisit]);
        }
        Db::query('UPDATE log_visit SET visit_last_action_time = ?, visit_total_actions = 2 WHERE idvisit = ?', [$lastAction, $firstIdVisit]);
        Db::query('DELETE FROM log_visit WHERE idvisit = ?', [$lastIdVisit]);
        $this->assertEquals(1, Db::fetchOne('SELECT COUNT(*) FROM log_visit WHERE idsite = ?', [$idSite]));

        // Earlier report-comparison tests deliberately disable archiving. Prepare this case independently and
        // restore that setting, so running the whole class has the same behavior as selecting this test alone.
        $general = Config::getInstance()->General;
        Config::getInstance()->General = array_merge($general, [
            'browser_archiving_disabled_enforce' => 0,
            'enable_browser_archiving_triggering' => 1,
            'enable_general_settings_admin' => 0,
        ]);
        try {
            \Piwik\API\Request::processRequest('VisitsSummary.get', ['idSite' => $idSite, 'period' => 'day', 'date' => '2013-01-01']);
        } finally {
            Config::getInstance()->General = $general;
        }
        $sourceReports = Db::fetchAll('SELECT name, value FROM archive_numeric_2013_01 WHERE idsite = ? AND period = 1 AND date1 = ? ORDER BY name, value', [$idSite, '2013-01-01']);
        $this->assertNotEmpty($sourceReports);

        $options = ['--source-idsite' => $idSite, '--start-date' => '2013-01-01', '--end-date' => '2013-01-01'];
        $targetDb = self::$fixture->targetDb;
        $sitesBefore = $targetDb->fetchOne('SELECT COUNT(*) FROM ' . $targetDb->prefixTable('site'));
        $result = $this->runCommand(array_merge($options, ['--dry-run' => true]));
        $output = $this->applicationTester->getDisplay();
        $this->assertEquals(0, $result, $output);
        $this->assertStringContainsString('--dates=2012-12-31,2012-12-31 --periods=day', $output);
        $this->assertEquals($sitesBefore, $targetDb->fetchOne('SELECT COUNT(*) FROM ' . $targetDb->prefixTable('site')));

        $result = $this->runCommand($options);
        $output = $this->applicationTester->getDisplay();
        $this->assertEquals(0, $result, $output);
        $targetIdSite = (int) $targetDb->fetchOne('SELECT MAX(idsite) FROM ' . $targetDb->prefixTable('site'));
        $this->assertStringContainsString('core:invalidate-report-data --sites=' . $targetIdSite . ' --dates=2012-12-31,2012-12-31 --periods=day', $output);
        $this->assertStringContainsString('core:invalidate-report-data --sites=' . $targetIdSite . ' --dates=2012-12-31,2012-12-31 --periods=week,month,year', $output);
        $this->assertStringContainsString('core:invalidate-report-data --sites=' . $targetIdSite . ' --dates=2013-01-01 --periods=month,year', $output);
        $this->assertEquals(1, $targetDb->fetchOne('SELECT COUNT(*) FROM ' . $targetDb->prefixTable('log_visit') . ' WHERE idsite = ?', [$targetIdSite]));
        $this->assertEquals(2, $targetDb->fetchOne('SELECT COUNT(*) FROM ' . $targetDb->prefixTable('log_link_visit_action') . ' WHERE idsite = ?', [$targetIdSite]));
        $this->assertEquals($firstAction, $targetDb->fetchOne('SELECT MIN(server_time) FROM ' . $targetDb->prefixTable('log_link_visit_action') . ' WHERE idsite = ?', [$targetIdSite]));
        $this->assertEquals($sourceReports, $targetDb->fetchAll('SELECT name, value FROM ' . $targetDb->prefixTable('archive_numeric_2013_01') . ' WHERE idsite = ? AND period = 1 AND date1 = ? ORDER BY name, value', [$targetIdSite, '2013-01-01']));
    }

    public function getVisitsCrossingTheStartDate(): array
    {
        return [
            ['UTC', '2012-12-31 23:55:00', '2013-01-01 00:05:00'],
            ['America/New_York', '2013-01-01 04:55:00', '2013-01-01 05:05:00'],
        ];
    }

    public function test_runMigration_dateRange_withoutLogs_doesNotRecommendRebuildingReports()
    {
        $result = $this->runCommand(['--start-date' => '2013-01-23', '--end-date' => '2013-01-23', '--skip-logs' => true]);
        $output = $this->applicationTester->getDisplay();
        $this->assertEquals(0, $result, $output);
        $this->assertStringNotContainsString('core:invalidate-report-data', $output);
        $this->assertStringContainsString('Without the raw data, reports of periods only partly in the date range cannot be rebuilt on the target.', $output);
    }

    /** @dataProvider getInvalidDateRanges */
    public function test_runMigration_dateRange_failsWhenInvalid($options, $expectedError)
    {
        $result = $this->runCommand($options);
        $this->assertEquals(1, $result);
        $this->assertStringContainsString($expectedError, $this->applicationTester->getDisplay());
    }

    public function getInvalidDateRanges()
    {
        return [
            [['--start-date' => '23.01.2013'], 'The --start-date must be a date like 2024-01-31.'],
            [['--end-date' => '2013-13-45'], 'The --end-date must be a date like 2024-01-31.'],
            [['--end-date' => '2013-02-30'], 'The --end-date must be a date like 2024-01-31.'],
            [['--start-date' => '2013-02-01', '--end-date' => '2013-01-01'], 'The --start-date must not be after the --end-date.'],
        ];
    }

    public function test_runMigration_dateRange_usesTheTimezoneOfTheSite()
    {
        // the visits are tracked at 2013-01-23 01:29 UTC and later, which is 2013-01-22 in New York
        Db::query("UPDATE site SET timezone = 'America/New_York' WHERE idsite = 1");
        Site::clearCache();
        try {
            $resultDayBefore = $this->runCommand(['--start-date' => '2013-01-22', '--end-date' => '2013-01-22', '--skip-archives' => true]);
            $outputDayBefore = $this->applicationTester->getDisplay();

            $resultDay = $this->runCommand(['--start-date' => '2013-01-23', '--end-date' => '2013-01-23', '--skip-archives' => true]);
            $outputDay = $this->applicationTester->getDisplay();

            // the note to process the reports tells the days of the visits in the timezone of the site
            $idSite = $this->createTargetSite('New York site', 'America/New_York');
            self::$fixture->targetDb->insert('log_visit', array_merge(
                Db::fetchRow('SELECT * FROM log_visit WHERE idsite = 1 LIMIT 1'),
                ['idvisit' => null, 'idsite' => $idSite]
            ));
            $resultExisting = $this->runCommand(['--target-idsite' => $idSite]);
            $outputExisting = $this->applicationTester->getDisplay();
        } finally {
            Db::query("UPDATE site SET timezone = 'UTC' WHERE idsite = 1");
            Site::clearCache();
        }

        $this->assertEquals(0, $resultDayBefore, $outputDayBefore);
        $this->assertStringContainsString('Found 2 visits at', $outputDayBefore);
        $this->assertEquals(0, $resultDay, $outputDay);
        $this->assertStringContainsString('Found 0 visits at', $outputDay);
        $this->assertEquals(0, $resultExisting, $outputExisting);
        $this->assertStringContainsString('core:invalidate-report-data --sites=' . $idSite . ' --dates=2013-01-22,2013-01-22"', $outputExisting);
    }

    public function test_runMigration_failsWhenTargetIsTheSource()
    {
        $result = $this->runCommand(['--target-db-prefix' => '']);
        $this->assertEquals(1, $result);
        $this->assertStringContainsString('The target database and table prefix are the same as the source ones.', $this->applicationTester->getDisplay());
    }

    /** @dataProvider getInvalidTargetIds */
    public function test_invalidTargetIdDoesNotCreateASite(string $id): void
    {
        $targetDb = self::$fixture->targetDb;
        $before = $targetDb->fetchAll('SELECT * FROM ' . $targetDb->quoteTable('site'));
        $this->assertSame(1, $this->runCommand(['--target-idsite' => $id]));
        $this->assertStringContainsString('must be a positive integer', $this->applicationTester->getDisplay());
        $this->assertSame($before, $targetDb->fetchAll('SELECT * FROM ' . $targetDb->quoteTable('site')));
    }

    public function getInvalidTargetIds(): array
    {
        return [['0'], ['-1'], ['1.5'], ['1abc']];
    }

    public function test_populatedTargetKeepsItsSettingsAndReports(): void
    {
        $targetDb = self::$fixture->targetDb;
        $idSite = $this->createTargetSite('Live site', 'UTC');
        $visit = Db::fetchRow('SELECT * FROM log_visit WHERE idsite = 1 LIMIT 1');
        unset($visit['idvisit']);
        $visit['idsite'] = $idSite;
        $visit['visit_first_action_time'] = '2020-01-01 12:00:00';
        $visit['visit_last_action_time'] = '2020-01-01 12:01:00';
        $existingVisit = $targetDb->insert('log_visit', $visit);
        $targetDb->insert('site_setting', ['idsite' => $idSite, 'plugin_name' => 'Test', 'setting_name' => 'keep', 'setting_value' => 'destination']);
        $targetDb->createArchiveTableIfNeeded('archive_numeric_2013_01');
        $archive = Db::fetchRow('SELECT * FROM archive_numeric_2013_01 WHERE idsite = 1 LIMIT 1');
        $archive['idsite'] = $idSite;
        $archive['idarchive'] = 98765;
        $targetDb->insert('archive_numeric_2013_01', $archive);
        $reportsBefore = $targetDb->fetchAll('SELECT * FROM ' . $targetDb->quoteTable('archive_numeric_2013_01') . ' WHERE idsite = ?', [$idSite]);
        $sitesBefore = $targetDb->fetchOne('SELECT COUNT(*) FROM ' . $targetDb->quoteTable('site'));

        $this->assertSame(0, $this->runCommand(['--target-idsite' => $idSite, '--end-date' => '2013-01-23']), $this->applicationTester->getDisplay());
        $this->assertSame($sitesBefore, $targetDb->fetchOne('SELECT COUNT(*) FROM ' . $targetDb->quoteTable('site')));
        $this->assertEquals(3, $targetDb->fetchOne('SELECT COUNT(*) FROM ' . $targetDb->quoteTable('log_visit') . ' WHERE idsite = ?', [$idSite]));
        $this->assertEquals(array_merge($visit, ['idvisit' => $existingVisit]), $targetDb->fetchRow('SELECT * FROM ' . $targetDb->quoteTable('log_visit') . ' WHERE idvisit = ?', [$existingVisit]));
        $this->assertSame($reportsBefore, $targetDb->fetchAll('SELECT * FROM ' . $targetDb->quoteTable('archive_numeric_2013_01') . ' WHERE idsite = ?', [$idSite]));
        $this->assertSame('destination', $targetDb->fetchOne('SELECT setting_value FROM ' . $targetDb->quoteTable('site_setting') . ' WHERE idsite = ? AND setting_name = ?', [$idSite, 'keep']));
        $this->assertStringContainsString('core:invalidate-report-data --sites=' . $idSite, $this->applicationTester->getDisplay());
        $this->assertStringNotContainsString('Warning: The existing target site has', $this->applicationTester->getDisplay());
    }

    public function test_archivedOnlyHistoryBackdatesAnEmptyDestination(): void
    {
        FakeAccess::clearAccess(true);
        $sourceId = MigrationFixture::createWebsite('2010-01-01 00:00:00', 0, 'Archived history');
        $archive = Db::fetchRow("SELECT * FROM archive_numeric_2013_01 WHERE idsite = 1 AND name = 'nb_visits' AND period = 1 LIMIT 1");
        $this->assertNotEmpty($archive);
        $archive['idsite'] = $sourceId;
        $archive['idarchive'] = (int) Db::fetchOne('SELECT MAX(idarchive) FROM archive_numeric_2013_01') + 1;
        Db::get()->insert('archive_numeric_2013_01', $archive);
        $targetId = $this->createTargetSite('Empty destination', 'UTC');
        $targetDb = self::$fixture->targetDb;
        $this->assertSame(0, $this->runCommand([
            '--source-idsite' => $sourceId, '--target-idsite' => $targetId,
            '--target-site-is-offline' => true,
            '--start-date' => '2013-01-01', '--skip-logs' => true,
        ]), $this->applicationTester->getDisplay());
        $this->assertSame('2013-01-01 00:00:00', $targetDb->fetchOne('SELECT ts_created FROM ' . $targetDb->quoteTable('site') . ' WHERE idsite = ?', [$targetId]));
        $this->assertEquals($archive['value'], $targetDb->fetchOne('SELECT value FROM ' . $targetDb->quoteTable('archive_numeric_2013_01') . ' WHERE idsite = ? AND name = ?', [$targetId, 'nb_visits']));
        $this->assertSame(0, $this->getTargetSiteCounts($targetId)['visits']);
    }

    public function test_emptyExistingTargetDefaultsToRebuildingReports(): void
    {
        $idSite = $this->createTargetSite('Live empty site', 'UTC');
        $this->assertSame(0, $this->runCommand(['--target-idsite' => $idSite]), $this->applicationTester->getDisplay());
        $this->assertSame(2, $this->getTargetSiteCounts($idSite)['visits']);
        $this->assertSame(0, $this->getTargetSiteCounts($idSite)['archives']);
        $this->assertStringContainsString('--target-site-is-offline', $this->applicationTester->getDisplay());
        $this->assertStringContainsString('core:invalidate-report-data', $this->applicationTester->getDisplay());
    }

    public function test_archiveOnlyImportRequiresOfflineAssertionBeforeWriting(): void
    {
        $idSite = $this->createTargetSite('Live empty site', 'UTC');
        $targetDb = self::$fixture->targetDb;
        $before = $targetDb->fetchRow('SELECT * FROM ' . $targetDb->quoteTable('site') . ' WHERE idsite = ?', [$idSite]);
        $this->assertSame(1, $this->runCommand(['--target-idsite' => $idSite, '--skip-logs' => true]), $this->applicationTester->getDisplay());
        $this->assertStringContainsString('--target-site-is-offline', $this->applicationTester->getDisplay());
        $this->assertStringNotContainsString('Processing SiteMigration', $this->applicationTester->getDisplay());
        $this->assertSame($before, $targetDb->fetchRow('SELECT * FROM ' . $targetDb->quoteTable('site') . ' WHERE idsite = ?', [$idSite]));
        $this->assertSame(['visits' => 0, 'goals' => 0, 'dimensions' => 0, 'segments' => 0, 'annotations' => 0, 'archives' => 0], $this->getTargetSiteCounts($idSite));
    }

    public function test_offlineAssertionRequiresAnExistingTarget(): void
    {
        $this->assertSame(1, $this->runCommand(['--target-site-is-offline' => true]), $this->applicationTester->getDisplay());
        $this->assertStringContainsString('requires --target-idsite', $this->applicationTester->getDisplay());
        $this->assertStringNotContainsString('Processing SiteMigration', $this->applicationTester->getDisplay());
    }

    public function test_directArchiveMigrationAlsoRequiresOfflineAssertion(): void
    {
        $targetDb = self::$fixture->targetDb;
        $request = new Request();
        $request->sourceIdSite = 1;
        $request->targetIdSite = $this->createTargetSite('Live empty site', 'UTC');
        $request->isExistingTargetSite = true;
        $migration = new ArchiveMigration();
        $migration->prepare($request, $targetDb);
        $migration->migrate($request, $targetDb);
        $this->assertStringContainsString('--target-site-is-offline', $request->archivesNotCopiedReason);
        $this->assertSame(0, $this->getTargetSiteCounts($request->targetIdSite)['archives']);
    }

    /** @dataProvider getLateTargetData */
    public function test_archivePreparationRechecksAnExistingTarget(string $kind): void
    {
        $targetDb = self::$fixture->targetDb;
        $idSite = $this->createTargetSite('Offline assertion with stale state', 'UTC');
        $request = new Request();
        $request->sourceIdSite = 1;
        $request->targetIdSite = $idSite;
        $request->isExistingTargetSite = true;
        $request->targetSiteIsOffline = true;
        $this->assertSame(0, $this->getTargetSiteCounts($idSite)['visits']);
        $this->assertSame(0, $this->getTargetSiteCounts($idSite)['archives']);

        // Reproduce a cached empty-target decision becoming stale before archive preparation.
        if ($kind === 'visit') {
            $row = Db::fetchRow('SELECT * FROM log_visit WHERE idsite = 1 LIMIT 1');
            unset($row['idvisit']);
            $row['idsite'] = $idSite;
            $targetDb->insert('log_visit', $row);
        } else {
            $targetDb->createArchiveTableIfNeeded('archive_numeric_2013_01');
            $row = Db::fetchRow('SELECT * FROM archive_numeric_2013_01 WHERE idsite = 1 LIMIT 1');
            $row['idsite'] = $idSite;
            $row['idarchive'] = $targetDb->createArchiveId('archive_numeric_2013_01');
            $targetDb->insert('archive_numeric_2013_01', $row);
        }
        $counts = $this->getTargetSiteCounts($idSite);
        $migration = new ArchiveMigration();
        $migration->prepare($request, $targetDb);
        $this->assertStringContainsString('acquired data before copying', $request->archivesNotCopiedReason);
        $migration->migrate($request, $targetDb);
        $this->assertSame($counts, $this->getTargetSiteCounts($idSite));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Archived reports cannot be copied');
        (new ArchiveMigration(null, false))->prepare($request, $targetDb);
    }

    public static function getLateTargetData(): array
    {
        return [['visit'], ['archive']];
    }

    /** @dataProvider getRetentionRebuildCases */
    public function test_retentionGuidanceWorksWithTheActualCoreInvalidator(bool $copyCompleteDays): void
    {
        $targetDb = self::$fixture->targetDb;
        if ($copyCompleteDays) {
            $this->assertSame(0, $this->runCommand(['--start-date' => '2013-01-23', '--end-date' => '2013-01-23']), $this->applicationTester->getDisplay());
        } else {
            $idSite = $this->createTargetSite('Offline history', 'UTC');
            $this->assertSame(0, $this->runCommand(['--target-idsite' => $idSite, '--target-site-is-offline' => true]), $this->applicationTester->getDisplay());
            // A real multi-day range is essential: core converts an equal-date "range" into a Day and its
            // range-invalidation pass then bypasses retention for that day even without the override.
            $visit = Db::fetchRow('SELECT * FROM log_visit WHERE idsite = 1 LIMIT 1');
            unset($visit['idvisit']);
            $visit['visit_first_action_time'] = '2013-01-24 12:00:00';
            $visit['visit_last_action_time'] = '2013-01-24 12:01:00';
            Db::get()->insert('log_visit', $visit);
            $extraVisitId = Db::fetchOne('SELECT LAST_INSERT_ID()');
            try {
                $this->assertSame(0, $this->runCommand(['--target-idsite' => $idSite]), $this->applicationTester->getDisplay());
            } finally {
                Db::query('DELETE FROM log_visit WHERE idvisit = ?', [$extraVisitId]);
            }
        }
        $output = $this->applicationTester->getDisplay();
        $this->assertStringContainsString('with -v to see dates skipped', $output);
        $this->assertStringContainsString('Only then repeat the same invalidation commands with --ignore-log-deletion-limit', $output);
        $this->assertSame(1, preg_match('/core:invalidate-report-data --sites=(\d+) --dates=([\d,-]+)(?: --periods=([a-z,]+))?/', $output, $match));
        $options = ['command' => 'core:invalidate-report-data', '--sites' => $match[1], '--dates' => [$match[2]], '--no-interaction' => true];
        if (!empty($match[3])) {
            $options['--periods'] = $match[3];
        }
        if (!$copyCompleteDays) {
            $this->assertSame('2013-01-23,2013-01-24', $match[2]);
        }

        // Run the emitted command in the destination, including core's real retention filter.
        $config = Config::getInstance();
        $sourceDatabase = $config->database;
        $targetDatabase = $sourceDatabase;
        $targetDatabase['tables_prefix'] = MigrationFixture::TARGET_DB_PREFIX;
        $config->database = $targetDatabase;
        Option::clearCache();
        Site::clearCache();
        ArchiveTableCreator::clear();
        $purgeSettings = PrivacyManager::getPurgeDataSettings();
        try {
            PrivacyManager::savePurgeDataSettings(['delete_logs_enable' => 1, 'delete_logs_older_than' => 180]);
            $period = $copyCompleteDays ? 2 : 1;
            $queueSql = 'SELECT COUNT(*) FROM ' . $targetDb->quoteTable('archive_invalidations') . ' WHERE idsite = ? AND period = ?';
            $flagsSql = 'SELECT idarchive, value FROM ' . $targetDb->quoteTable('archive_numeric_2013_01')
                . " WHERE idsite = ? AND period = 1 AND date1 = '2013-01-23' AND name = 'done' ORDER BY idarchive";
            $flagsBefore = $targetDb->fetchAll($flagsSql, [$match[1]]);
            $this->assertNotEmpty($flagsBefore);
            $this->assertEquals(0, $targetDb->fetchOne($queueSql, [$match[1], $period]));
            $this->assertSame(0, $this->applicationTester->run($options), $this->applicationTester->getDisplay());
            $this->assertEquals(0, $targetDb->fetchOne($queueSql, [$match[1], $period]), 'Retention silently skips the historical period despite imported data.');
            $this->assertSame($flagsBefore, $targetDb->fetchAll($flagsSql, [$match[1]]), 'The pre-existing historical day archive remains untouched.');
            $this->assertSame(0, $this->applicationTester->run($options + ['--ignore-log-deletion-limit' => true]), $this->applicationTester->getDisplay());
            $this->assertGreaterThan(0, (int) $targetDb->fetchOne($queueSql, [$match[1], $period]), 'The documented conditional override must actually queue the historical period.');
            if ($copyCompleteDays) {
                $this->assertSame($flagsBefore, $targetDb->fetchAll($flagsSql, [$match[1]]), 'Rebuilding excluded aggregate periods must preserve copied daily archives.');
            } else {
                $this->assertNotEquals($flagsBefore, $targetDb->fetchAll($flagsSql, [$match[1]]), 'The override must invalidate the existing historical day archive, not only queue another period.');
            }
        } finally {
            PrivacyManager::savePurgeDataSettings($purgeSettings);
            $config->database = $sourceDatabase;
            Option::clearCache();
            Site::clearCache();
            ArchiveTableCreator::clear();
        }
    }

    public static function getRetentionRebuildCases(): array
    {
        return ['existing destination' => [false], 'excluded aggregate periods' => [true]];
    }

    /** @dataProvider getArchiveRebuildBoundaries */
    public function test_rebuildInstructionsPreserveCompleteCopiedPeriods(array $options, array $expectedPeriods): void
    {
        FakeAccess::clearAccess(true);
        $sourceId = MigrationFixture::createWebsite('2010-01-01 00:00:00', 0, 'Purged history');
        $offset = (int) Db::fetchOne('SELECT MAX(idarchive) FROM archive_numeric_2013_01') + 1;
        foreach (Db::fetchAll('SELECT * FROM archive_numeric_2013_01 WHERE idsite = 1') as $row) {
            $row['idsite'] = $sourceId;
            $row['idarchive'] += $offset;
            Db::get()->insert('archive_numeric_2013_01', $row);
        }
        $this->assertEquals(0, Db::fetchOne('SELECT COUNT(*) FROM log_visit WHERE idsite = ?', [$sourceId]));
        $this->assertSame(0, $this->runCommand(array_merge(['--source-idsite' => $sourceId], $options)), $this->applicationTester->getDisplay());
        $output = $this->applicationTester->getDisplay();
        $targetDb = self::$fixture->targetDb;
        $targetId = (int) $targetDb->fetchOne('SELECT MAX(idsite) FROM ' . $targetDb->quoteTable('site'));
        $copied = $targetDb->fetchAll('SELECT DISTINCT period, date1 FROM ' . $targetDb->quoteTable('archive_numeric_2013_01') . ' WHERE idsite = ?', [$targetId]);
        $this->assertNotEmpty($copied, 'Archived history must survive even without raw visits.');

        // Interpret the suggested commands with the same core period parser as core:invalidate-report-data.
        preg_match_all('/core:invalidate-report-data --sites=\d+ --dates=([\d,-]+) --periods=([a-z,]+)/', $output, $commands, PREG_SET_ORDER);
        $invalidated = [];
        foreach ($commands as $command) {
            foreach (explode(',', $command[2]) as $label) {
                $period = \Piwik\Period\Factory::build($label, $command[1]);
                $periods = $period instanceof \Piwik\Period\Range ? $period->getSubperiods() : [$period];
                foreach ($periods as $part) {
                    $date = $part->getDateStart()->toString();
                    $invalidated[$label . ':' . $date] = true;
                    foreach ($copied as $archive) {
                        $this->assertFalse((int) $archive['period'] === \Piwik\Piwik::$idPeriods[$label] && $archive['date1'] === $date, 'Suggested command invalidates a complete copied report: ' . $command[0]);
                    }
                }
            }
        }
        $actual = array_keys($invalidated);
        sort($actual);
        sort($expectedPeriods);
        $this->assertSame($expectedPeriods, $actual, $output);
    }

    public static function getArchiveRebuildBoundaries(): array
    {
        return [
            'complete year' => [['--start-date' => '2013-01-01', '--end-date' => '2013-12-31'], ['week:2012-12-31', 'week:2013-12-30']],
            'complete month' => [['--start-date' => '2013-01-01', '--end-date' => '2013-01-31'], ['week:2012-12-31', 'week:2013-01-28', 'year:2013-01-01']],
            'open end' => [['--start-date' => '2013-01-01'], ['week:2012-12-31']],
            'open start' => [['--end-date' => '2013-01-31'], ['week:2013-01-28', 'year:2013-01-01']],
            'two partial months' => [['--start-date' => '2013-01-15', '--end-date' => '2013-02-15'], ['week:2013-01-14', 'week:2013-02-11', 'month:2013-01-01', 'month:2013-02-01', 'year:2013-01-01']],
        ];
    }

    public function test_skippingArchivesStillExplainsHowToRebuildImportedVisits(): void
    {
        $idSite = $this->createTargetSite('Live destination', 'UTC');
        $this->assertSame(0, $this->runCommand(['--target-idsite' => $idSite]), $this->applicationTester->getDisplay());
        $this->assertSame(0, $this->runCommand(['--target-idsite' => $idSite, '--skip-archives' => true]), $this->applicationTester->getDisplay());
        $output = $this->applicationTester->getDisplay();
        $this->assertStringContainsString('core:invalidate-report-data --sites=' . $idSite . ' --dates=2013-01-23,2013-01-23', $output);
        $this->assertStringContainsString('raw data from both instances', $output);
        $this->assertSame(0, $this->runCommand(['--target-idsite' => $idSite, '--skip-archives' => true, '--skip-logs' => true]), $this->applicationTester->getDisplay());
        $this->assertStringNotContainsString('core:invalidate-report-data', $this->applicationTester->getDisplay());
    }

    /** @dataProvider getSameDatabaseConnections */
    public function test_rejectsTheSourceThroughAnEquivalentConnection(bool $dryRun): void
    {
        $config = Db::getDatabaseConfig();
        $before = Db::fetchAll('SELECT * FROM site ORDER BY idsite');
        // A leading zero is accepted by both DB adapters, but makes the connection strings different.
        // The target is still the exact same server, schema and tables. Do not run the raw-data loop here.
        $result = $this->runCommand([
            '--target-db-port' => '0' . ($config['port'] ?? 3306),
            '--target-db-prefix' => $config['tables_prefix'],
            '--target-idsite' => 1,
            '--dry-run' => $dryRun,
            '--skip-logs' => true,
            '--skip-archives' => true,
        ]);
        $this->assertSame(1, $result, $this->applicationTester->getDisplay());
        $this->assertStringContainsString('same as the source ones', $this->applicationTester->getDisplay());
        $this->assertStringNotContainsString('Processing SiteMigration', $this->applicationTester->getDisplay());
        $this->assertSame($before, Db::fetchAll('SELECT * FROM site ORDER BY idsite'));
    }

    public static function getSameDatabaseConnections(): array
    {
        return ['write' => [false], 'dry run' => [true]];
    }

    public function test_rejectsAnExistingDestinationOfAnotherMeasurableType(): void
    {
        $targetId = $this->createTargetSite('Roll-up destination', 'UTC');
        $targetDb = self::$fixture->targetDb;
        $targetDb->update('site', ['type' => 'rollup'], ['idsite' => $targetId]);
        $before = $this->getTargetSiteCounts($targetId);
        $this->assertSame(1, $this->runCommand(['--target-idsite' => $targetId]));
        $output = preg_replace('/\s+/', ' ', $this->applicationTester->getDisplay());
        $this->assertStringContainsString('Choose an existing destination of the same type', $output);
        $this->assertSame($before, $this->getTargetSiteCounts($targetId));
    }

    public function test_noSourceVisitsExplainsWhyReportsCannotBeRebuilt(): void
    {
        $idSite = $this->createTargetSite('Different timezone', 'Europe/Bucharest');
        $this->assertSame(0, $this->runCommand(['--target-idsite' => $idSite, '--end-date' => '2000-01-01']), $this->applicationTester->getDisplay());
        $this->assertStringContainsString('there are no source visits in the selected period to rebuild them', $this->applicationTester->getDisplay());
        $this->assertStringContainsString('Historical reports whose raw data was deleted cannot be merged', $this->applicationTester->getDisplay());
        $this->assertSame(0, $this->getTargetSiteCounts($idSite)['visits']);
    }

    public function test_archivesOnlyIntoPopulatedSiteFailsBeforeCopying(): void
    {
        $targetDb = self::$fixture->targetDb;
        $idSite = $this->createTargetSite('Live site', 'UTC');
        $visit = Db::fetchRow('SELECT * FROM log_visit WHERE idsite = 1 LIMIT 1');
        unset($visit['idvisit']);
        $visit['idsite'] = $idSite;
        $targetDb->insert('log_visit', $visit);
        $before = $this->getTargetSiteCounts($idSite);
        $this->assertSame(1, $this->runCommand(['--target-idsite' => $idSite, '--skip-logs' => true]));
        $output = preg_replace('/\s+/', ' ', $this->applicationTester->getDisplay());
        $this->assertStringContainsString('Archived reports cannot be copied', $output);
        $this->assertSame($before, $this->getTargetSiteCounts($idSite));
        $this->assertSame(0, $this->runCommand(['--target-idsite' => $idSite, '--skip-logs' => true, '--skip-archives' => true]));
        $this->assertSame(1, $this->getTargetSiteCounts($idSite)['visits']);
    }

    public function test_runMigration_targetIdSite_givesGoalsAndDimensionsTheIdsTheyHaveOnTheExistingSite()
    {
        $targetDb = self::$fixture->targetDb;
        $db = $targetDb->getDb();
        $idSite = $this->createTargetSite('Site with other goals and dimensions', 'UTC');
        $goalRow = function (int $idGoal, string $name, string $pattern) use ($idSite) {
            return ['idsite' => $idSite, 'idgoal' => $idGoal, 'name' => $name, 'description' => '', 'match_attribute' => 'url', 'pattern' => $pattern,
                'pattern_type' => 'contains', 'case_sensitive' => 0, 'allow_multiple' => 0, 'revenue' => 0, 'deleted' => 0];
        };
        // the goal 1 is another goal, the source goal 2 "Download Software2" is the target goal 3
        $targetDb->insert('goal', $goalRow(1, 'Other goal', 'other'));
        $targetDb->insert('goal', array_merge(Db::fetchRow('SELECT * FROM goal WHERE idsite = 1 AND idgoal = 2'), ['idsite' => $idSite, 'idgoal' => 3]));
        // the dimensions 1 and 2 and their columns are other dimensions, the source dimension 3 "MyName3" is the target dimension 7
        $dimensionRow = function (int $id, string $name, string $scope, int $index) use ($idSite) {
            return ['idcustomdimension' => $id, 'idsite' => $idSite, 'name' => $name, 'index' => $index, 'scope' => $scope, 'active' => 1, 'extractions' => '[]', 'case_sensitive' => 1];
        };
        $targetDb->insert('custom_dimensions', $dimensionRow(1, 'Author', 'visit', 1));
        $targetDb->insert('custom_dimensions', $dimensionRow(2, 'Other', 'action', 1));
        $targetDb->insert('custom_dimensions', $dimensionRow(7, 'MyName3', 'action', 4));

        // what references goals and dimensions by ID on the source
        Db::query("UPDATE log_visit SET custom_dimension_1 = 'visit value' WHERE idsite = 1");
        Db::query("UPDATE log_conversion SET custom_dimension_1 = 'visit value' WHERE idsite = 1");
        // the column 3 holds no dimension of the source site
        Db::query("UPDATE log_link_visit_action SET custom_dimension_1 = 'action value', custom_dimension_2 = 'inactive value', custom_dimension_3 = 'stale' WHERE idsite = 1");
        $segment = Db::fetchRow('SELECT * FROM segment WHERE enable_only_idsite = 1 LIMIT 1');
        unset($segment['idsegment']);
        foreach (['dimension1==visit%2520value;visitConvertedGoalId==1,dimension3==x', urlencode('visitConvertedGoalId==2')] as $i => $definition) {
            Db::get()->insert('segment', array_merge($segment, ['name' => 'segment ' . $i, 'definition' => $definition, 'hash' => md5(urldecode($definition))]));
        }
        $alert = [
            'name' => '', 'login' => 'alert_owner', 'period' => 'day',
            'report' => '', 'metric' => '', 'metric_condition' => 'less_than',
            'metric_matched' => 5, 'compared_to' => 7, 'email_me' => 0,
            'additional_emails' => '[]', 'phone_numbers' => '[]',
        ];
        $idAlert = (int) Db::fetchOne('SELECT MAX(idalert) FROM alert');
        foreach (['Goal alert' => ['Goals_get_idGoal--1', 'goal_1_nb_conversions'], 'Dimension alert' => ['CustomDimensions_getCustomDimension_idDimension--2', 'nb_hits']] as $name => [$report, $metric]) {
            $idAlert++;
            Db::get()->insert('alert', array_merge($alert, ['idalert' => $idAlert, 'name' => $name, 'report' => $report, 'metric' => $metric]));
            Db::get()->insert('alert_site', ['idalert' => $idAlert, 'idsite' => 1]);
            $snapshot = Db::fetchRow('SELECT * FROM alert WHERE idalert = ?', [$idAlert]);
            Db::get()->insert('alert_triggered', array_merge($snapshot, [
                'idsite' => 1, 'ts_triggered' => '2013-01-24 00:00:00', 'ts_last_sent' => '2013-01-24 01:00:00',
                'value_old' => '10', 'value_new' => '1', 'report' => 'Goals_get_idGoal--2', 'metric' => 'goal_2_nb_conversions',
            ]));
        }
        try {
            $result = $this->runCommand(['--target-idsite' => $idSite, '--target-site-is-offline' => true]);
            $output = $this->applicationTester->getDisplay();
            $resultAgain = $this->runCommand(['--target-idsite' => $idSite]);
            $outputAgain = $this->applicationTester->getDisplay();
        } finally {
            Db::query("DELETE FROM alert_triggered WHERE idalert IN (SELECT idalert FROM alert WHERE name IN ('Goal alert', 'Dimension alert'))");
            Db::query("DELETE FROM alert_site WHERE idalert IN (SELECT idalert FROM alert WHERE name IN ('Goal alert', 'Dimension alert'))");
            Db::query("DELETE FROM alert WHERE name IN ('Goal alert', 'Dimension alert')");
            Db::query("DELETE FROM segment WHERE name IN ('segment 0', 'segment 1')");
            Db::query('UPDATE log_visit SET custom_dimension_1 = NULL WHERE idsite = 1');
            Db::query('UPDATE log_conversion SET custom_dimension_1 = NULL WHERE idsite = 1');
            Db::query('UPDATE log_link_visit_action SET custom_dimension_1 = NULL, custom_dimension_2 = NULL, custom_dimension_3 = NULL WHERE idsite = 1');
        }
        $this->assertEquals(0, $result, $output);
        $output = preg_replace('/\s+/', ' ', $output); // long lines are wrapped to the width of the terminal

        // told before the migration is confirmed, as the tracking code of the website may need to change
        $this->assertStringContainsString('Warning: These goals get other IDs on the existing target site, as it has them with other IDs or uses their IDs for other goals: '
            . '"Download Software" (ID 1 => 5), "Download Software2" (ID 2 => 3), "Opens Contact Form" (ID 3 => 6).', $output);
        $this->assertStringContainsString('Warning: These custom dimensions get other IDs on the existing target site, as it has them with other IDs or uses their IDs for other dimensions: '
            . '"MyName1" (ID 1 => 8), "MyName2" (ID 2 => 9), "MyName3" (ID 3 => 7).', $output);
        $this->assertStringContainsString('Found 4 goals, added 3 goals the existing site did not have', $output);
        $this->assertStringContainsString('Found 3 custom dimensions, added 2 custom dimensions the existing site did not have', $output);
        $this->assertStringContainsString('The reports are not copied, as goals have other IDs on the target site, which the reports reference.', $output);
        $this->assertEquals(0, $this->getTargetSiteCounts($idSite)['archives']);

        $targetGoals = $db->fetchPairs('SELECT idgoal, name FROM ' . $targetDb->prefixTable('goal') . ' WHERE idsite = ? ORDER BY idgoal', [$idSite]);
        $this->assertEquals([1 => 'Other goal', 3 => 'Download Software2', 4 => 'Visit Docs', 5 => 'Download Software', 6 => 'Opens Contact Form'], $targetGoals);
        $targetDimensions = $db->fetchAll('SELECT idcustomdimension, name, scope, `index` FROM ' . $targetDb->prefixTable('custom_dimensions') . ' WHERE idsite = ? ORDER BY idcustomdimension', [$idSite]);
        $this->assertEquals([
            ['idcustomdimension' => 1, 'name' => 'Author', 'scope' => 'visit', 'index' => 1],
            ['idcustomdimension' => 2, 'name' => 'Other', 'scope' => 'action', 'index' => 1],
            ['idcustomdimension' => 7, 'name' => 'MyName3', 'scope' => 'action', 'index' => 4],
            ['idcustomdimension' => 8, 'name' => 'MyName1', 'scope' => 'visit', 'index' => 2],
            ['idcustomdimension' => 9, 'name' => 'MyName2', 'scope' => 'action', 'index' => 2],
        ], $targetDimensions);

        // the conversions of a goal and the values of a dimension are the ones of the same goal and dimension
        $this->assertEquals(
            Db::fetchAll('SELECT DISTINCT IF(idgoal = 1, 5, idgoal) AS target_idgoal, revenue FROM log_conversion WHERE idsite = 1 ORDER BY 1'),
            $db->fetchAll('SELECT DISTINCT idgoal AS target_idgoal, revenue FROM ' . $targetDb->prefixTable('log_conversion') . ' WHERE idsite = ? ORDER BY 1', [$idSite])
        );
        $dimensionValues = function (string $table) use ($db, $targetDb, $idSite) {
            return $db->fetchAll('SELECT DISTINCT custom_dimension_1, custom_dimension_2, custom_dimension_3, custom_dimension_4 FROM ' . $targetDb->prefixTable($table) . ' WHERE idsite = ?', [$idSite]);
        };
        $visitValues = [['custom_dimension_1' => null, 'custom_dimension_2' => 'visit value', 'custom_dimension_3' => null, 'custom_dimension_4' => null]];
        $this->assertEquals($visitValues, $dimensionValues('log_visit'));
        $this->assertEquals($visitValues, $dimensionValues('log_conversion'));
        $this->assertEquals([['custom_dimension_1' => null, 'custom_dimension_2' => 'action value', 'custom_dimension_3' => null, 'custom_dimension_4' => 'inactive value']], $dimensionValues('log_link_visit_action'));

        $segments = $db->fetchAll('SELECT definition, hash FROM ' . $targetDb->prefixTable('segment') . " WHERE enable_only_idsite = ? AND name LIKE 'segment %' ORDER BY name", [$idSite]);
        $this->assertEquals([
            ['definition' => 'dimension8==visit%2520value;visitConvertedGoalId==5,dimension7==x', 'hash' => md5('dimension8==visit%20value;visitConvertedGoalId==5,dimension7==x')],
            ['definition' => urlencode('visitConvertedGoalId==3'), 'hash' => md5('visitConvertedGoalId==3')],
        ], $segments);

        $alerts = $db->fetchAll('SELECT a.name, a.report, a.metric FROM ' . $targetDb->prefixTable('alert') . ' a JOIN ' . $targetDb->prefixTable('alert_site')
            . " s ON s.idalert = a.idalert WHERE s.idsite = ? AND a.name IN ('Goal alert', 'Dimension alert') ORDER BY a.name", [$idSite]);
        $this->assertEquals([
            ['name' => 'Dimension alert', 'report' => 'CustomDimensions_getCustomDimension_idDimension--9', 'metric' => 'nb_hits'],
            ['name' => 'Goal alert', 'report' => 'Goals_get_idGoal--5', 'metric' => 'goal_5_nb_conversions'],
        ], $alerts);

        $history = $db->fetchAll('SELECT report, metric FROM ' . $targetDb->quoteTable('alert_triggered') . ' WHERE idsite = ? ORDER BY idtriggered', [$idSite]);
        $this->assertEquals([
            ['report' => 'Goals_get_idGoal--3', 'metric' => 'goal_3_nb_conversions'],
            ['report' => 'Goals_get_idGoal--3', 'metric' => 'goal_3_nb_conversions'],
        ], $history, 'History has its own remapped report and metric and is not duplicated on repeat import.');

        // migrating again finds the goals, dimensions and plugin data under their target IDs
        $this->assertEquals(0, $resultAgain, $outputAgain);
        $outputAgain = preg_replace('/\s+/', ' ', $outputAgain);
        $this->assertStringContainsString('Found 4 goals, added 0 goals the existing site did not have', $outputAgain);
        $this->assertStringContainsString('Found 3 custom dimensions, added 0 custom dimensions the existing site did not have', $outputAgain);
        $this->assertStringContainsString('Found 4 segments, added 0 segments the existing site did not have', $outputAgain);
        $this->assertStringContainsString('Migrated 0 custom alerts', $outputAgain);
        $this->assertEquals($targetGoals, $db->fetchPairs('SELECT idgoal, name FROM ' . $targetDb->prefixTable('goal') . ' WHERE idsite = ? ORDER BY idgoal', [$idSite]));
        $this->assertEquals($visitValues, $dimensionValues('log_visit'));
    }
}

MigrateExistingSiteTest::$fixture = new MigrationFixture();
