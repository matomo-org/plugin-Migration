<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\tests\Fixtures;

use Piwik\Archive\Chunk;
use Piwik\Common;
use Piwik\DataTable;
use Piwik\DataTable\Row;
use Piwik\Date;
use Piwik\Db;
use Piwik\Plugins\Migration\Migrations\Request;
use Piwik\Plugins\Migration\PluginData\IdResolver;
use Piwik\Plugins\Migration\PluginData\PluginDefinition;
use Piwik\Plugins\Migration\PluginData\TableDefinition;
use Piwik\Plugins\Migration\TargetDb;

/**
 * Adds the tables and data of a plugin that stores its data like premium plugins do: site tables with auto increment
 * and random keys, visit tables with child tables, a table shared by all sites, references to actions and archive
 * records whose name contains the ID of a row of a plugin table.
 */
class PluginDataFixture extends MigrationFixture
{
    public const PLUGIN_NAME = 'Migration';

    public const CONTAINER_KEY = 'aBcD1234';

    /**
     * IDs of the heatmaps are not 1, 2, ... so a missing translation can't go unnoticed
     */
    public const HEATMAP_IDS = [11, 12];

    /**
     * A heatmap that was deleted, its log and archive rows must not be migrated
     */
    public const DELETED_HEATMAP_ID = 99;

    /**
     * Makes migrating a plugin table fail, to test what is left on the target
     *
     * @var bool
     */
    public static $failMigratingReports = false;

    public static function getPluginDefinition(): PluginDefinition
    {
        $definition = new PluginDefinition(self::PLUGIN_NAME);
        $definition->addTable(TableDefinition::forSite('mtest_heatmap')->autoIncrement('idheatmap')->referencesOwnTable('copy_of_idheatmap')->identifiedBy('name'));
        // metrics shared by all sites, which the reports use
        $definition->addTable(TableDefinition::shared('mtest_metric', 'idmetric', ['name', 'formula'])->keepsUnique('name'));
        // reports of the site, of all websites and of several websites, on the page of a heatmap or of a report
        $definition->addTable(TableDefinition::forSite('mtest_report')->autoIncrement('idreport')->identifiedBy('name')
            ->includesRowsOfSeveralSites('idsite = 0 AND (idsites IS NULL OR FIND_IN_SET(?, idsites))')
            ->transforms('idsites', function () {
                return null;
            })
            ->referencesInJsonList('metrics', 'mtest_metric')
            ->transforms('subcategory', function ($subcategory, array $row, IdResolver $resolver) {
                return $row['category'] === 'MTest_Reports' ? $subcategory : $resolver->resolveSubcategory($row['category'], $subcategory) ?? '';
            })
            ->referencesOwnTable('subcategory', function (array $row) {
                return $row['category'] === 'MTest_Reports';
            })
            ->references('idsegment', TableDefinition::SEGMENT)
            ->transforms('name', function ($name) {
                if (self::$failMigratingReports) {
                    throw new \RuntimeException('Failing on purpose');
                }
                return $name;
            })
            ->transforms('filter', function ($filter, array $row, IdResolver $resolver) {
                return is_string($filter) ? $resolver->resolveSegmentDefinition($filter) : $filter;
            }));
        $definition->addTable(TableDefinition::forSite('mtest_container')->uniqueKey('idcontainer', function () {
            return Common::getRandomString(8);
        })->identifiedBy('name'));
        $definition->addTable(TableDefinition::childOf('mtest_container_item', 'mtest_container', 'idcontainer')
            ->autoIncrement('iditem')
            ->referencesInJsonList('heatmap_ids', 'mtest_heatmap')
            ->identifiedBy('name'));
        $definition->addTable(TableDefinition::shared('mtest_blob', 'idblob', ['hash', 'value']));
        $definition->addTable(TableDefinition::forVisit('log_mtest')->autoIncrement('idlogmtest')->copies('idpageview'));
        $definition->addTable(TableDefinition::childOf('log_mtest_heatmap', 'log_mtest', 'idlogmtest')
            ->references('idheatmap', 'mtest_heatmap'));
        $definition->addTable(TableDefinition::childOf('log_mtest_event', 'log_mtest', 'idlogmtest')
            ->autoIncrement('idevent')
            ->references('idselector', TableDefinition::ACTION)
            ->references('idblob', 'mtest_blob'));
        // data imported per property, the property of a site is its main URL
        $definition->addTable(TableDefinition::forSiteKeys('mtest_import', 'property', 'date', function (int $idSite) {
            return [(string) Db::fetchOne('SELECT main_url FROM ' . Common::prefixTable('site') . ' WHERE idsite = ?', [$idSite])];
        }));
        // flags of the site that a step of its own migrates
        $definition->migratesSiteWith(function (Request $request, TargetDb $targetDb) {
            $flags = Db::fetchAll('SELECT flag FROM ' . Common::prefixTable('mtest_site_flag') . ' WHERE idsite = ? ORDER BY flag', [$request->sourceIdSite]);
            foreach ($flags as $row) {
                $targetDb->insert('mtest_site_flag', ['idsite' => $request->targetIdSite, 'flag' => $row['flag']]);
            }
            return [sprintf('Copied %s flags of the site', count($flags))];
        }, 'mtest_site_flag');
        $definition->renamesArchiveRecords('/^MTest_heatmap_(\d+)(?=_|$)/', 'mtest_heatmap');
        $definition->identifiesReportsBy('idHeatmap', 'mtest_heatmap');
        $definition->identifiesSegmentsBy('mtestHeatmap', 'mtest_heatmap');
        $definition->identifiesSubcategoriesBy('MTest_Heatmaps', 'mtest_heatmap');
        $definition->translatesArchiveRowLabels('/^MTest_heatmaps$/', 'mtest_heatmap', '/^(\d+)(?=_|$)/');
        $definition->translatesArchiveRowColumn('/^MTest_heatmaps$/', 'mtest_heatmap', 'idheatmap');
        $definition->translatesArchiveRowColumnNames('/^MTest_heatmaps$/', 'mtest_metric', '/^metric_(\d+)$/');
        return $definition;
    }

    public function setUp(): void
    {
        $this->createPluginTables();
        parent::setUp();
        $this->insertPluginData();
    }

    public function tearDown(): void
    {
        parent::tearDown();
        foreach (array_keys(self::getTableSql()) as $table) {
            Db::query('DROP TABLE IF EXISTS `' . Common::prefixTable($table) . '`');
        }
    }

    /**
     * @return array<string, string>
     */
    private static function getTableSql(): array
    {
        return [
            'mtest_heatmap' => '`idheatmap` INT UNSIGNED NOT NULL AUTO_INCREMENT, `idsite` INT UNSIGNED NOT NULL,
                `name` VARCHAR(50) NOT NULL, `idgoal` INT NULL, `copy_of_idheatmap` INT UNSIGNED NULL, PRIMARY KEY (`idheatmap`), KEY (`idsite`),
                UNIQUE KEY (`copy_of_idheatmap`)',
            'mtest_metric' => '`idmetric` INT UNSIGNED NOT NULL AUTO_INCREMENT, `name` VARCHAR(50) NOT NULL, `formula` VARCHAR(100) NOT NULL,
                PRIMARY KEY (`idmetric`), UNIQUE KEY (`name`)',
            'mtest_report' => '`idreport` INT UNSIGNED NOT NULL AUTO_INCREMENT, `idsite` INT UNSIGNED NOT NULL, `idsites` VARCHAR(50) NULL,
                `name` VARCHAR(50) NOT NULL, `idsegment` INT NULL, `filter` VARCHAR(255) NULL, `metrics` TEXT NULL,
                `category` VARCHAR(50) NOT NULL DEFAULT \'\', `subcategory` VARCHAR(50) NOT NULL DEFAULT \'\', PRIMARY KEY (`idreport`), UNIQUE KEY (`idsite`, `name`)',
            'mtest_container' => '`idcontainer` VARCHAR(8) NOT NULL, `idsite` INT UNSIGNED NOT NULL,
                `name` VARCHAR(50) NOT NULL, PRIMARY KEY (`idcontainer`)',
            'mtest_container_item' => '`iditem` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `idcontainer` VARCHAR(8) NOT NULL,
                `idsite` INT UNSIGNED NOT NULL, `name` VARCHAR(50) NOT NULL, `heatmap_ids` TEXT NOT NULL,
                PRIMARY KEY (`iditem`)',
            'mtest_blob' => '`idblob` INT UNSIGNED NOT NULL AUTO_INCREMENT, `hash` INT UNSIGNED NOT NULL,
                `value` MEDIUMBLOB NULL, PRIMARY KEY (`idblob`), KEY (`hash`)',
            'log_mtest' => '`idlogmtest` INT UNSIGNED NOT NULL AUTO_INCREMENT, `idsite` INT UNSIGNED NOT NULL,
                `idvisit` BIGINT UNSIGNED NOT NULL, `idlink_va` BIGINT UNSIGNED NULL, `idaction_url` INT UNSIGNED NOT NULL DEFAULT 0,
                `idpageview` CHAR(6) NULL, `server_time` DATETIME NOT NULL, PRIMARY KEY (`idlogmtest`), UNIQUE KEY (`idvisit`, `idpageview`)',
            'log_mtest_heatmap' => '`idlogmtest` INT UNSIGNED NOT NULL, `idheatmap` INT UNSIGNED NOT NULL,
                PRIMARY KEY (`idlogmtest`, `idheatmap`)',
            'log_mtest_event' => '`idevent` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `idlogmtest` INT UNSIGNED NOT NULL,
                `idselector` INT UNSIGNED NULL, `idblob` INT UNSIGNED NULL, `x` SMALLINT NOT NULL, `y` SMALLINT NOT NULL,
                PRIMARY KEY (`idevent`), KEY (`idlogmtest`)',
            'mtest_site_flag' => '`idsite` INT UNSIGNED NOT NULL, `flag` VARCHAR(20) NOT NULL',
            'mtest_import' => '`property` VARCHAR(100) NOT NULL, `date` DATE NOT NULL, `type` VARCHAR(15) NOT NULL, `data` MEDIUMBLOB NULL,
                PRIMARY KEY (`property`, `date`, `type`)',
        ];
    }

    private function createPluginTables(): void
    {
        foreach (self::getTableSql() as $table => $columns) {
            Db::query(sprintf('CREATE TABLE IF NOT EXISTS `%s` (%s)', Common::prefixTable($table), $columns));
        }
    }

    private function insertPluginData(): void
    {
        $idSite = $this->idSite;
        [$heatmap1, $heatmap2] = self::HEATMAP_IDS;

        // the heatmaps reference each other: one a heatmap copied after it, one a heatmap that was deleted
        $this->insert('mtest_heatmap', ['idheatmap' => $heatmap1, 'idsite' => $idSite, 'name' => 'Homepage heatmap', 'idgoal' => 2, 'copy_of_idheatmap' => $heatmap2]);
        $this->insert('mtest_heatmap', ['idheatmap' => $heatmap2, 'idsite' => $idSite, 'name' => 'Product heatmap', 'idgoal' => null,
            'copy_of_idheatmap' => self::DELETED_HEATMAP_ID]);
        // a heatmap of another site, must not be migrated
        $this->insert('mtest_heatmap', ['idheatmap' => 50, 'idsite' => $idSite + 100, 'name' => 'Other site heatmap', 'idgoal' => null]);

        // metrics shared by all sites, the target has one with the same name and another formula, and one like a source one
        $metric = function (string $name, string $formula, string $prefix = '') {
            return $this->insert('mtest_metric', ['name' => $name, 'formula' => $formula], $prefix);
        };
        $unusedMetric = $metric('Unused metric', '{{nb_clicks}} * 2');
        $metrics = [$metric('Clicks per visit', '{{nb_clicks}} / {{nb_visits}}'), $metric('Conversion rate', '{{nb_conversions}} / {{nb_visits}}'),
            $metric('Bounce rate', '{{bounce_count}} / {{nb_visits}}')];
        $metric('Conversion rate', '{{nb_conversions}} / {{nb_uniq_visitors}}', MigrationFixture::TARGET_DB_PREFIX);
        $metric('Bounce rate', '{{bounce_count}} / {{nb_visits}}', MigrationFixture::TARGET_DB_PREFIX);

        $idSegment = Db::fetchOne('SELECT idsegment FROM ' . Common::prefixTable('segment') . " WHERE name = 'bar'");
        // the filter is a segment definition about a heatmap and a goal, the report is on the page of a report added after it
        $idReport = $this->insert('mtest_report', ['idsite' => $idSite, 'name' => 'Report on segment', 'idsegment' => $idSegment,
            'filter' => 'mtestHeatmap==' . $heatmap1 . ';visitConvertedGoalId==1', 'metrics' => json_encode([$metrics[0], $metrics[1]]), 'category' => 'MTest_Reports']);
        $idOtherReport = $this->insert('mtest_report', ['idsite' => $idSite, 'name' => 'Report without segment', 'idsegment' => null,
            'metrics' => json_encode([$metrics[2]]), 'category' => 'MTest_Reports']);
        Db::query('UPDATE ' . Common::prefixTable('mtest_report') . ' SET subcategory = ? WHERE idreport IN (?, ?)', [$idOtherReport, $idReport, $idOtherReport]);
        // reports of all websites and of several websites, on the pages of heatmaps, one has the name of a report of the site
        $this->insert('mtest_report', ['idsite' => 0, 'name' => 'Report of all websites', 'category' => 'MTest_Heatmaps', 'subcategory' => (string) $heatmap1]);
        $this->insert('mtest_report', ['idsite' => 0, 'idsites' => $idSite . ',' . ($idSite + 1), 'name' => 'Report of several websites',
            'category' => 'MTest_Heatmaps', 'subcategory' => (string) self::DELETED_HEATMAP_ID]);
        $this->insert('mtest_report', ['idsite' => 0, 'idsites' => ($idSite + 1) . ',' . ($idSite + 2), 'name' => 'Report of other websites']);
        $this->insert('mtest_report', ['idsite' => 0, 'name' => 'Report on segment', 'metrics' => json_encode([$unusedMetric])]);

        // segments selecting a heatmap by ID, also URL encoded, and by name
        $segment = ['login' => 'superUserLogin', 'enable_all_users' => 0, 'enable_only_idsite' => $idSite, 'auto_archive' => 0, 'ts_created' => '2013-01-01 00:00:00', 'deleted' => 0];
        foreach (
            [
                'Heatmap visitors' => 'visitCount>=1;mtestHeatmap==' . $heatmap1 . ',mtestHeatmap!=' . $heatmap2,
                'Heatmap visitors encoded' => urlencode('mtestHeatmap==' . $heatmap2),
                'Heatmap visitors by name' => 'mtestHeatmap==Homepage heatmap',
            ] as $name => $definition
        ) {
            $this->insert('segment', array_merge($segment, ['name' => $name, 'definition' => $definition, 'hash' => md5(urldecode($definition))]));
        }

        $this->insert('mtest_container', ['idcontainer' => self::CONTAINER_KEY, 'idsite' => $idSite, 'name' => 'Web container']);
        $this->insert('mtest_container_item', ['idcontainer' => self::CONTAINER_KEY, 'idsite' => $idSite, 'name' => 'Both heatmaps',
            'heatmap_ids' => json_encode([$heatmap1, $heatmap2])]);
        $this->insert('mtest_container_item', ['idcontainer' => self::CONTAINER_KEY, 'idsite' => $idSite, 'name' => 'Deleted heatmap',
            'heatmap_ids' => json_encode([(string) self::DELETED_HEATMAP_ID, (string) $heatmap2])]);
        $this->insert('mtest_container_item', ['idcontainer' => self::CONTAINER_KEY, 'idsite' => $idSite, 'name' => 'No heatmap',
            'heatmap_ids' => '']);

        // a blob already on the target is reused, the other one is copied
        $sharedBlob = $this->insert('mtest_blob', ['hash' => crc32('shared'), 'value' => "shared\x00blob"]);
        $this->insert('mtest_blob', ['hash' => crc32('shared'), 'value' => "shared\x00blob"], MigrationFixture::TARGET_DB_PREFIX);
        $ownBlob = $this->insert('mtest_blob', ['hash' => crc32('own'), 'value' => "own\xffblob"]);

        $selector1 = $this->insert('log_action', ['name' => '#main > .button', 'hash' => crc32('#main > .button'), 'type' => 42]);
        $selector2 = $this->insert('log_action', ['name' => 'form input[name=q]', 'hash' => crc32('form input[name=q]'), 'type' => 42]);

        $actions = Db::fetchAll('SELECT idvisit, idlink_va, idaction_url, server_time FROM ' . Common::prefixTable('log_link_visit_action')
            . ' WHERE idsite = ? AND idaction_url IS NOT NULL ORDER BY idlink_va', [$idSite]);
        foreach ($actions as $index => $action) {
            $idLogMtest = $this->insert('log_mtest', [
                'idsite' => $idSite,
                'idvisit' => $action['idvisit'],
                'idlink_va' => $action['idlink_va'],
                'idaction_url' => $action['idaction_url'],
                'idpageview' => 'pv' . $index,
                'server_time' => $action['server_time'],
            ]);
            $this->insert('log_mtest_heatmap', ['idlogmtest' => $idLogMtest, 'idheatmap' => $heatmap1]);
            if ($index === 0) {
                $this->insert('log_mtest_heatmap', ['idlogmtest' => $idLogMtest, 'idheatmap' => $heatmap2]);
                // the log of a deleted heatmap, must not be migrated
                $this->insert('log_mtest_heatmap', ['idlogmtest' => $idLogMtest, 'idheatmap' => self::DELETED_HEATMAP_ID]);
            }
            $this->insert('log_mtest_event', ['idlogmtest' => $idLogMtest, 'idselector' => $selector1, 'idblob' => $sharedBlob, 'x' => 10 + $index, 'y' => 20]);
            $this->insert('log_mtest_event', ['idlogmtest' => $idLogMtest, 'idselector' => $selector2, 'idblob' => $ownBlob, 'x' => 30, 'y' => 40 + $index]);
            $this->insert('log_mtest_event', ['idlogmtest' => $idLogMtest, 'idselector' => null, 'idblob' => null, 'x' => -1, 'y' => -1]);
            if ($index === 0) {
                // more events than fetched by one query, like the mouse moves of a session recording
                for ($i = 0; $i < 2500; $i++) {
                    $this->insert('log_mtest_event', ['idlogmtest' => $idLogMtest, 'idselector' => $selector1, 'idblob' => null, 'x' => 1000 + $i, 'y' => $i]);
                }
            }
        }

        foreach ([[$idSite, 'beta'], [$idSite, 'alpha'], [$idSite + 100, 'other']] as [$idFlagSite, $flag]) {
            $this->insert('mtest_site_flag', ['idsite' => $idFlagSite, 'flag' => $flag]);
        }
        $this->insertImportedData();
        $this->insertArchiveRecords();
    }

    /**
     * Data imported for the property of the site, more days than read by one query, and for another property
     */
    private function insertImportedData(): void
    {
        $property = Db::fetchOne('SELECT main_url FROM ' . Common::prefixTable('site') . ' WHERE idsite = ?', [$this->idSite]);
        $day = Date::factory('2012-08-01');
        for ($i = 0; $i < 150; $i++) {
            $this->insert('mtest_import', ['property' => $property, 'date' => $day->addDay($i)->toString(), 'type' => 'keywords', 'data' => "keywords\x00" . $i]);
        }
        $this->insert('mtest_import', ['property' => $property, 'date' => '2013-01-22', 'type' => 'keywords', 'data' => 'source keywords']);
        $this->insert('mtest_import', ['property' => $property, 'date' => '2013-01-23', 'type' => 'keywords', 'data' => 'keywords']);
        $this->insert('mtest_import', ['property' => $property, 'date' => '2013-01-23', 'type' => 'crawlstats', 'data' => 'crawl stats']);
        // the current state, which has no date
        $this->insert('mtest_import', ['property' => $property, 'date' => '0000-00-00', 'type' => 'crawlerrors', 'data' => 'crawl errors']);
        $this->insert('mtest_import', ['property' => 'https://other.example.com/', 'date' => '2013-01-23', 'type' => 'keywords', 'data' => 'other']);
        // the target imported a day already
        $this->insert('mtest_import', ['property' => $property, 'date' => '2013-01-22', 'type' => 'keywords', 'data' => 'target keywords'], MigrationFixture::TARGET_DB_PREFIX);
    }

    private function insertArchiveRecords(): void
    {
        $numericTable = Common::prefixTable('archive_numeric_2013_01');
        $blobTable = Common::prefixTable('archive_blob_2013_01');
        $idArchive = Db::fetchOne("SELECT idarchive FROM $numericTable WHERE idsite = ? AND period = 1 LIMIT 1", [$this->idSite]);

        // Segment archives are located by their done flag, which contains the segment hash.
        $segment = Db::fetchRow("SELECT hash FROM segment WHERE enable_only_idsite = ? AND name = 'Heatmap visitors'", [$this->idSite]);
        $segmentArchive = (int) Db::fetchOne("SELECT MAX(idarchive) FROM $numericTable") + 1000;
        foreach (['done' . $segment['hash'] . '.all' => 1, 'nb_visits' => 17] as $name => $value) {
            $this->insert('archive_numeric_2013_01', [
                'idarchive' => $segmentArchive, 'name' => $name, 'idsite' => $this->idSite,
                'date1' => '2013-01-23', 'date2' => '2013-01-23', 'period' => 1,
                'ts_archived' => '2013-01-24 00:00:00', 'value' => $value,
            ]);
        }

        foreach (array_merge(self::HEATMAP_IDS, [self::DELETED_HEATMAP_ID]) as $idHeatmap) {
            $archive = ['idarchive' => $idArchive, 'idsite' => $this->idSite, 'date1' => '2013-01-23', 'date2' => '2013-01-23',
                'period' => 1, 'ts_archived' => '2013-01-24 00:00:00'];
            Db::query(
                "INSERT INTO $numericTable (idarchive, name, idsite, date1, date2, period, ts_archived, value) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$archive['idarchive'], 'MTest_heatmap_' . $idHeatmap . '_nb_clicks', $archive['idsite'], $archive['date1'], $archive['date2'],
                $archive['period'],
                $archive['ts_archived'],
                $idHeatmap * 3]
            );
            foreach (['MTest_heatmap_' . $idHeatmap, 'MTest_heatmap_' . $idHeatmap . '_chunk_0_99'] as $name) {
                Db::query(
                    "INSERT INTO $blobTable (idarchive, name, idsite, date1, date2, period, ts_archived, value) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [$archive['idarchive'], $name, $archive['idsite'], $archive['date1'], $archive['date2'], $archive['period'],
                    $archive['ts_archived'],
                    'blob of heatmap ' . $idHeatmap]
                );
            }
        }
        // a report whose rows are the heatmaps, eg "11" or "12_mobile", with a subtable stored in a chunk, and columns of
        // metrics named by their ID, one of a metric no migrated report uses
        [$heatmap1, $heatmap2] = self::HEATMAP_IDS;
        $metricColumn = function (string $name) {
            return 'metric_' . Db::fetchOne('SELECT idmetric FROM ' . Common::prefixTable('mtest_metric') . ' WHERE name = ?', [$name]);
        };
        $report = new DataTable();
        $report->addRowsFromSimpleArray([
            ['label' => $heatmap1, 'nb_clicks' => 5, $metricColumn('Clicks per visit') => 0.5, $metricColumn('Unused metric') => 10],
            ['label' => $heatmap2 . '_mobile', 'nb_clicks' => 3, $metricColumn('Conversion rate') => 0.25],
            ['label' => (string) self::DELETED_HEATMAP_ID, 'nb_clicks' => 1],
            ['label' => 'Not a heatmap', 'nb_clicks' => 2],
            // rows linking to a heatmap, one that was deleted
            ['label' => 'Page with a heatmap', 'nb_clicks' => 4, 'idheatmap' => $heatmap2],
            ['label' => 'Page with a deleted heatmap', 'nb_clicks' => 6, 'idheatmap' => (string) self::DELETED_HEATMAP_ID],
        ]);
        $subtable = new DataTable();
        $subtable->addRowsFromSimpleArray([['label' => (string) $heatmap2, 'nb_clicks' => 2], ['label' => 'other', 'nb_clicks' => 1]]);
        $report->getFirstRow()->setSubtable($subtable);
        $report->addSummaryRow(new Row([Row::COLUMNS => ['label' => DataTable::LABEL_SUMMARY_ROW, 'nb_clicks' => 21, $metricColumn('Bounce rate') => 0.75]]));
        $blobs = $report->getSerialized();
        $records = ['MTest_heatmaps' => $blobs[0]];
        unset($blobs[0]);
        foreach ((new Chunk())->moveArchiveBlobsIntoChunks('MTest_heatmaps', $blobs) as $chunkName => $subtables) {
            $records[$chunkName] = serialize($subtables);
        }
        foreach ($records as $name => $value) {
            Db::query(
                "INSERT INTO $blobTable (idarchive, name, idsite, date1, date2, period, ts_archived, value) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$idArchive, $name, $this->idSite, '2013-01-23', '2013-01-23', 1, '2013-01-24 00:00:00', gzcompress($value)]
            );
        }

        // shares the prefix of the pattern but is not a heatmap record, must be copied as it is
        Db::query(
            "INSERT INTO $numericTable (idarchive, name, idsite, date1, date2, period, ts_archived, value) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$idArchive, 'MTest_heatmap_count', $this->idSite, '2013-01-23', '2013-01-23', 1, '2013-01-24 00:00:00', 2]
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return int the auto increment ID of the row
     */
    private function insert(string $table, array $row, string $prefix = ''): int
    {
        $tablePrefixed = $prefix === '' ? Common::prefixTable($table) : $prefix . $table;
        Db::query(sprintf('INSERT INTO `%s` (`%s`) VALUES (%s)', $tablePrefixed, implode('`,`', array_keys($row)), Common::getSqlStringFieldsArray($row)), array_values($row));
        return (int) Db::get()->lastInsertId();
    }
}
