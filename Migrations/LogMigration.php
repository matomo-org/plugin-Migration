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
use Piwik\Plugins\Migration\Db\BatchQuery;
use Piwik\Plugins\Migration\TargetDb;

class LogMigration extends BaseMigration
{
    public function validateStructure(TargetDb $targetDb)
    {
        $errors1 = $this->checkTablesHaveSameStructure($targetDb, 'log_visit');
        $errors2 = $this->checkTablesHaveSameStructure($targetDb, 'log_link_visit_action');
        $errors3 = $this->checkTablesHaveSameStructure($targetDb, 'log_action');
        $errors4 = $this->checkTablesHaveSameStructure($targetDb, 'log_conversion');
        $errors5 = $this->checkTablesHaveSameStructure($targetDb, 'log_conversion_item');
        return array_merge($errors1, $errors2, $errors3, $errors4, $errors5);
    }

    public function migrate(Request $request, TargetDb $targetDb)
    {
        $numVisits = Db::fetchOne('SELECT count(*) FROM ' . Common::prefixTable('log_visit') . ' WHERE idsite = ?', array($request->sourceIdSite));
        $this->log(sprintf('Found %s visits', $numVisits));

        if (!$numVisits) {
            return;
        }

        $logActionMigration = new LogActionMigration();

        $batchQuery = new BatchQuery();
        $count = 0;

        $loggedAt = array(0.05, 0.1, 0.2, 0.4, 0.6, 0.8, 0.9);

        foreach ($batchQuery->generateQuery('SELECT * FROM ' . Common::prefixTable('log_visit') . ' WHERE idsite = ? ORDER BY idvisit ASC',  array($request->sourceIdSite)) as $visitRows) {
            $count += count($visitRows);
            $this->migrateVisits($visitRows, $request, $logActionMigration, $targetDb);

            foreach ($loggedAt as $logAt) {
                if ($numVisits && ($count / $numVisits) > $logAt) {
                    $this->log('Migrated ' . ($logAt * 100) . '% of visits');
                    array_shift($loggedAt);
                }
            }
        }
        $this->log(sprintf('Migrated %s visits. The number of migrated visits may be higher if data is still tracked into the source Matomo while migrating the data', $count));
    }

    private function migrateVisits($visitRows, Request $request, LogActionMigration $logActionMigration, TargetDb $targetDb)
    {
        $visitorIdMap = array();
        $visitorLinkActionMap = array();
        foreach ($visitRows as $row) {
            $oldIdVisit = $row['idvisit'];
            $row['idsite'] = $request->targetIdSite;
            $row['visit_entry_idaction_url'] = $logActionMigration->migrateAction($row['visit_entry_idaction_url'], $targetDb);
            $row['visit_entry_idaction_name'] = $logActionMigration->migrateAction($row['visit_entry_idaction_name'], $targetDb);
            $row['visit_exit_idaction_url'] = $logActionMigration->migrateAction($row['visit_exit_idaction_url'], $targetDb);
            $row['visit_exit_idaction_name'] = $logActionMigration->migrateAction($row['visit_exit_idaction_name'], $targetDb);
            unset($row['idvisit']);
            $visitorIdMap[$oldIdVisit] = $targetDb->insert('log_visit', $row);
        }

        $visitorIds = array_map('intval', array_values($visitorIdMap));
        $visitorIds = implode(',', $visitorIds);

        $batchQuery = new BatchQuery();
        foreach ($batchQuery->generateQuery('SELECT * FROM ' . Common::prefixTable('log_link_visit_action') . ' WHERE idvisit in ('.$visitorIds.') ORDER BY idlink_va ASC') as $actionRows) {
            foreach ($actionRows as $row) {
                $oldIdLinkAction = $row['idlink_va'];
                $row['idvisit'] = $visitorIdMap[$row['idvisit']];
                $row['idsite'] = $request->targetIdSite;
                $row['idaction_url'] = $logActionMigration->migrateAction($row['idaction_url'], $targetDb);
                $row['idaction_url_ref'] = $logActionMigration->migrateAction($row['idaction_url_ref'], $targetDb);
                $row['idaction_name'] = $logActionMigration->migrateAction($row['idaction_name'], $targetDb);
                $row['idaction_name_ref'] = $logActionMigration->migrateAction($row['idaction_name_ref'], $targetDb);
                $row['idaction_event_category'] = $logActionMigration->migrateAction($row['idaction_event_category'], $targetDb);
                $row['idaction_event_action'] = $logActionMigration->migrateAction($row['idaction_event_action'], $targetDb);
                unset($row['idlink_va']);
                $visitorLinkActionMap[$oldIdLinkAction] = $targetDb->insert('log_link_visit_action', $row);
            }
        }
        unset($actionRows);

        $rows = Db::fetchAll('SELECT * FROM ' . Common::prefixTable('log_conversion') . ' WHERE idvisit in ('.$visitorIds.')');
        foreach ($rows as $row) {
            $row['idvisit'] = $visitorIdMap[$row['idvisit']];
            if (isset($row['idlink_va'])) {
                $row['idlink_va'] = $visitorLinkActionMap[$row['idlink_va']];
            } else {
                $row['idlink_va'] = null;
            }
            $row['idsite'] = $request->targetIdSite;
            $row['idaction_url'] = $logActionMigration->migrateAction($row['idaction_url'], $targetDb);
            $targetDb->insert('log_conversion', $row);
        }

        unset($rows);

        $rows = Db::fetchAll('SELECT * FROM ' . Common::prefixTable('log_conversion_item') . ' WHERE idvisit in ('.$visitorIds.')');
        foreach ($rows as $row) {
            $row['idvisit'] = $visitorIdMap[$row['idvisit']];
            $row['idsite'] = $request->targetIdSite;
            $row['idaction_sku'] = $logActionMigration->migrateAction($row['idaction_sku'], $targetDb);
            $row['idaction_name'] = $logActionMigration->migrateAction($row['idaction_name'], $targetDb);
            $row['idaction_category'] = $logActionMigration->migrateAction($row['idaction_category'], $targetDb);
            $row['idaction_category2'] = $logActionMigration->migrateAction($row['idaction_category2'], $targetDb);
            $row['idaction_category3'] = $logActionMigration->migrateAction($row['idaction_category3'], $targetDb);
            $row['idaction_category4'] = $logActionMigration->migrateAction($row['idaction_category4'], $targetDb);
            $row['idaction_category5'] = $logActionMigration->migrateAction($row['idaction_category5'], $targetDb);
            $targetDb->insert('log_conversion_item', $row);
        }

        unset($rows);

        foreach ($visitRows as $visitRow) {
            $usesCustomDimensions = isset($visitRow['last_idlink_va']);
            $hasMapForIdLinkVa = $usesCustomDimensions && isset($visitorLinkActionMap[$visitRow['last_idlink_va']]);
            if ($hasMapForIdLinkVa) {
                $newLastIdLinkVa = $visitorLinkActionMap[$visitRow['last_idlink_va']];

                $targetDb->update('log_visit', array('last_idlink_va' => $newLastIdLinkVa), array(
                    'idvisit' => $visitorIdMap[$visitRow['idvisit']]
                ));
            }
        }

        unset($visitRows);
    }

}
