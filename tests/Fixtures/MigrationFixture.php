<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\tests\Fixtures;

use Piwik\API\Request;
use Piwik\Date;
use Piwik\Db;
use Piwik\DbHelper;
use Piwik\Plugins\Migration\TargetDb;
use Piwik\Plugins\Goals;
use Piwik\Tests\Framework\Fixture;
use Piwik\Plugins\SitesManager\API as APISitesManager;

/**
 * Generates tracker testing data for our MigrationsTest
 *
 * This Simple fixture adds one website and tracks one visit with couple pageviews and an ecommerce conversion
 */
class MigrationFixture extends Fixture
{
    public $dateTime = '2013-01-23 01:23:45';
    public $idSite = 1;

    const TARGET_DB_PREFIX = 'targetdb_';

    private $goals = array(
        array('name' => 'Download Software',  'match' => 'url', 'pattern' => 'download',   'patternType' => 'contains', 'revenue' => 0.10),
        array('name' => 'Download Software2', 'match' => 'url', 'pattern' => 'latest.zip', 'patternType' => 'contains', 'revenue' => 0.05),
        array('name' => 'Opens Contact Form', 'match' => 'url', 'pattern' => 'contact',    'patternType' => 'contains', 'revenue' => false),
        array('name' => 'Visit Docs',         'match' => 'url', 'pattern' => 'docs',       'patternType' => 'contains', 'revenue' => false),
    );
    /**
     * @var TargetDb
     */
    public $targetDb;

    public function setUp()
    {
        parent::setUp();

        $this->targetDb = new TargetDb(array(
            'tables_prefix' => self::TARGET_DB_PREFIX
        ));
        self::copyTableStructure($this->targetDb);

        Fixture::createSuperUser();

        $this->setUpWebsite();
        $this->setUpGoals();
        $this->trackFirstVisit();
        $this->trackSecondVisit();

        // make sure to archive data
        Request::processRequest('API.get',array(
            'period' => 'year',
            'date' => '2013-01-23',
            'idSite' => $this->idSite
        ));
    }

    public static function copyTableStructure(TargetDb $targetDb)
    {
        $installed = Db::get()->fetchCol("SHOW TABLES");
        foreach ($installed as $table) {
            if (strpos($table, self::TARGET_DB_PREFIX) === 0) {
                continue; // do not copy target table again
            }

            $tablePrefixed = $targetDb->prefixTable($table);
            if (in_array($tablePrefixed, $installed)) {
                continue; // already installed
            }

            if ($targetDb->doesTableExist($tablePrefixed)) {
                continue;
            }

            $row = Db::fetchRow('SHOW CREATE TABLE `' . $table . '`');
            $sql = $row['Create Table'];
            $sql = str_replace('`'.$table.'`', '`'.$tablePrefixed.'`', $sql);
            $targetDb->getDb()->query($sql);
        }
    }

    public static function createTargetDbTableStructure(TargetDb $targetDb)
    {
        foreach (DbHelper::getTablesCreateSql() as $table => $sql) {
            $sql = str_replace($table, $targetDb->prefixTable($table), $sql);
            $targetDb->getDb()->query($sql);
        }
    }

    public static function tearDownTargetDbTableStructure(TargetDb $targetDb)
    {
        $prefix = $targetDb->prefixTable('');
        foreach ($targetDb->getDb()->fetchCol("SHOW TABLES LIKE '" . $prefix . "%'") as $table) {
            $targetDb->getDb()->query('drop table ' . $table);
        }
    }

    public function tearDown()
    {
        self::tearDownTargetDbTableStructure($this->targetDb);
        parent::tearDown();
    }

    private function setUpWebsite()
    {
        if (!self::siteCreated($this->idSite)) {
            $idSite = self::createWebsite($this->dateTime, $ecommerce = 1, $siteName = 'MigrationSite');
            $this->assertSame($this->idSite, $idSite);

            APISitesManager::getInstance()->addSiteAliasUrls($this->idSite, array('https://www.example1.com', 'https://www.example2.net'));
        }
    }

    private function setUpGoals()
    {
        $api = Goals\API::getInstance();
        foreach ($this->goals as $goal) {
            $api->addGoal($this->idSite, $goal['name'], $goal['match'], $goal['pattern'], $goal['patternType'], $caseSensitive = false, $goal['revenue'], $allowMultipleConversionsPerVisit = false);
        }
    }

    protected function trackFirstVisit()
    {
        $t = self::getTracker($this->idSite, $this->dateTime, $defaultInit = true);

        $t->setForceVisitDateTime(Date::factory($this->dateTime)->addHour(0.1)->getDatetime());
        $t->setUrl('http://example.com/');
        self::checkResponse($t->doTrackPageView('Viewing homepage'));

        $t->setForceVisitDateTime(Date::factory($this->dateTime)->addHour(0.2)->getDatetime());
        $t->setUrl('http://example.com/sub/page');
        self::checkResponse($t->doTrackPageView('Second page view'));

        $t->setForceVisitDateTime(Date::factory($this->dateTime)->addHour(0.25)->getDatetime());
        $t->addEcommerceItem($sku = 'SKU_ID', $name = 'Test item!', $category = 'Test & Category', $price = 777, $quantity = 33);
        self::checkResponse($t->doTrackEcommerceOrder('TestingOrder', $grandTotal = 33 * 77));

        self::checkResponse($t->doTrackGoal(1, 5));
    }

    protected function trackSecondVisit()
    {
        $t = self::getTracker($this->idSite, $this->dateTime, $defaultInit = true);
        $t->setIp('56.11.55.73');

        $t->setForceVisitDateTime(Date::factory($this->dateTime)->addHour(0.1)->getDatetime());
        $t->setUrl('http://example.com/sub/page');
        self::checkResponse($t->doTrackPageView('Viewing homepage'));

        $t->setForceVisitDateTime(Date::factory($this->dateTime)->addHour(0.2)->getDatetime());
        $t->setUrl('http://example.com/?search=this is a site search query');
        self::checkResponse($t->doTrackPageView('Site search query'));

        $t->setForceVisitDateTime(Date::factory($this->dateTime)->addHour(0.3)->getDatetime());
        $t->addEcommerceItem($sku = 'SKU_ID2', $name = 'A durable item', $category = 'Best seller', $price = 321);
        self::checkResponse($t->doTrackEcommerceCartUpdate($grandTotal = 33 * 77));
    }
}