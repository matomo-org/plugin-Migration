<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\tests\System\Commands;

use Piwik\Access;
use Piwik\Db;
use Piwik\Piwik;
use Piwik\Plugins\Migration\tests\Fixtures\MigrationFixture;
use Piwik\Config;
use Piwik\Tests\Framework\Mock\FakeAccess;
use Piwik\Tests\Framework\TestCase\ConsoleCommandTestCase;

/**
 * @group Migration
 * @group MigrationsTest
 * @group Plugins
 */
class MigrateTest extends ConsoleCommandTestCase
{
    /**
     * @var MigrationFixture
     */
    public static $fixture = null; // initialized below class definition

    public function tearDown(): void
    {
        parent::tearDown();

        // otherwise won't be reset if test fails
        $this->setTargetDbPrefix('');
    }

    public function test_runMigration()
    {
        $result = $this->runCommand();
        $this->assertEquals('Processing SiteMigration at 2019-01-10 02:48:01
Target site is 1 at 2019-01-10 02:48:01
Processed SiteMigration at 2019-01-10 02:48:01
Processing SiteUrlMigration at 2019-01-10 02:48:01
Found 2 site urls at 2019-01-10 02:48:01
Processed SiteUrlMigration at 2019-01-10 02:48:01
Processing SiteSettingMigration at 2019-01-10 02:48:01
Found 0 site settings at 2019-01-10 02:48:01
Processed SiteSettingMigration at 2019-01-10 02:48:01
Processing GoalsMigration at 2019-01-10 02:48:01
Found 4 goals at 2019-01-10 02:48:01
Processed GoalsMigration at 2019-01-10 02:48:01
Processing SegmentsMigration at 2019-01-10 02:48:01
Found 2 segments at 2019-01-10 02:48:01
Processed SegmentsMigration at 2019-01-10 02:48:01
Processing AnnotationsMigration at 2019-01-10 02:48:01
Found annotations at 2019-01-10 02:48:01
Processed AnnotationsMigration at 2019-01-10 02:48:01
Processing CustomDimensionMigration at 2019-01-10 02:48:01
Found 3 custom dimensions at 2019-01-10 02:48:01
Processed CustomDimensionMigration at 2019-01-10 02:48:01
Processing LogMigration at 2019-01-10 02:48:01
Found 2 visits at 2019-01-10 02:48:01
Migrated 5% of visits at 2019-01-10 02:48:01
Migrated 10% of visits at 2019-01-10 02:48:01
Migrated 20% of visits at 2019-01-10 02:48:01
Migrated 40% of visits at 2019-01-10 02:48:01
Migrated 60% of visits at 2019-01-10 02:48:01
Migrated 80% of visits at 2019-01-10 02:48:01
Migrated 90% of visits at 2019-01-10 02:48:01
Migrated 2 visits. The number of migrated visits may be higher if data is still tracked into the source Matomo while migrating the data at 2019-01-10 02:48:01
Processed LogMigration at 2019-01-10 02:48:01
Processing ArchiveMigration at 2019-01-10 02:48:01
Found 15 archive tables at 2019-01-10 02:48:01
Starting to migrate archive table archive_numeric_2013_01 at 2019-01-10 02:48:01
Migrated archive table archive_numeric_2013_01 at 2019-01-10 02:48:01
Starting to migrate archive table archive_numeric_2013_02 at 2019-01-10 02:48:01
Migrated archive table archive_numeric_2013_02 at 2019-01-10 02:48:01
Starting to migrate archive table archive_numeric_2013_03 at 2019-01-10 02:48:01
Migrated archive table archive_numeric_2013_03 at 2019-01-10 02:48:01
Starting to migrate archive table archive_numeric_2013_04 at 2019-01-10 02:48:01
Migrated archive table archive_numeric_2013_04 at 2019-01-10 02:48:01
Starting to migrate archive table archive_numeric_2013_05 at 2019-01-10 02:48:01
Migrated archive table archive_numeric_2013_05 at 2019-01-10 02:48:01
Starting to migrate archive table archive_numeric_2013_06 at 2019-01-10 02:48:01
Migrated archive table archive_numeric_2013_06 at 2019-01-10 02:48:01
Starting to migrate archive table archive_numeric_2013_07 at 2019-01-10 02:48:01
Migrated archive table archive_numeric_2013_07 at 2019-01-10 02:48:01
Starting to migrate archive table archive_numeric_2013_08 at 2019-01-10 02:48:01
Migrated archive table archive_numeric_2013_08 at 2019-01-10 02:48:01
Starting to migrate archive table archive_numeric_2013_09 at 2019-01-10 02:48:01
Migrated archive table archive_numeric_2013_09 at 2019-01-10 02:48:01
Starting to migrate archive table archive_numeric_2013_10 at 2019-01-10 02:48:01
Migrated archive table archive_numeric_2013_10 at 2019-01-10 02:48:01
Starting to migrate archive table archive_numeric_2013_11 at 2019-01-10 02:48:01
Migrated archive table archive_numeric_2013_11 at 2019-01-10 02:48:01
Starting to migrate archive table archive_numeric_2013_12 at 2019-01-10 02:48:01
Migrated archive table archive_numeric_2013_12 at 2019-01-10 02:48:01
Starting to migrate archive table archive_numeric_2014_01 at 2019-01-10 02:48:01
Migrated archive table archive_numeric_2014_01 at 2019-01-10 02:48:01
Starting to migrate archive table archive_blob_2013_01 at 2019-01-10 02:48:01
Migrated archive table archive_blob_2013_01 at 2019-01-10 02:48:01
Starting to migrate archive table archive_blob_2013_12 at 2019-01-10 02:48:01
Migrated archive table archive_blob_2013_12 at 2019-01-10 02:48:01
Processed ArchiveMigration at 2019-01-10 02:48:01
', $this->applicationTester->getDisplay());
        $this->assertEquals('0', $result);
    }

    public function test_runMigration_actuallyCopiesValues()
    {
        $targetDb = self::$fixture->targetDb->getDb();

        $site = $targetDb->fetchAll('SELECT * FROM ' . self::$fixture->targetDb->prefixTable('site'));
        $this->assertCount(1, $site);
        $this->assertSame('1', $site[0]['idsite']);
        $this->assertSame('MigrationSite', $site[0]['name']);

        $siteUrls = $targetDb->fetchAll('SELECT * FROM ' . self::$fixture->targetDb->prefixTable('site_url'));
        $this->assertCount(2, $siteUrls);

        $visits = $targetDb->fetchAll('SELECT * FROM ' . self::$fixture->targetDb->prefixTable('log_visit'));
        $this->assertCount(2, $visits);

        $visitActions = $targetDb->fetchAll('SELECT * FROM ' . self::$fixture->targetDb->prefixTable('log_link_visit_action'));
        $this->assertCount(4, $visitActions);

        $conversions = $targetDb->fetchAll('SELECT * FROM ' . self::$fixture->targetDb->prefixTable('log_conversion'));
        $this->assertCount(3, $conversions);

        $conversionItems = $targetDb->fetchAll('SELECT * FROM ' . self::$fixture->targetDb->prefixTable('log_conversion_item'));
        $this->assertCount(2, $conversionItems);

        $archives = $targetDb->fetchAll('SELECT * FROM ' . self::$fixture->targetDb->prefixTable('archive_numeric_2013_01'));
        $this->assertGreaterThanOrEqual(195, count($archives));
        $this->assertLessThanOrEqual(600, count($archives));

        $archives = $targetDb->fetchAll('SELECT * FROM ' . self::$fixture->targetDb->prefixTable('archive_blob_2013_01'));
        $this->assertGreaterThanOrEqual(195, count($archives));
        $this->assertLessThanOrEqual(600, count($archives));
    }

    /**
     * @dataProvider getApiForTesting
     */
    public function testApi($api, $params)
    {
        FakeAccess::clearAccess(true);
        $this->disableArchiving();
        $this->runApiTests($api, $params);
    }

    /**
     * We make sure the copied instance returns same live and reporting data
     *
     * @dataProvider getApiForTesting
     */
    public function testApi_migrated($api, $params)
    {
        $this->setTargetDbPrefix(MigrationFixture::TARGET_DB_PREFIX);
        $this->disableArchiving();

        FakeAccess::clearAccess($superUser = true);
        try {
            $this->runApiTests($api, $params);
        } catch (\Exception $e) {
            $this->setTargetDbPrefix('');
            throw $e;
        }

        $this->setTargetDbPrefix('');
    }

    private function setTargetDbPrefix($prefix)
    {
        $testEnv = self::$fixture->getTestEnvironment();
        $config = Config::getInstance();
        foreach (array('database', 'database_tests') as $category) {
            $testEnv->overrideConfig($category, 'tables_prefix', $prefix);
            $cat = $config->$category;
            $cat['tables_prefix'] = $prefix;
            $config->$category = $cat;
        }
        $testEnv->save();
        Db::destroyDatabaseObject();
    }

    private function disableArchiving()
    {
        // by disabling archiving we make sure the archives were copied and the reports aren't archived on demand!
        $testEnv = self::$fixture->getTestEnvironment();
        $testEnv->overrideConfig('General', 'browser_archiving_disabled_enforce', '1');
        $testEnv->overrideConfig('General', 'archiving_range_force_on_browser_request', '0');
        $testEnv->overrideConfig('General', 'enable_browser_archiving_triggering', '0');
        $testEnv->overrideConfig('General', 'enable_general_settings_admin', '0');
        $testEnv->save();
    }

    public function test_runMigration_CanBeExecutedMultipleTimesWithoutAnyIdProblems()
    {
        $result = $this->runCommand();
        $this->assertStringContainsString('Starting to migrate archive table archive_numeric_2013_01 at 2019-01-10 02:48:01
Migrated archive table archive_numeric_2013_01 at 2019-01-10 02:48:01
Starting to migrate archive table archive_numeric_2013_02 at 2019-01-10 02:48:01
Migrated archive table archive_numeric_2013_02 at 2019-01-10 02:48:01', $this->applicationTester->getDisplay());
        $this->assertEquals('0', $result);
        $result = $this->runCommand();
        $this->assertEquals('0', $result);
    }

    public function test_runMigration_failsWhenNotSameStructure()
    {
        $logVisitTable = self::$fixture->targetDb->prefixTable('log_visit');
        $logActionTable = self::$fixture->targetDb->prefixTable('log_action');
        self::$fixture->targetDb->getDb()->query("ALTER TABLE $logVisitTable DROP COLUMN idsite");
        self::$fixture->targetDb->getDb()->query("ALTER TABLE $logActionTable DROP COLUMN idaction");

        $result = $this->runCommand();
        $this->assertStringContainsString('The following columns are missing in the target DB table "targetdb_log_visit": idsite
The following columns are missing in the target DB table "targetdb_log_action": idaction', $this->applicationTester->getDisplay());
        $this->assertEquals('1', $result);
    }

    public function getApiForTesting()
    {
        $api = array(
            'API.get',
            'Live.getLastVisitsDetails',
            'Actions.getPageUrls',
            'SitesManager.getSiteFromId',
            'Goals.getGoals',
            'CustomDimensions.getConfiguredCustomDimensions',
        );

        $apiToTest   = array();
        $apiToTest[] = array($api,
            array(
                'idSite'     => 1,
                'date'       => self::$fixture->dateTime,
                'periods'    => array('day', 'year'),
                'testSuffix' => '',
                'xmlFieldsToRemove' => array('pageIdAction')
            )
        );

        return $apiToTest;
    }

    private function runCommand()
    {
        $config = Db::getDatabaseConfig();
        $result = $this->applicationTester->run(array(
            'command' => 'migration:measurable',
            '--source-idsite' => 1,
            '--target-db-host' => $config['host'],
            '--target-db-username' => $config['username'],
            '--target-db-password' => $config['password'],
            '--target-db-name' => $config['dbname'],
            '--target-db-prefix' => MigrationFixture::TARGET_DB_PREFIX,
            '--no-interaction' => true,
        ));
        return $result;
    }

    public static function provideContainerConfigBeforeClass()
    {
        return [
            Access::class => new FakeAccess()
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

MigrateTest::$fixture = new MigrationFixture();
