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

    public function __construct(?PluginDataMigrator $pluginDataMigrator = null)
    {
        $this->pluginDataMigrator = $pluginDataMigrator;
    }

    public function validateStructure(TargetDb $targetDb)
    {
        return array();
    }

    public function prepare(TargetDb $targetDb): void
    {
        $this->idArchiveMap = [];
        $this->archiveTables = array_unique(ArchiveTableCreator::getTablesArchivesInstalled());
        $sourcePrefix = Common::prefixTable('');
        foreach ($this->archiveTables as $archiveTable) {
            if ($this->isSourceArchiveTable($archiveTable, $sourcePrefix)) {
                $targetDb->createArchiveTableIfNeeded(substr($archiveTable, strlen($sourcePrefix)));
            }
        }
    }

    public function migrate(Request $request, TargetDb $targetDb)
    {
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

            $this->log('Starting to migrate archive table ' . $archiveTable);
            $archiveTable = substr($archiveTable, strlen($sourcePrefix));

            $numSkippedRecords = 0;
            $batchQuery = new BatchQuery($limit = 1000);
            $archiveTablePrefixed = Common::prefixTable($archiveTable);
            foreach ($batchQuery->generateQuery('SELECT * FROM ' . $archiveTablePrefixed . ' WHERE idsite = ? ORDER BY idarchive,`name` ASC', array($request->sourceIdSite)) as $archives) {
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
}
