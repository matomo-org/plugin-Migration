<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\Migrations;

use Piwik\Common;
use Piwik\Config;
use Piwik\DataAccess\ArchiveTableCreator;
use Piwik\Plugins\Migration\Db\BatchQuery;
use Piwik\Plugins\Migration\TargetDb;

class ArchiveMigration extends BaseMigration
{
    private $idArchiveMap = array();

    public function validateStructure(TargetDb $targetDb)
    {
        return array();
    }

    public function migrate(Request $request, TargetDb $targetDb)
    {
        $archiveTables = ArchiveTableCreator::getTablesArchivesInstalled();
        $archiveTables = array_unique($archiveTables);
        $this->log(sprintf('Found %s archive tables', count($archiveTables)));

        $sourcePrefix = Common::prefixTable('');

        foreach ($archiveTables as $archiveTable) {
            $isSourceTable = strpos($archiveTable, $sourcePrefix . 'archive_') === 0;
            if (!$isSourceTable) {
                $this->log('Skipping table because it is a target table ' . $archiveTable  . ' and source prefix is:' . $sourcePrefix);
                // special case...
                // user is migrating from one matomo instance to another matomo instance with the same DB but different
                // prefix... we can only take into consideration the DB tables that belong to the source DB
                // I think it also only happens when the source DB prefix is an empty string
                continue;
            }

            $this->log('Starting to migrate archive table ' . $archiveTable);
            $archiveTable = str_replace($sourcePrefix, '', $archiveTable);
            $targetDb->createArchiveTableIfNeeded($archiveTable);

            $batchQuery = new BatchQuery($limit = 1000);
            $archiveTablePrefixed = Common::prefixTable($archiveTable);
            foreach ($batchQuery->generateQuery('SELECT * FROM ' . $archiveTablePrefixed . ' WHERE idsite = ? ORDER BY idarchive,`name` ASC', array($request->sourceIdSite)) as $archives) {
                foreach ($archives as $archive) {
                    if (!empty($archive['idarchive'])) {
                        $this->log(sprintf('sourceId %s', $archive['idarchive']));
                        $archive['idarchive'] = $this->createArchiveId($targetDb, $archiveTable, $archive['idarchive']);
                        $archive['idsite']    = $request->targetIdSite;
                        $this->log(sprintf('insert %s for name %s', $archive['idarchive'], $archive['name']));
                        $targetDb->insert($archiveTable, $archive);
                    }
                }
            }
            $this->log('Migrated archive table ' . $archiveTable);
        }
        $this->idArchiveMap = array();
    }

    private function createArchiveId(TargetDb $targetDb, $archiveTable, $soruceArchiveId)
    {
        $archiveTable = str_replace('archive_blob', 'archive_numeric', $archiveTable);

        if (!isset($this->idArchiveMap[$archiveTable])) {
            $this->idArchiveMap[$archiveTable] = array();
        }

        if (!isset($this->idArchiveMap[$archiveTable][$soruceArchiveId])) {
            $archiveId = $targetDb->createArchiveId($archiveTable);
            $this->log(sprintf('Created archiveId %s for sourceId %s', $archiveId, $soruceArchiveId));
            $this->idArchiveMap[$archiveTable][$soruceArchiveId] = $archiveId;
        }

        return $this->idArchiveMap[$archiveTable][$soruceArchiveId];
    }
}
