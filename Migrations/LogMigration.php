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
use Piwik\DbHelper;
use Piwik\Plugins\Migration\Db\BatchQuery;
use Piwik\Plugins\Migration\PluginData\PluginDataMigrator;
use Piwik\Plugins\Migration\PluginData\TableDefinition;
use Piwik\Plugins\Migration\TargetDb;

class LogMigration extends BaseMigration
{
    /**
     * @var PluginDataMigrator|null
     */
    private $pluginDataMigrator;

    /** @var int */
    private $numSkippedOrders = 0;

    /** @var array<string, array<string, string|null>> */
    private $customDimensionColumns = [];

    public function __construct(?PluginDataMigrator $pluginDataMigrator = null)
    {
        $this->pluginDataMigrator = $pluginDataMigrator;
    }

    public function validateStructure(TargetDb $targetDb)
    {
        $errors1 = $this->checkTablesHaveSameStructure($targetDb, 'log_visit');
        $errors2 = $this->checkTablesHaveSameStructure($targetDb, 'log_link_visit_action');
        $errors3 = $this->checkTablesHaveSameStructure($targetDb, 'log_action');
        $errors4 = $this->checkTablesHaveSameStructure($targetDb, 'log_conversion');
        $errors5 = $this->checkTablesHaveSameStructure($targetDb, 'log_conversion_item');
        $errors6 = [];
        if ($this->hasPageViewTimeTable()) {
            $targetTable = $targetDb->prefixTable('log_page_view_time');
            $errors6 = $targetDb->doesTableExist($targetTable)
                ? $this->checkTablesHaveSameStructure($targetDb, 'log_page_view_time')
                : [sprintf('The table "%s" is missing in the target DB', $targetTable)];
        }
        return array_merge($errors1, $errors2, $errors3, $errors4, $errors5, $errors6);
    }

    public function migrate(Request $request, TargetDb $targetDb)
    {
        $dateCondition = '';
        $dateBind = [];
        if ($request->hasDateRange()) {
            $dateCondition = ' AND visit_last_action_time BETWEEN ? AND ?';
            $dateBind = $request->getUtcDateTimeRange();
        }
        $numVisits = (int) Db::fetchOne('SELECT count(*) FROM ' . Common::prefixTable('log_visit') . ' WHERE idsite = ?' . $dateCondition, array_merge([$request->sourceIdSite], $dateBind));
        $this->log(sprintf('Found %s visits', $numVisits));

        $logActionMigration = new LogActionMigration();
        if ($this->pluginDataMigrator) {
            foreach ($this->pluginDataMigrator->migrateSiteLogData($request, $targetDb, $logActionMigration) as $table => $counts) {
                $this->logRowCounts($table, $counts);
            }
        }

        if (!$numVisits) {
            return;
        }

        $hasPageViewTimeTable = $this->hasPageViewTimeTable();

        $count = 0;
        $loggedAt = array(0.05, 0.1, 0.2, 0.4, 0.6, 0.8, 0.9);

        $table = Common::prefixTable('log_visit');

        $batchQuery = new BatchQuery();
        $limit = $batchQuery->getLimit();

        $sql = 'SELECT * FROM %s WHERE idsite = ? AND idvisit > ?' . $dateCondition . ' ORDER BY idvisit ASC LIMIT %d';
        $sql = sprintf($sql, $table, $limit);

        $lastIdVisit = 0;
        while ($visitRows = Db::fetchAll($sql, array_merge([$request->sourceIdSite, $lastIdVisit], $dateBind))) {
            $count += count($visitRows);
            $this->migrateVisits($visitRows, $request, $logActionMigration, $targetDb, $hasPageViewTimeTable);
            $lastIdVisit = end($visitRows)['idvisit'];

            foreach ($loggedAt as $logAt) {
                if (($count / $numVisits) > $logAt) {
                    $this->log('Migrated ' . ($logAt * 100) . '% of visits');
                    array_shift($loggedAt);
                }
            }
        }
        $this->log(sprintf('Migrated %s visits. The number of migrated visits may be higher if data is still tracked into the source Matomo while migrating the data', $count));
        if ($this->numSkippedOrders) {
            $this->log(sprintf('Skipped %s ecommerce orders the target site has already', $this->numSkippedOrders));
        }

        if ($this->pluginDataMigrator) {
            foreach ($this->pluginDataMigrator->getVisitDataCounts() as $pluginTable => $counts) {
                $this->logRowCounts($pluginTable, $counts);
            }
        }
    }

    private function migrateVisits($visitRows, Request $request, LogActionMigration $logActionMigration, TargetDb $targetDb, bool $hasPageViewTimeTable)
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
            $row = $this->moveCustomDimensionValues('log_visit', 'visit', $row, $request);
            unset($row['idvisit']);
            $visitorIdMap[$oldIdVisit] = $targetDb->insert('log_visit', $row);
        }

        $visitorIds = array_map('intval', array_keys($visitorIdMap));
        $visitorIds = implode(',', $visitorIds);

        $batchQuery = new BatchQuery();
        foreach ($batchQuery->generateQuery('SELECT * FROM ' . Common::prefixTable('log_link_visit_action') . ' WHERE idvisit in (' . $visitorIds . ') ORDER BY idlink_va ASC') as $actionRows) {
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
                $row = $this->moveCustomDimensionValues('log_link_visit_action', 'action', $row, $request);
                unset($row['idlink_va']);
                $visitorLinkActionMap[$oldIdLinkAction] = $targetDb->insert('log_link_visit_action', $row);
            }
        }
        unset($actionRows);

        $skippedOrders = array();
        $sourceIdVisitByTargetId = array_flip($visitorIdMap);
        $targetGoalIds = $request->idMaps->getAll(TableDefinition::GOAL);
        $rows = Db::fetchAll('SELECT * FROM ' . Common::prefixTable('log_conversion') . ' WHERE idvisit in (' . $visitorIds . ')');
        foreach ($rows as $row) {
            $row['idvisit'] = $visitorIdMap[$row['idvisit']];
            if (isset($row['idlink_va'])) {
                $row['idlink_va'] = $visitorLinkActionMap[$row['idlink_va']];
            } else {
                $row['idlink_va'] = null;
            }
            $row['idsite'] = $request->targetIdSite;
            $row['idaction_url'] = $logActionMigration->migrateAction($row['idaction_url'], $targetDb);
            $row['idgoal'] = $targetGoalIds[$row['idgoal']] ?? $row['idgoal']; // not the ecommerce goals 0 and -1
            $row = $this->moveCustomDimensionValues('log_conversion', 'visit', $row, $request);
            try {
                $targetDb->insert('log_conversion', $row);
            } catch (\Exception $e) {
                // an order is unique per site, the existing target site may have it already, eg tracked into both
                // Matomo while switching the tracking code
                if (empty($row['idorder']) || !$this->isDuplicateEntryError($e)) {
                    throw $e;
                }
                $skippedOrders[$sourceIdVisitByTargetId[$row['idvisit']]][$row['idorder']] = true;
                $this->numSkippedOrders++;
            }
        }

        unset($rows);

        $rows = Db::fetchAll('SELECT * FROM ' . Common::prefixTable('log_conversion_item') . ' WHERE idvisit in (' . $visitorIds . ')');
        foreach ($rows as $row) {
            if (isset($skippedOrders[$row['idvisit']][$row['idorder']])) {
                continue; // the items of a skipped order
            }
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

        if ($hasPageViewTimeTable) {
            foreach ($batchQuery->generateQuery('SELECT * FROM ' . Common::prefixTable('log_page_view_time') . ' WHERE idvisit in (' . $visitorIds . ') ORDER BY idpageviewtime ASC') as $rows) {
                foreach ($rows as $row) {
                    // An action tracked after this batch's actions were copied has no counterpart in the target
                    if (!isset($visitorLinkActionMap[$row['idlink_va']])) {
                        continue;
                    }
                    $row['idvisit'] = $visitorIdMap[$row['idvisit']];
                    $row['idlink_va'] = $visitorLinkActionMap[$row['idlink_va']];
                    $row['idsite'] = $request->targetIdSite;
                    $row['idaction_url'] = $logActionMigration->migrateAction($row['idaction_url'], $targetDb);
                    $row['idaction_name'] = $logActionMigration->migrateAction($row['idaction_name'], $targetDb);
                    unset($row['idpageviewtime']);
                    $targetDb->insert('log_page_view_time', $row);
                }
            }
            unset($rows);
        }

        if ($this->pluginDataMigrator && $this->pluginDataMigrator->hasVisitData()) {
            $this->pluginDataMigrator->migrateVisitData($request, $targetDb, $visitorIdMap, $visitorLinkActionMap, $logActionMigration);
        }

        foreach ($visitRows as $visitRow) {
            $usesCustomDimensions = isset($visitRow['last_idlink_va']);
            $hasMapForIdLinkVa = $usesCustomDimensions && isset($visitorLinkActionMap[$visitRow['last_idlink_va']]);
            if ($hasMapForIdLinkVa) {
                $newLastIdLinkVa = $visitorLinkActionMap[$visitRow['last_idlink_va']];

                $targetDb->update('log_visit', array('last_idlink_va' => $newLastIdLinkVa), array(
                    'idvisit' => $visitorIdMap[$visitRow['idvisit']],
                ));
            }
        }

        unset($visitRows);
    }

    // Core only creates this table from 6.0.0-b6, and the plugin supports 6.0.0-b1 onwards.
    private function hasPageViewTimeTable(): bool
    {
        return DbHelper::tableExists(Common::prefixTable('log_page_view_time'));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function moveCustomDimensionValues(string $table, string $scope, array $row, Request $request): array
    {
        if ($request->customDimensionIndexes === null) {
            return $row;
        }
        if (!isset($this->customDimensionColumns[$table])) {
            $columns = array_fill_keys(preg_grep('/^custom_dimension_\d+$/', array_keys($row)), null);
            foreach ($request->customDimensionIndexes[$scope] ?? [] as $sourceIndex => $targetIndex) {
                $columns['custom_dimension_' . $targetIndex] = 'custom_dimension_' . $sourceIndex;
            }
            $this->customDimensionColumns[$table] = $columns;
        }

        $values = array();
        foreach ($this->customDimensionColumns[$table] as $column => $sourceColumn) {
            $values[$column] = $sourceColumn === null ? null : ($row[$sourceColumn] ?? null);
        }
        return array_merge($row, $values);
    }
}
