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
use Piwik\Plugins\Migration\Commands\Migrate;
use Piwik\Plugins\Migration\PluginData\Definitions\SearchEngineKeywordsPerformance;
use Piwik\Plugins\Migration\PluginData\PluginDefinitions;
use Piwik\Plugins\Migration\PluginData\TableDefinition;
use Piwik\Plugins\Migration\tests\Fixtures\MigrationFixture;
use Piwik\Plugins\Migration\tests\Fixtures\PluginDataFixture;
use Piwik\Tests\Framework\Mock\FakeAccess;
use Piwik\Tests\Framework\TestCase\ConsoleCommandTestCase;

/**
 * @group Migration
 * @group MigrateFailureTest
 * @group Plugins
 */
class MigrateFailureTest extends ConsoleCommandTestCase
{
    public static $fixture = null;
    private const PREFIX = 'rollback_target_';
    private static bool $readEncryptedProperties = false;
    private static int $encryptedPropertyReads = 0;

    public function setUp(): void
    {
        parent::setUp();
        // A second archive month catches commits after an earlier month was already copied.
        Db::query('CREATE TABLE archive_numeric_2099_01 LIKE archive_numeric_2013_01');
        $archive = Db::fetchRow('SELECT * FROM archive_numeric_2013_01 WHERE idsite = 1 LIMIT 1');
        $archive['date1'] = '2099-01-01';
        $archive['date2'] = '2099-01-01';
        Db::get()->insert('archive_numeric_2099_01', $archive);
        foreach (Db::get()->fetchCol('SHOW TABLES') as $table) {
            if (strpos($table, MigrationFixture::TARGET_DB_PREFIX) !== 0) {
                continue;
            }
            $name = substr($table, strlen(MigrationFixture::TARGET_DB_PREFIX));
            Db::query('CREATE TABLE ' . $this->quote(self::PREFIX . $name) . ' LIKE ' . $this->quote($table));
            Db::query('INSERT INTO ' . $this->quote(self::PREFIX . $name) . ' SELECT * FROM ' . $this->quote($table));
        }
        // An unrelated target site must survive every failed attempt and retry.
        $site = Db::fetchRow('SELECT * FROM site WHERE idsite = 1');
        $site['idsite'] = 1000;
        $site['name'] = 'Existing destination site';
        Db::get()->insert(self::PREFIX . 'site', $site);
        ArchiveTableCreator::clear();
    }

    public function tearDown(): void
    {
        foreach ($this->targetTables() as $table) {
            Db::query('DROP TABLE ' . $this->quote($table));
        }
        Db::query('DROP TABLE archive_numeric_2099_01');
        ArchiveTableCreator::clear();
        parent::tearDown();
    }

    /**
     * @dataProvider getFailureCases
     */
    public function test_archiveFailureRollsBackAndCanBeRetried(bool $existingArchives, int $failAfter, bool $throwError): void
    {
        if ($existingArchives) {
            foreach (ArchiveTableCreator::getTablesArchivesInstalled() as $table) {
                if (strpos($table, 'archive_') !== 0) {
                    continue;
                }
                Db::query('CREATE TABLE ' . $this->quote(self::PREFIX . $table) . ' LIKE ' . $this->quote($table));
                $row = Db::fetchRow('SELECT * FROM ' . $this->quote($table) . ' LIMIT 1');
                if ($row) {
                    $row['idarchive'] = 900;
                    $row['idsite'] = 1000;
                    Db::get()->insert(self::PREFIX . $table, $row);
                }
            }
        }
        $before = $this->targetRows();
        $sourceBefore = $this->sourceRows();
        $command = new class extends Migrate {
            public int $failAfter;
            public bool $throwError;
            public int $copiedTables = 0;
            public function logMessage($message)
            {
                parent::logMessage($message);
                if (strpos($message, 'Migrated archive table ') === 0 && ++$this->copiedTables === $this->failAfter) {
                    if ($this->throwError) {
                        throw new \Error('Injected archive failure');
                    }
                    throw new \RuntimeException('Injected archive failure');
                }
            }
        };
        $command->failAfter = $failAfter;
        $command->throwError = $throwError;
        $this->application->add($command);

        try {
            $this->assertSame(1, $this->runCommand());
            $this->assertStringContainsString('Injected archive failure', $this->applicationTester->getDisplay());
        } catch (\Error $error) {
            if (!$throwError) {
                throw $error;
            }
            $this->assertSame('Injected archive failure', $error->getMessage());
        }
        $this->assertSame($failAfter, $command->copiedTables);
        $this->assertTargetUnchanged($before);
        $this->assertSame($sourceBefore, $this->sourceRows());

        $this->application->add(new Migrate());
        $this->assertSame(0, $this->runCommand(), $this->applicationTester->getDisplay());
        $this->assertSame(2, (int) Db::fetchOne('SELECT COUNT(*) FROM ' . self::PREFIX . 'site'));
        $idSite = (int) Db::fetchOne('SELECT MAX(idsite) FROM ' . self::PREFIX . 'site');
        $this->assertSame(2, (int) Db::fetchOne('SELECT COUNT(*) FROM ' . self::PREFIX . 'log_visit WHERE idsite = ?', [$idSite]));
        $this->assertSame(2, (int) Db::fetchOne('SELECT COUNT(*) FROM ' . self::PREFIX . 'mtest_heatmap WHERE idsite = ?', [$idSite]));
        $this->assertGreaterThan(0, (int) Db::fetchOne('SELECT COUNT(*) FROM ' . self::PREFIX . 'archive_numeric_2099_01 WHERE idsite = ?', [$idSite]));
        $this->assertSame('Existing destination site', Db::fetchOne('SELECT name FROM ' . self::PREFIX . 'site WHERE idsite = 1000'));
        $this->assertSame($sourceBefore, $this->sourceRows());
    }

    public static function getFailureCases(): array
    {
        return [
            'new tables, first archive' => [false, 1, false],
            'new tables, later archive' => [false, 3, false],
            'existing archives' => [true, 1, false],
            'PHP error' => [false, 1, true],
        ];
    }

    public function test_dryRunDoesNotCreateArchiveTablesOrChangeData(): void
    {
        $tables = $this->targetTables();
        $before = $this->targetRows();
        $sequences = Db::fetchAll('SELECT * FROM ' . self::PREFIX . 'sequence');
        $this->assertSame(0, $this->runCommand(['--dry-run' => true]), $this->applicationTester->getDisplay());
        $this->assertSame($tables, $this->targetTables());
        $this->assertTargetUnchanged($before);
        $this->assertSame($sequences, Db::fetchAll('SELECT * FROM ' . self::PREFIX . 'sequence'));
    }

    public function test_skipArchivesDoesNotPrepareArchiveTables(): void
    {
        $tables = $this->targetTables();
        $this->assertSame(0, $this->runCommand(['--skip-archives' => true]), $this->applicationTester->getDisplay());
        $this->assertSame($tables, $this->targetTables());
    }

    public function test_preparationFailureDoesNotCopySiteData(): void
    {
        Db::query('DROP TABLE ' . self::PREFIX . 'sequence');
        $before = $this->targetRows();
        $this->assertNotSame(0, $this->runCommand());
        $this->assertStringContainsString(self::PREFIX . 'sequence', $this->applicationTester->getDisplay());
        $this->assertTargetUnchanged($before);
    }

    public function test_unreadableEncryptedSettingRollsBackAndSkipLogsAvoidsItsLookup(): void
    {
        $table = 'mtest_encrypted_import';
        $secret = 'enc:v1:synthetic-unreadable-secret';
        Db::query('CREATE TABLE ' . $this->quote($table) . ' LIKE mtest_import');
        Db::query('CREATE TABLE ' . $this->quote(self::PREFIX . $table) . ' LIKE mtest_import');
        $setting = ['idsite' => 1, 'plugin_name' => 'SearchEngineKeywordsPerformance', 'setting_name' => 'bingsiteurl'];
        Db::get()->insert('site_setting', $setting + ['setting_value' => $secret]);
        Db::get()->insert($table, ['property' => 'https://example.com/', 'date' => '2013-01-01', 'type' => 'keywords', 'data' => 'source data']);
        Db::get()->insert(self::PREFIX . $table, ['property' => 'https://unrelated.example/', 'date' => '2013-01-01', 'type' => 'keywords', 'data' => 'existing target data']);
        self::$readEncryptedProperties = true;
        self::$encryptedPropertyReads = 0;
        try {
            $before = $this->targetRows();
            $sourceBefore = $this->sourceRows();
            $settingsBefore = Db::fetchAll('SELECT * FROM site_setting ORDER BY idsite_setting');
            $importsBefore = Db::fetchAll('SELECT * FROM ' . $this->quote($table));

            $result = $this->runCommand();
            $output = $this->applicationTester->getDisplay();

            $this->assertNotSame(0, $result, $output);
            $this->assertGreaterThan(0, self::$encryptedPropertyReads, 'The real encrypted-property reader must run.');
            $this->assertStringContainsString('Cannot decrypt', $output);
            $this->assertStringContainsString('bingsiteurl', $output);
            $this->assertStringContainsString('site 1', $output);
            $this->assertStringContainsString('--skip-plugin-data=SearchEngineKeywordsPerformance', $output);
            $this->assertStringNotContainsString('synthetic-unreadable-secret', $output);
            $this->assertTargetUnchanged($before);
            $this->assertSame($sourceBefore, $this->sourceRows());
            $this->assertSame($settingsBefore, Db::fetchAll('SELECT * FROM site_setting ORDER BY idsite_setting'));
            $this->assertSame($importsBefore, Db::fetchAll('SELECT * FROM ' . $this->quote($table)));

            // The same unreadable setting is still present; excluding raw logs must avoid its lookup.
            self::$encryptedPropertyReads = 0;
            $this->assertSame(0, $this->runCommand(['--skip-logs' => true]), $this->applicationTester->getDisplay());
            $this->assertSame(0, self::$encryptedPropertyReads);
            $this->assertSame(2, (int) Db::fetchOne('SELECT COUNT(*) FROM ' . self::PREFIX . 'site'));
            $idSite = (int) Db::fetchOne('SELECT MAX(idsite) FROM ' . self::PREFIX . 'site');
            $this->assertSame(2, (int) Db::fetchOne('SELECT COUNT(*) FROM ' . self::PREFIX . 'mtest_heatmap WHERE idsite = ?', [$idSite]));
            $this->assertSame(0, (int) Db::fetchOne('SELECT COUNT(*) FROM ' . self::PREFIX . 'log_visit WHERE idsite = ?', [$idSite]));
            $this->assertSame($before[self::PREFIX . $table], Db::fetchAll('SELECT * FROM ' . $this->quote(self::PREFIX . $table)));
            $this->assertSame('Existing destination site', Db::fetchOne('SELECT name FROM ' . self::PREFIX . 'site WHERE idsite = 1000'));
            $this->assertSame($sourceBefore, $this->sourceRows());
            $this->assertSame($settingsBefore, Db::fetchAll('SELECT * FROM site_setting ORDER BY idsite_setting'));
            $this->assertSame($importsBefore, Db::fetchAll('SELECT * FROM ' . $this->quote($table)));
        } finally {
            self::$readEncryptedProperties = false;
            self::$encryptedPropertyReads = 0;
            Db::query('DROP TABLE ' . $this->quote($table));
            Db::query('DELETE FROM site_setting WHERE idsite = ? AND plugin_name = ? AND setting_name = ? AND setting_value = ?', [1, 'SearchEngineKeywordsPerformance', 'bingsiteurl', $secret]);
        }
    }

    private function assertTargetUnchanged(array $before): void
    {
        $after = $this->targetRows();
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, $after[$table], $table);
            unset($after[$table]);
        }
        // Schema preparation can leave empty archive tables, but no copied analytics rows.
        foreach ($after as $table => $rows) {
            $this->assertSame([], $rows, $table);
        }
    }

    private function targetTables(): array
    {
        return Db::get()->fetchCol('SHOW TABLES LIKE ?', [addcslashes(self::PREFIX, '\\_%') . '%']);
    }

    private function targetRows(): array
    {
        $rows = [];
        foreach ($this->targetTables() as $table) {
            if ($table === self::PREFIX . 'sequence') {
                continue; // Preparing sequence infrastructure can advance reserved archive IDs.
            }
            $rows[$table] = Db::fetchAll('SELECT * FROM ' . $this->quote($table));
        }
        return $rows;
    }

    private function sourceRows(): array
    {
        $rows = [];
        foreach (['site', 'mtest_heatmap', 'mtest_report', 'log_visit', 'archive_numeric_2013_01', 'archive_blob_2013_01', 'archive_numeric_2099_01'] as $table) {
            $rows[$table] = Db::fetchAll('SELECT * FROM ' . $this->quote($table));
        }
        return $rows;
    }

    private function quote(string $name): string
    {
        return Db::get()->quoteIdentifier([$name]);
    }

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
            '--target-db-prefix' => self::PREFIX,
            '--no-interaction' => true,
        ], $options));
    }

    public static function provideContainerConfigBeforeClass()
    {
        return [
            Access::class => new FakeAccess(),
            'observers.global' => \Piwik\DI::add([
                ['Migration.addPluginDefinitions', \Piwik\DI::value(function (PluginDefinitions $definitions) {
                    $definition = PluginDataFixture::getPluginDefinition();
                    if (self::$readEncryptedProperties) {
                        // Exercise the shipped reader through ordinary migration phases without licensed tables.
                        $definition->addTable(TableDefinition::forSiteKeys('mtest_encrypted_import', 'property', 'date', function (int $idSite) {
                            ++self::$encryptedPropertyReads;
                            return SearchEngineKeywordsPerformance::getPropertiesOfSite($idSite, 'bingsiteurl');
                        }));
                    }
                    $definitions->add($definition);
                })],
            ]),
        ];
    }
}

MigrateFailureTest::$fixture = new PluginDataFixture();
