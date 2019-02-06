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
        $this->log(sprintf('Found %s archive tables', count($archiveTables)));

        $configDb = Config::getInstance()->database;
        foreach ($archiveTables as $archiveTable) {
            $this->log('Starting to migrate archive table ' . $archiveTable);
            
            $archiveTable = str_replace($configDb['tables_prefix'], '', $archiveTable);
            $targetDb->createArchiveTableIfNeeded($archiveTable);

            $batchQuery = new BatchQuery($limit = 1000);
            $archiveTablePrefixed = Common::prefixTable($archiveTable);
            foreach ($batchQuery->generateQuery('SELECT * FROM ' . $archiveTablePrefixed . ' WHERE idsite = ? ORDER BY idarchive ASC', array($request->sourceIdSite)) as $archives) {
                foreach ($archives as $archive) {
                    if (!empty($archive['idarchive'])) {
                        $archive['idarchive'] = $this->createArchiveId($targetDb, $archiveTable, $archive['idarchive']);
                        $archive['idsite']    = $request->targetIdSite;
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
            $this->idArchiveMap[$archiveTable][$soruceArchiveId] = $targetDb->createArchiveId($archiveTable);
        }

        return $this->idArchiveMap[$archiveTable][$soruceArchiveId];
    }
}
