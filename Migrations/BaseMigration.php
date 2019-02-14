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
        $rows = Db::fetchAll('SELECT * FROM ' . Common::prefixTable($tableUnprefixed) . ' WHERE '.$idSiteColumnName.' = ?', array($request->sourceIdSite));

        $this->log(sprintf('Found %s %s', count($rows), $entityName));

        foreach ($rows as $row) {
            $row[$idSiteColumnName] = $request->targetIdSite;
            if ($unsetId) {
                unset($row[$unsetId]);
            }
            $targetDb->insert($tableUnprefixed, $row);
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
            $errors[] = sprintf('The following tables are missing in the target DB table "%s": %s', $targetTable, implode(', ', $diff));
        } else {
            $diff = array_diff($targetColumnNames, $columnNames);
            if (!empty($diff)) {
                $errors[] = sprintf('The following tables are missing in the source DB table "%s": %s', $sourceTable, implode(', ', $diff));
            }
        }

        return $errors;
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
