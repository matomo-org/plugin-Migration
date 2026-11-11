<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\Migrations;

use Piwik\Common;
use Piwik\Db;
use Piwik\Plugins\Migration\TargetDb;

class SiteMigration extends BaseMigration
{
    /** @var bool */
    private $withArchives;

    public function __construct(bool $withArchives = true)
    {
        $this->withArchives = $withArchives;
    }

    public function validateStructure(TargetDb $targetDb)
    {
        return $this->checkTablesHaveSameStructure($targetDb, 'site');
    }

    public function migrate(Request $request, TargetDb $targetDb)
    {
        if ($request->isExistingTargetSite) {
            $this->migrateIntoExistingSite($request, $targetDb);
            return;
        }

        $row = Db::fetchRow('SELECT * FROM ' . Common::prefixTable('site') . ' WHERE idsite = ?', array($request->sourceIdSite));
        unset($row['idsite']);
        $request->targetIdSite = $targetDb->insert('site', $row);
        $request->idMaps->set('site', $request->sourceIdSite, $request->targetIdSite);

        $this->log(sprintf('Target site is %s', $request->targetIdSite));
    }

    private function migrateIntoExistingSite(Request $request, TargetDb $targetDb): void
    {
        $request->idMaps->set('site', $request->sourceIdSite, $request->targetIdSite);
        $this->log(sprintf('Target site is the existing site %s', $request->targetIdSite));

        // reports of periods before the creation of a site are not processed, so the site needs to be created before
        // the first migrated action
        $dateCondition = '';
        $bind = array($request->sourceIdSite);
        if ($request->hasDateRange()) {
            $dateCondition = ' AND visit_last_action_time BETWEEN ? AND ?';
            $bind = array_merge($bind, $request->getUtcDateTimeRange());
        }
        $firstAction = Db::fetchOne('SELECT MIN(visit_first_action_time) FROM ' . Common::prefixTable('log_visit') . ' WHERE idsite = ?' . $dateCondition, $bind);
        // Archived history can predate every retained visit, or have no raw visits left at all.
        if ($this->withArchives && $request->archivesNotCopiedReason === null) {
            /** @var \Zend_Db_Adapter_Abstract $sourceDb Migration uses the regular DB adapter, outside the tracker. */
            $sourceDb = Db::get();
            $sourceCreated = Db::fetchOne('SELECT ts_created FROM ' . $sourceDb->quoteIdentifier([Common::prefixTable('site')]) . ' WHERE idsite = ?', [$request->sourceIdSite]);
            [$start, $end] = $request->getUtcDateTimeRange();
            $archiveStart = max($sourceCreated, $start);
            if ($archiveStart <= $end && (!$firstAction || $archiveStart < $firstAction)) {
                $firstAction = $archiveStart;
            }
        }
        $targetCreated = $targetDb->fetchOne('SELECT ts_created FROM ' . $targetDb->quoteTable('site') . ' WHERE idsite = ?', array($request->targetIdSite));
        if ($firstAction && (!$targetCreated || $firstAction < $targetCreated)) {
            $targetDb->update('site', array('ts_created' => $firstAction), array('idsite' => $request->targetIdSite));
            $this->log(sprintf('Changed the creation date of the target site to %s to include migrated history', $firstAction));
        }
    }
}
