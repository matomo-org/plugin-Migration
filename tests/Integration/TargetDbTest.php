<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\tests\Integration;

use Piwik\Plugins\Migration\TargetDb;
use Piwik\Plugins\Migration\tests\Fixtures\MigrationFixture;
use Piwik\Sequence;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group Migration
 * @group TargetDbTest
 * @group Plugins
 */
class TargetDbTest extends IntegrationTestCase
{
    /**
     * @var TargetDb
     */
    private $targetDb;

    public function setUp(): void
    {
        parent::setUp();

        $this->targetDb = new TargetDb(array(
            'tables_prefix' => 'testprefix_'
        ));
        MigrationFixture::createTargetDbTableStructure($this->targetDb);
    }

    public function tearDown(): void
    {
        MigrationFixture::tearDownTargetDbTableStructure($this->targetDb);
        parent::tearDown();
    }

    public function test_prefixTable()
    {
        $this->assertSame('testprefix_log_visit', $this->targetDb->prefixTable('log_visit'));
    }

    public function test_doesTableExist()
    {
        $this->assertTrue($this->targetDb->doesTableExist($this->targetDb->prefixTable('log_visit')));
        $this->assertFalse($this->targetDb->doesTableExist($this->targetDb->prefixTable('log_vvvvisit')));
    }

    public function test_getTableColumns()
    {
        $columns = $this->targetDb->getTableColumns($this->targetDb->prefixTable('site_url'));
        $columns = array_keys($columns);
        $this->assertEquals(array('idsite', 'url'), $columns);
    }

    public function test_getTableColumns_notExists()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('foobar\' doesn\'t exist');
        $this->targetDb->getTableColumns('foobar');
    }

    public function test_createArchiveTableIfNeeded_createsTableIfNotExistsYet()
    {
        $this->targetDb->createArchiveTableIfNeeded('archive_blob_2006_01');
        $columns = $this->targetDb->getTableColumns($this->targetDb->prefixTable('archive_blob_2006_01'));
        $this->assertNotEmpty($columns);
    }

    public function test_createArchiveTableIfNeeded_doesNotFailWhenAlreadyExists()
    {
        $tableName = 'archive_numeric_2006_01';
        $tableNamePrefixed = $this->targetDb->prefixTable($tableName);
        $this->assertNull($this->targetDb->createArchiveTableIfNeeded($tableName));
        $this->assertNull($this->targetDb->createArchiveTableIfNeeded($tableName));

        $this->assertEquals(0, $this->targetDb->getMaxArchiveId($tableNamePrefixed));

        $this->targetDb->getDb()->query("INSERT INTO $tableNamePrefixed (idarchive, name,idsite, date1, date2, period, value) values (1, 'b', 1, '2020-01-02', '2020-01-02', '1', 'foo')");
        $this->targetDb->getDb()->query("INSERT INTO $tableNamePrefixed (idarchive, name,idsite, date1, date2, period, value) values (2, 'b', 1, '2020-01-02', '2020-01-02', '1', 'foo')");

        $this->assertEquals(2, $this->targetDb->getMaxArchiveId($tableNamePrefixed));

        $sequenceTable = $this->targetDb->prefixTable(Sequence::TABLE_NAME);
        $this->targetDb->getDb()->query('UPDATE ' . $sequenceTable . ' SET `value` = 0');

        // calling create table again should fix the sequence value!
        $this->assertNull($this->targetDb->createArchiveTableIfNeeded($tableName));

        $val = $this->targetDb->getDb()->fetchOne('SELECT `value` from ' . $sequenceTable . ' where `name` like "%archive_numeric_2006_01%"');
        // our script increases the value by 20
        $this->assertEquals(2 + 20, $val);
    }

    public function test_createArchiveId()
    {
        $id = $this->targetDb->createArchiveId('archive_numeric_2006_01');
        $this->assertEquals(1, $id);

        $id = $this->targetDb->createArchiveId('archive_numeric_2006_01');
        $this->assertEquals(2, $id);

        $id = $this->targetDb->createArchiveId('archive_numeric_2006_02');
        $this->assertEquals(1, $id);
    }

    public function test_insert()
    {
        $this->targetDb->insert('site_url', array('idsite' => 3, 'url' => 'https://www.foobar.com'));
        $this->targetDb->insert('site_url', array('idsite' => 3, 'url' => 'https://www.barbaz.com'));
        $this->targetDb->insert('site_url', array('idsite' => 4, 'url' => 'https://www.foobaz.com'));

        $urls = $this->targetDb->getDb()->fetchAll('SELECT * FROM ' . $this->targetDb->prefixTable('site_url'));
        $this->assertEquals(array(
            array ('idsite' => '3', 'url' => 'https://www.barbaz.com'),
            array ('idsite' => '3', 'url' => 'https://www.foobar.com'),
            array ('idsite' => '4', 'url' => 'https://www.foobaz.com'),
        ), $urls);
    }

    public function test_update()
    {
        $this->test_insert();
        $this->targetDb->update('site_url', array('url' => 'https://www.updated.com'), array('idsite' => 3, 'url' => 'https://www.foobar.com'));

        $urls = $this->targetDb->getDb()->fetchAll('SELECT * FROM ' . $this->targetDb->prefixTable('site_url'));
        $this->assertEquals(array(
            array ('idsite' => '3', 'url' => 'https://www.barbaz.com'),
            array ('idsite' => '3', 'url' => 'https://www.updated.com'),
            array ('idsite' => '4', 'url' => 'https://www.foobaz.com'),
        ), $urls);
    }
}
