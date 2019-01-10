<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\tests\Integration\Migrations;

use Piwik\Plugins\Migration\Migrations\LogActionMigration;
use Piwik\Plugins\Migration\TargetDb;
use Piwik\Plugins\Migration\tests\Fixtures\MigrationFixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;
use Piwik\Tracker\Model;

/**
 * @group Migration
 * @group LogActionMigrationTest
 * @group Plugins
 */
class LogActionMigrationTest extends IntegrationTestCase
{
    /**
     * @var TargetDb
     */
    private $targetDb;

    /**
     * @var LogActionMigration
     */
    private $migration;

    public function setUp()
    {
        parent::setUp();

        $this->targetDb = new TargetDb(array(
            'tables_prefix' => 'testprefix_'
        ));
        MigrationFixture::createTargetDbTableStructure($this->targetDb);
        $this->migration = new LogActionMigration();
    }

    public function tearDown()
    {
        MigrationFixture::tearDownTargetDbTableStructure($this->targetDb);
        parent::tearDown();
    }

    public function test_migrateAction()
    {
        $model = new Model();
        $id1 = $model->createNewIdAction('foo', 1, 1);
        $id2 = $model->createNewIdAction('bar', 1, 1);
        $id3 = $model->createNewIdAction('baz', 1, 1);
        $id4 = $model->createNewIdAction('baz', 2, 1);

        $targetId1 = $this->migration->migrateAction($id1, $this->targetDb);
        $this->assertEquals(1, $targetId1);

        // should return same ID and not create new entry
        $targetId1 = $this->migration->migrateAction($id1, $this->targetDb);
        $this->assertEquals(1, $targetId1);

        $targetId2 = $this->migration->migrateAction($id2, $this->targetDb);
        $this->assertEquals(2, $targetId2);

        $targetId3 = $this->migration->migrateAction($id3, $this->targetDb);
        $this->assertEquals(3, $targetId3);

        $targetId4 = $this->migration->migrateAction($id4, $this->targetDb);
        $this->assertEquals(4, $targetId4);

        // should return same ID
        $targetId3 = $this->migration->migrateAction($id3, $this->targetDb);
        $this->assertEquals(3, $targetId3);
    }

}
