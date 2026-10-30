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
        foreach ($alerts as $alert) {
            $sourceId = $alert['idalert'];
            $alert = $this->translateReportReferences($alert, $resolver, 'custom alert ' . $sourceId);
            $isActiveForSite = $alert['migration_idsite'] !== null;
            unset($alert['migration_idsite']);
            $alert['idalert'] = ++$nextId;
            $targetDb->insert('alert', $alert);
            $idMap[$sourceId] = $alert['idalert'];
            if ($isActiveForSite) {
                $targetDb->insert('alert_site', ['idalert' => $alert['idalert'], 'idsite' => $request->targetIdSite]);
            }
        }
        $this->log(sprintf('Migrated %s custom alerts', count($alerts)));

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
                $targetDb->insert('alert_triggered', $row);
                ++$count;
            }
        } while (count($rows) === 1000);
        $this->log(sprintf('Migrated %s triggered custom alerts', $count));
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

        return $row;
    }
}
