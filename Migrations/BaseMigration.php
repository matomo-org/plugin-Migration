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

    /**
     * Prepares schema before the runner starts the transaction that copies site data.
     * Creating tables inside that transaction would implicitly commit earlier writes.
     */
    public function prepare(Request $request, TargetDb $targetDb): void
    {
    }

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
            // the rows are inserted with the source columns only, so a column only the target has gets its default value
            $diff = array_filter(array_diff($targetColumnNames, $columnNames), function ($column) use ($targetColumns) {
                return !$this->hasDefaultValue($targetColumns[$column]);
            });
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

    public function getWarnings(Request $request, TargetDb $targetDb): array
    {
        return [];
    }

    protected function isDuplicateEntryError(\Exception $e): bool
    {
        // not only the SQLSTATE 23000, which is also used for other constraint violations, eg NULL values
        return strpos($e->getMessage(), 'Duplicate entry') !== false
            || strpos($e->getMessage(), ' 1062 ') !== false;
    }

    /** @param array<string, mixed> $column */
    private function hasDefaultValue(array $column): bool
    {
        $extra = strtolower((string) ($column['Extra'] ?? ''));
        return ($column['Null'] ?? '') === 'YES'
            || $column['Default'] !== null
            || strpos($extra, 'auto_increment') !== false
            || strpos($extra, 'generated') !== false;
    }
}
