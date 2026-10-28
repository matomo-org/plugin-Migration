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
use Piwik\Plugins\Migration\TargetDb;

abstract class BaseMigration
{
    /**
     * @var callable|null
     */
    private $callback;

    public function getName()
    {
        $classname = get_class($this);
        $parts     = explode('\\', $classname);
        return end($parts);
    }

    abstract public function validateStructure(TargetDb $targetDb);

    abstract public function migrate(Request $request, TargetDb $targetDb);

    protected function migrateEntities(Request $request, TargetDb $targetDb, $tableUnprefixed, $entityName, $unsetId = null, $idSiteColumnName = 'idsite')
    {
        $rows = Db::fetchAll('SELECT * FROM ' . Common::prefixTable($tableUnprefixed) . ' WHERE ' . $idSiteColumnName . ' = ?', array($request->sourceIdSite));

        $this->log(sprintf('Found %s %s', count($rows), $entityName));

        foreach ($rows as $row) {
            $row[$idSiteColumnName] = $request->targetIdSite;
            if ($unsetId) {
                $sourceId = $row[$unsetId];
                unset($row[$unsetId]);
                $request->idMaps->set($tableUnprefixed, $sourceId, $targetDb->insert($tableUnprefixed, $row));
            } else {
                $targetDb->insert($tableUnprefixed, $row);
            }
        }
    }

    protected function checkTablesHaveSameStructure(TargetDb $targetDb, $tableName)
    {
        $sourceTable = Common::prefixTable($tableName);
        $columns = DbHelper::getTableColumns($sourceTable);
        $columnNames = array_keys($columns);

        $targetTable = $targetDb->prefixTable($tableName);
        $targetColumns = $targetDb->getTableColumns($targetTable);
        $targetColumnNames = array_keys($targetColumns);

        $errors = array();
        $diff = array_diff($columnNames, $targetColumnNames);
        if (!empty($diff)) {
            $errors[] = sprintf('The following columns are missing in the target DB table "%s": %s', $targetTable, implode(', ', $diff));
        } else {
            $diff = array_diff($targetColumnNames, $columnNames);
            if (!empty($diff)) {
                $errors[] = sprintf('The following columns are missing in the source DB table "%s": %s', $sourceTable, implode(', ', $diff));
            }
        }

        return $errors;
    }

    /**
     * @param array{migrated: int, skipped: int, existing?: int, duplicate?: int, present?: int} $counts
     */
    protected function logRowCounts(string $table, array $counts): void
    {
        $message = sprintf('Migrated %s rows of %s', $counts['migrated'], $table);
        if (!empty($counts['existing'])) {
            $message .= sprintf(', %s rows the existing site had already', $counts['existing']);
        }
        if (!empty($counts['present'])) {
            $message .= sprintf(', %s rows the target had already', $counts['present']);
        }
        if ($counts['skipped']) {
            $message .= sprintf(', skipped %s rows referencing data that was not migrated', $counts['skipped']);
        }
        if (!empty($counts['duplicate'])) {
            $message .= sprintf(', skipped %s rows of all or several sites, as the site has another row with the same identity, eg name', $counts['duplicate']);
        }
        $this->log($message);
    }

    protected function log($message)
    {
        if ($this->callback) {
            call_user_func($this->callback, $message);
        }
    }

    public function onLog($callback)
    {
        $this->callback = $callback;
    }
}
