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

    public function test_doesTableExist_keepsWildcardsAndSqlSyntaxLiteral(): void
    {
        $this->assertFalse($this->targetDb->doesTableExist("testprefix_site_url' OR 1=1 -- "));
        $this->assertFalse($this->targetDb->doesTableExist('testprefix_site_ur%'));
        $this->assertTrue($this->targetDb->doesTableExist('testprefix_site_url'));
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

    public function test_delete_keepsSqlSyntaxInColumnNamesAndValuesLiteral()
    {
        $table = $this->targetDb->prefixTable('sql_identifier_test');
        $column = 'key` OR 1=1 #comment';
        $quotedTable = $this->targetDb->getDb()->quoteIdentifier([$table]);
        $quotedColumn = $this->targetDb->getDb()->quoteIdentifier([$column]);
        $value = "value' OR 1=1 -- ";
        $this->targetDb->getDb()->query("CREATE TABLE $quotedTable ($quotedColumn VARCHAR(100))");
        try {
            $this->targetDb->getDb()->query("INSERT INTO $quotedTable ($quotedColumn) VALUES (?), (?)", [$value, 'keep']);
            $this->targetDb->delete('sql_identifier_test', [$column => $value]);
            $this->assertSame(['keep'], $this->targetDb->fetchCol("SELECT $quotedColumn FROM $quotedTable"));
        } finally {
            $this->targetDb->getDb()->query("DROP TABLE $quotedTable");
        }
    }

    public function test_delete_doesNotExecuteAConditionInjectedThroughAColumnName(): void
    {
        $this->test_insert();
        $rejected = false;
        try {
            $this->targetDb->delete('site_url', ['idsite` = 3 OR `idsite' => 999]);
        } catch (\Zend_Db_Statement_Exception $e) {
            $rejected = true;
        }
        $table = $this->targetDb->prefixTable('site_url');
        $this->assertSame(3, (int) $this->targetDb->fetchOne("SELECT COUNT(*) FROM `$table`"));
        $this->assertTrue($rejected, 'The injected expression must be treated as a nonexistent column name.');
    }

    public function test_delete_quotesTheWholePrefixedTableName()
    {
        $targetDb = new TargetDb(['tables_prefix' => 'testprefix_`']);
        $table = $targetDb->getDb()->quoteIdentifier([$targetDb->prefixTable('sql_identifier_test')]);
        $targetDb->getDb()->query("CREATE TABLE $table (`id` INT)");
        try {
            $targetDb->getDb()->query("INSERT INTO $table (`id`) VALUES (1), (2)");
            $targetDb->delete('sql_identifier_test', ['id' => 1]);
            $this->assertEquals([2], $targetDb->fetchCol("SELECT id FROM $table"));
        } finally {
            $targetDb->getDb()->query("DROP TABLE $table");
        }
    }

    public function test_delete_doesNotWriteInDryRunOrWithoutConditions()
    {
        $this->test_insert();
        $table = $this->targetDb->prefixTable('site_url');
        $this->targetDb->delete('site_url', []);
        $this->assertSame(3, (int) $this->targetDb->fetchOne("SELECT COUNT(*) FROM `$table`"));
        $this->targetDb->enableDryRun();
        $this->targetDb->delete('site_url', ['idsite' => 3]);
        $this->assertSame(3, (int) $this->targetDb->fetchOne("SELECT COUNT(*) FROM `$table`"));
    }
}
