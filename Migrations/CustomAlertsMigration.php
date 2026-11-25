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
use Piwik\Plugins\Migration\PluginData\IdResolver;
use Piwik\Plugins\Migration\PluginData\PluginDataMigrator;
use Piwik\Plugins\Migration\TargetDb;

class CustomAlertsMigration extends BaseMigration
{
    /** @var PluginDataMigrator|null */
    private $pluginDataMigrator;

    public function __construct(?PluginDataMigrator $pluginDataMigrator = null)
    {
        $this->pluginDataMigrator = $pluginDataMigrator;
    }

    public function validateStructure(TargetDb $targetDb)
    {
        $errors = [];
        foreach (['alert', 'alert_site', 'alert_triggered'] as $table) {
            if (!$targetDb->doesTableExist($targetDb->prefixTable($table))) {
                $errors[] = sprintf('CustomAlerts table "%s" is missing in the target database. Install CustomAlerts on the target Matomo first.', $targetDb->prefixTable($table));
                continue;
            }
            $errors = array_merge($errors, $this->checkTablesHaveSameStructure($targetDb, $table));
        }
        return $errors;
    }

    public function migrate(Request $request, TargetDb $targetDb)
    {
        $resolver = $this->pluginDataMigrator === null ? null : new IdResolver(
            $request->idMaps,
            $this->pluginDataMigrator,
            new LogActionMigration(),
            $targetDb
        );
        $alertTable = Common::prefixTable('alert');
        $siteTable = Common::prefixTable('alert_site');
        $historyTable = Common::prefixTable('alert_triggered');

        $alerts = Db::fetchAll(
            'SELECT a.*, s.idsite AS migration_idsite FROM ' . $alertTable . ' a'
            . ' INNER JOIN (SELECT idalert FROM ' . $siteTable . ' WHERE idsite = ?'
            . ' UNION SELECT idalert FROM ' . $historyTable . ' WHERE idsite = ?) relevant_alerts ON relevant_alerts.idalert = a.idalert'
            . ' LEFT JOIN ' . $siteTable . ' s ON a.idalert = s.idalert AND s.idsite = ?'
            . ' ORDER BY a.idalert',
            [$request->sourceIdSite, $request->sourceIdSite, $request->sourceIdSite]
        );

        $maxId = $targetDb->fetchRow('SELECT MAX(idalert) AS idalert FROM ' . $targetDb->prefixTable('alert'));
        $nextId = (int) $maxId['idalert'];
        $idMap = [];
        $added = 0;
        foreach ($alerts as $alert) {
            $sourceId = $alert['idalert'];
            $alert = $this->translateReportReferences($alert, $resolver, 'custom alert ' . $sourceId);
            $isActiveForSite = $alert['migration_idsite'] !== null;
            unset($alert['migration_idsite']);
            unset($alert['idalert']);
            $existingId = $request->isExistingTargetSite ? $this->findExistingAlert($alert, $request, $targetDb) : null;
            $alert['idalert'] = $existingId ?? ++$nextId;
            if ($existingId === null) {
                $targetDb->insert('alert', $alert);
                ++$added;
            }
            $idMap[$sourceId] = $alert['idalert'];
            if ($isActiveForSite && !$targetDb->fetchOne('SELECT 1 FROM ' . $targetDb->quoteTable('alert_site') . ' WHERE idalert = ? AND idsite = ?', [$alert['idalert'], $request->targetIdSite])) {
                $targetDb->insert('alert_site', ['idalert' => $alert['idalert'], 'idsite' => $request->targetIdSite]);
            }
        }
        $this->log(sprintf('Migrated %s custom alerts', $added));

        $historyLookup = null;
        try {
            if ($request->isExistingTargetSite && $idMap !== []) {
                // CustomAlerts has only a primary key on idtriggered. Snapshot the relevant IDs once, in a
                // connection-local indexed table, instead of scanning all target history for every source row.
                // CREATE/DROP TEMPORARY TABLE do not commit the migration's transaction.
                $historyLookup = 'migration_alert_history_' . bin2hex(random_bytes(8));
                $targetDb->getDb()->query(
                    'CREATE TEMPORARY TABLE ' . $targetDb->quoteIdentifier($historyLookup)
                    . ' (idtriggered BIGINT UNSIGNED NOT NULL PRIMARY KEY, idalert INT NOT NULL, KEY idalert (idalert)) ENGINE=InnoDB'
                );
                $targetDb->getDb()->query(
                    'INSERT INTO ' . $targetDb->quoteIdentifier($historyLookup) . ' (idtriggered, idalert)'
                    . ' SELECT idtriggered, idalert FROM ' . $targetDb->quoteTable('alert_triggered')
                    . ' WHERE idsite = ? AND idalert IN (' . Common::getSqlStringFieldsArray($idMap) . ')',
                    array_merge([$request->targetIdSite], array_values($idMap))
                );
            }
            $lastId = 0;
            $count = 0;
            do {
                $rows = Db::fetchAll(
                    'SELECT * FROM ' . $historyTable . ' WHERE idsite = ? AND idtriggered > ? ORDER BY idtriggered LIMIT 1000',
                    [$request->sourceIdSite, $lastId]
                );
                foreach ($rows as $row) {
                    if (!isset($idMap[$row['idalert']])) {
                        throw new \Exception(sprintf('Cannot migrate triggered custom alert %s: its alert definition is missing.', $row['idtriggered']));
                    }
                    $lastId = $row['idtriggered'];
                    // A snapshot may refer to a different report and metric than the current alert definition.
                    $row = $this->translateReportReferences($row, $resolver, 'triggered custom alert ' . $lastId);
                    unset($row['idtriggered']);
                    $row['idsite'] = $request->targetIdSite;
                    $row['idalert'] = $idMap[$row['idalert']];
                    if ($historyLookup !== null && $this->historyExists($row, $targetDb, $historyLookup)) {
                        continue;
                    }
                    $newId = $targetDb->insert('alert_triggered', $row);
                    if ($historyLookup !== null) {
                        // Read back the inserted row: dry runs return a simulated ID but write no history.
                        $targetDb->getDb()->query(
                            'INSERT IGNORE INTO ' . $targetDb->quoteIdentifier($historyLookup) . ' (idtriggered, idalert)'
                            . ' SELECT idtriggered, idalert FROM ' . $targetDb->quoteTable('alert_triggered') . ' WHERE idtriggered = ?',
                            [$newId]
                        );
                    }
                    ++$count;
                }
            } while (count($rows) === 1000);
            $this->log(sprintf('Migrated %s triggered custom alerts', $count));
        } finally {
            if ($historyLookup !== null) {
                $targetDb->getDb()->query('DROP TEMPORARY TABLE IF EXISTS ' . $targetDb->quoteIdentifier($historyLookup));
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function translateReportReferences(array $row, ?IdResolver $resolver, string $description): array
    {
        if ($resolver === null) {
            return $row;
        }

        if (is_string($row['report'])) {
            $report = $resolver->resolveReport($row['report']);
            if ($report === null) {
                throw new \RuntimeException(sprintf(
                    'Cannot migrate %s: its report "%s" could not be migrated. It references missing or excluded plugin data.',
                    $description,
                    $row['report']
                ));
            }
            $row['report'] = $report;
        }

        if (is_string($row['metric']) && preg_match('/^custom_metric_(\d+)$/', $row['metric'], $matches)) {
            $metricId = $resolver->resolve('custom_metrics', $matches[1]);
            if ($metricId === null) {
                throw new \RuntimeException(sprintf(
                    'Cannot migrate %s: its metric "%s" could not be migrated. It references missing or excluded plugin data.',
                    $description,
                    $row['metric']
                ));
            }
            $row['metric'] = 'custom_metric_' . $metricId;
        }

        if (is_string($row['metric'])) {
            $row['metric'] = $resolver->resolveSiteMetric($row['metric']);
        }

        return $row;
    }

    /** @param array<string, mixed> $alert */
    private function findExistingAlert(array $alert, Request $request, TargetDb $targetDb): ?int
    {
        $where = [];
        $bind = [];
        foreach ($alert as $column => $value) {
            $where[] = 'a.' . $targetDb->quoteIdentifier($column) . ' <=> ?';
            $bind[] = $value;
        }
        $bind[] = $request->targetIdSite;
        $bind[] = $request->targetIdSite;
        $id = $targetDb->fetchOne(
            'SELECT a.idalert FROM ' . $targetDb->quoteTable('alert') . ' a WHERE ' . implode(' AND ', $where)
            . ' AND (EXISTS (SELECT 1 FROM ' . $targetDb->quoteTable('alert_site') . ' s WHERE s.idalert = a.idalert AND s.idsite = ?)'
            . ' OR EXISTS (SELECT 1 FROM ' . $targetDb->quoteTable('alert_triggered') . ' h WHERE h.idalert = a.idalert AND h.idsite = ?))'
            . ' ORDER BY a.idalert LIMIT 1',
            $bind
        );
        return $id === false ? null : (int) $id;
    }

    /** @param array<string, mixed> $row */
    private function historyExists(array $row, TargetDb $targetDb, string $historyLookup): bool
    {
        $where = ['lookup.idalert = ?'];
        $bind = [$row['idalert']];
        unset($row['idalert']);
        foreach ($row as $column => $value) {
            $where[] = 'history.' . $targetDb->quoteIdentifier($column) . ' <=> ?';
            $bind[] = $value;
        }
        return (bool) $targetDb->fetchOne(
            'SELECT 1 FROM ' . $targetDb->quoteTable('alert_triggered') . ' history'
            . ' INNER JOIN ' . $targetDb->quoteIdentifier($historyLookup) . ' lookup ON lookup.idtriggered = history.idtriggered'
            . ' WHERE ' . implode(' AND ', $where) . ' LIMIT 1',
            $bind
        );
    }
}
