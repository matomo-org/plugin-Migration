<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\Migrations;

use Piwik\Common;
use Piwik\DataAccess\ArchiveTableCreator;
use Piwik\Plugins\Migration\Db\BatchQuery;
use Piwik\Plugins\Migration\PluginData\PluginDataMigrator;
use Piwik\Plugins\Migration\TargetDb;

class ArchiveMigration extends BaseMigration
{
    private $idArchiveMap = array();

    /**
     * @var string[]|null source snapshot, taken before destination archive tables are created
     */
    private $archiveTables;

    /**
     * @var PluginDataMigrator|null
     */
    private $pluginDataMigrator;

    /** @var bool */
    private $withRawData;

    public function __construct(?PluginDataMigrator $pluginDataMigrator = null, bool $withRawData = true)
    {
        $this->pluginDataMigrator = $pluginDataMigrator;
        $this->withRawData = $withRawData;
    }

    public function validateStructure(TargetDb $targetDb)
    {
        return array();
    }

    public function prepare(Request $request, TargetDb $targetDb): void
    {
        $this->idArchiveMap = [];
        if ($request->isExistingTargetSite && !$request->targetSiteIsOffline && $request->archivesNotCopiedReason === null) {
            $request->archivesNotCopiedReason = 'copying reports into an empty existing site requires stopping destination tracking and archiving and confirming this with --target-site-is-offline';
        }
        $this->checkReportsCanBeRebuilt($request);
        if ($request->archivesNotCopiedReason !== null) {
            return;
        }
        $this->archiveTables = array_unique(ArchiveTableCreator::getTablesArchivesInstalled());
        $sourcePrefix = Common::prefixTable('');
        foreach ($this->archiveTables as $archiveTable) {
            if ($this->isSourceArchiveTable($archiveTable, $sourcePrefix) && $this->isTableInDateRange($archiveTable, $request)) {
                $targetDb->createArchiveTableIfNeeded(substr($archiveTable, strlen($sourcePrefix)));
            }
        }
        // Archive preparation runs last, before any migrated visits are inserted. Recheck the cached empty-site
        // decision after preparing tables. This detects stale state; the explicit offline assertion, not this
        // point-in-time query, is what requires the operator to prevent writes throughout the migration.
        if ($request->isExistingTargetSite && $this->targetSiteHasData($request, $targetDb)) {
            $request->archivesNotCopiedReason = 'the target site acquired data before copying, which the copied reports would hide';
            $this->checkReportsCanBeRebuilt($request);
        }
    }

    private function targetSiteHasData(Request $request, TargetDb $targetDb): bool
    {
        if ($targetDb->fetchOne('SELECT 1 FROM ' . $targetDb->quoteTable('log_visit') . ' WHERE idsite = ? LIMIT 1', [$request->targetIdSite])) {
            return true;
        }
        $pattern = '/\A' . preg_quote($targetDb->prefixTable('archive_numeric_'), '/') . '[0-9]{4}_[0-9]{2}\z/';
        foreach ($targetDb->fetchCol('SHOW TABLES') as $table) {
            if (preg_match($pattern, $table) && $targetDb->fetchOne('SELECT 1 FROM ' . $targetDb->quoteIdentifier($table) . ' WHERE idsite = ? LIMIT 1', [$request->targetIdSite])) {
                return true;
            }
        }
        return false;
    }

    public function migrate(Request $request, TargetDb $targetDb)
    {
        // Reusing premium definitions can reveal conflicting settings only after their IDs are resolved.
        // In that case an archives-only import must fail inside the transaction instead of losing history silently.
        $this->checkReportsCanBeRebuilt($request);
        if ($request->archivesNotCopiedReason !== null) {
            $this->log(sprintf('The reports are not copied, as %s. They need to be processed on the target Matomo, see the note at the end.', $request->archivesNotCopiedReason));
            return;
        }
        if ($this->archiveTables === null) {
            throw new \LogicException('Archive migration must be prepared before copying data.');
        }
        $this->log(sprintf('Found %s archive tables', count($this->archiveTables)));

        $sourcePrefix = Common::prefixTable('');

        foreach ($this->archiveTables as $archiveTable) {
            if (!$this->isSourceArchiveTable($archiveTable, $sourcePrefix)) {
                $this->log('Skipping table ' . $archiveTable . ' because it is not a monthly archive table of the source');
                continue;
            }

            if (!$this->isTableInDateRange($archiveTable, $request)) {
                continue;
            }
            $this->log('Starting to migrate archive table ' . $archiveTable);
            $archiveTable = substr($archiveTable, strlen($sourcePrefix));

            $numSkippedRecords = 0;
            $batchQuery = new BatchQuery($limit = 1000);
            $archiveTablePrefixed = Common::prefixTable($archiveTable);
            $dateCondition = '';
            $bind = [$request->sourceIdSite];
            if ($request->hasDateRange()) {
                $dateCondition = ' AND date1 >= ? AND date2 <= ?';
                $bind[] = $request->startDate ?? '1991-08-06';
                $bind[] = $request->endDate ?? '2999-12-31';
            }
            foreach ($batchQuery->generateQuery('SELECT * FROM ' . $archiveTablePrefixed . ' WHERE idsite = ?' . $dateCondition . ' ORDER BY idarchive,`name` ASC', $bind) as $archives) {
                foreach ($archives as $archive) {
                    if (!empty($archive['idarchive']) && $this->pluginDataMigrator) {
                        // the name of some plugin records contains the ID of a row of a plugin table, which may be different on the target
                        $name = $this->pluginDataMigrator->getTargetArchiveRecordName($archive['name'], $request->idMaps);
                        if ($name === null) {
                            $numSkippedRecords++;
                            continue;
                        }
                        if (strpos($archiveTable, 'archive_blob_') === 0) {
                            $archive['value'] = $this->pluginDataMigrator->getTargetArchiveBlobValue($archive['name'], $archive['value'], $request->idMaps);
                        }
                        $archive['name'] = $name;
                    }
                    if (!empty($archive['idarchive'])) {
                        $archive['idarchive'] = $this->createArchiveId($targetDb, $archiveTable, $archive['idarchive']);
                        $archive['idsite']    = $request->targetIdSite;
                        try {
                            $targetDb->insert($archiveTable, $archive);
                        } catch (\Exception $e) {
                            if (
                                $e->getCode() == 23000
                                || strpos($e->getMessage(), 'Duplicate entry') !== false
                                || strpos($e->getMessage(), ' 1062 ') !== false
                            ) {
                                $this->log('Duplicate entry in ' . $archiveTable . ' table for archiveID: ' . $archive['idarchive'] . ' and name: ' . $archive['name']);
                                continue;
                            }
                            throw new \Exception($e->getMessage());
                        }
                    }
                }
            }
            if ($numSkippedRecords) {
                $this->log(sprintf('Skipped %s archive records of plugin data that was not migrated', $numSkippedRecords));
            }
            $this->log('Migrated archive table ' . $archiveTable);
        }
        $this->idArchiveMap = array();
    }

    private function checkReportsCanBeRebuilt(Request $request): void
    {
        if ($request->archivesNotCopiedReason !== null && !$this->withRawData) {
            throw new \RuntimeException('Archived reports cannot be copied because ' . $request->archivesNotCopiedReason
                . '. Remove --skip-logs to migrate raw data and rebuild reports, or use --skip-archives to migrate configuration only.');
        }
    }

    private function isSourceArchiveTable(string $table, string $sourcePrefix): bool
    {
        // The core LIKE query also returns tables belonging to overlapping destination prefixes.
        return (bool) preg_match('/\A' . preg_quote($sourcePrefix, '/') . 'archive_(?:numeric|blob)_[0-9]{4}_[0-9]{2}\z/', $table);
    }

    private function createArchiveId(TargetDb $targetDb, $archiveTable, $soruceArchiveId)
    {
        $archiveTable = str_replace('archive_blob', 'archive_numeric', $archiveTable);

        if (!isset($this->idArchiveMap[$archiveTable])) {
            $this->idArchiveMap[$archiveTable] = array();
        }

        if (!isset($this->idArchiveMap[$archiveTable][$soruceArchiveId])) {
            $this->idArchiveMap[$archiveTable][$soruceArchiveId] = $targetDb->createArchiveId($archiveTable);
        }

        return $this->idArchiveMap[$archiveTable][$soruceArchiveId];
    }

    private function isTableInDateRange(string $archiveTable, Request $request): bool
    {
        if (!$request->hasDateRange() || !preg_match('/_(\d{4})_(\d{2})$/', $archiveTable, $matches)) {
            return true;
        }
        $month = $matches[1] . '-' . $matches[2];
        return ($request->startDate === null || $month >= substr($request->startDate, 0, 7))
            && ($request->endDate === null || $month <= substr($request->endDate, 0, 7));
    }
}
