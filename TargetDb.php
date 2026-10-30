<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration;

use Piwik\Common;
use Piwik\Db;
use Piwik\DbHelper;
use Piwik\Sequence;
use Exception;

class TargetDb
{
    /**
     * @var \Zend_Db_Adapter_Abstract&\Piwik\Db\AdapterInterface
     */
    private $db;

    private $config = [];

    private $dryRun = false;

    private $useTransactions = true;

    public function __construct($config)
    {
        foreach (Db::getDatabaseConfig() as $key => $val) {
            $this->config[$key] = $val; // otherwise we change the reference of DB config :(
        }
        foreach ($config as $key => $val) {
            $this->config[$key] = $val;
        }
        $this->db = $this->testConnection($this->config);
    }

    public function disableTransactions()
    {
        $this->useTransactions = false;
    }

    public function enableDryRun()
    {
        $this->dryRun = true;
    }

    public function beginTransaction()
    {
        if ($this->useTransactions) {
            $this->db->beginTransaction();
        }
    }

    public function rollBack()
    {
        if ($this->useTransactions) {
            $this->db->rollBack();
        }
    }

    public function commit()
    {
        if ($this->useTransactions) {
            $this->db->commit();
        }
    }

    public function fetchRow($sql, $bind = [], $fetchMode = null)
    {
        return $this->db->fetchRow($sql, $bind, $fetchMode);
    }

    public function fetchOne($sql, $bind = [])
    {
        return $this->db->fetchOne($sql, $bind);
    }

    /**
     * @ignore tests only
     */
    public function getDb()
    {
        return $this->db;
    }

    public function getTableColumns($tableName)
    {
        $allColumns = $this->db->fetchAll('SHOW COLUMNS FROM ' . $this->quoteIdentifier($tableName));

        $fields = [];
        foreach ($allColumns as $column) {
            $fields[trim($column['Field'])] = $column;
        }

        return $fields;
    }

    public function doesTableExist($targetDbTableName)
    {
        $foundTable = $this->db->fetchAll('SHOW TABLES LIKE ?', [addcslashes($targetDbTableName, '\\_%')]);

        return !empty($foundTable);
    }

    public function createArchiveTableIfNeeded($table)
    {
        $targetDbTableName = $this->prefixTable($table);

        if (!$this->doesTableExist($targetDbTableName)) {
            $type = 'archive_numeric';
            if (strpos($table, 'blob') !== false) {
                $type = 'archive_blob';
            }
            $createTableSql = DbHelper::getTableCreateSql($type);
            $createTableSql = str_replace(
                'CREATE TABLE ' . Common::prefixTable($type),
                'CREATE TABLE ' . $this->quoteTable($table),
                $createTableSql
            );

            if (!$this->dryRun) {
                $this->db->query($createTableSql);
            }
        }

        $numericTableName = $this->prefixTable(str_replace('archive_blob', 'archive_numeric', $table));

        if (!$this->dryRun) {
            // need to make sure the correct sequence entry exists otherwise there may be random issues where it never gets created properly
            $this->makeSequenceEnsureExists($numericTableName);
        }

        if ($this->doesTableExist($numericTableName)) {
            // make sure sequence value is correct in case it is out of sync see #30
            $val = $this->getMaxArchiveId($numericTableName);

            if (!empty($val)) {
                $val = $val + 20; // +20 to allow other sequences to be created concurrently

                if (!$this->dryRun) {
                    $sequenceTable = $this->quoteTable(Sequence::TABLE_NAME);
                    // we also do +1 if the value is already high just to be safe...
                    $this->db->query("UPDATE $sequenceTable SET `value` = if(`value` < ?, ?, `value` + 1) WHERE `name` = ?", [$val, $val, $numericTableName]);
                }
            }
        }
    }

    public function getMaxArchiveId($targetDbTableNamePrefixed)
    {
        $val = $this->db->fetchOne('SELECT max(idarchive) FROM ' . $this->quoteIdentifier($targetDbTableNamePrefixed));
        if (empty($val)) {
            $val = 0;
        }
        return $val;
    }

    private function makeSequenceEnsureExists($prefixedArchiveNumericTableName)
    {
        $sequence = new Sequence($prefixedArchiveNumericTableName, $this->db, $this->prefixTable(''));

        if (!$sequence->exists()) {
            $sequence->create();
        }

        return $sequence;
    }

    public function createArchiveId($table)
    {
        if ($this->dryRun) {
            return mt_rand(1, 9999);
        }

        $name = $this->prefixTable($table);
        $sequence = $this->makeSequenceEnsureExists($name);

        return $sequence->getNextId();
    }

    public function fetchCol($sql, $bind = [])
    {
        return $this->db->fetchCol($sql, $bind);
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function fetchAll($sql, $bind = [])
    {
        return $this->db->fetchAll($sql, $bind);
    }

    /**
     * @param array<string, int|string> $whereColumns
     */
    public function delete(string $table, array $whereColumns): void
    {
        if ($this->dryRun || empty($whereColumns)) {
            return;
        }

        $where = [];
        foreach (array_keys($whereColumns) as $column) {
            $where[] = $this->quoteIdentifier($column) . ' = ?';
        }
        $this->db->query(sprintf('DELETE FROM %s WHERE %s', $this->quoteTable($table), implode(' AND ', $where)), array_values($whereColumns));
    }

    public function quoteTable(string $table): string
    {
        return $this->quoteIdentifier($this->prefixTable($table));
    }

    public function quoteIdentifier(string $identifier): string
    {
        // Each name is one identifier; dots must not become database/table qualifiers.
        return $this->db->quoteIdentifier([$identifier]);
    }

    public function prefixTable($table)
    {
        return $this->config['tables_prefix'] . $table;
    }

    public function insert($table, $row)
    {
        $columns = implode(',', array_map([$this, 'quoteIdentifier'], array_keys($row)));
        $fields = Common::getSqlStringFieldsArray($row);

        $tablePrefixed = $this->quoteTable($table);

        $sql = sprintf('INSERT INTO %s (%s) VALUES(%s)', $tablePrefixed, $columns, $fields);
        $bind = array_values($row);

        if ($this->dryRun) {
            return mt_rand(1, 999999);
        }

        $this->db->query($sql, $bind);
        $id = $this->db->lastInsertId();

        return (int) $id;
    }

    public function update($table, $columns, $whereColumns)
    {
        if ($this->dryRun) {
            return;
        }

        if (!empty($columns)) {
            $fields = [];
            $bind = [];
            foreach ($columns as $key => $value) {
                $fields[] = $this->quoteIdentifier($key) . ' = ?';
                $bind[] = $value;
            }
            $fields = implode(',', $fields);
            $where = [];
            foreach ($whereColumns as $col => $val) {
                $where[] = $this->quoteIdentifier($col) . ' = ?';
                $bind[] = $val;
            }
            $where = implode(' AND ', $where);
            $query = sprintf('UPDATE %s SET %s WHERE %s', $this->quoteTable($table), $fields, $where);

            $this->db->query($query, $bind);
        }
    }

    /**
     * @param array $config
     * @return \Zend_Db_Adapter_Abstract&Db\AdapterInterface
     */
    private function testConnection($config)
    {
        try {
            $adapter = $config['adapter'];
            if ($adapter === 'WordPress') {
                $adapter = 'Mysqli';
            }
            /** @var \Zend_Db_Adapter_Abstract&Db\AdapterInterface $db every adapter the factory creates extends the Zend adapter */
            $db = @Db\Adapter::factory($adapter, $config);
        } catch (Exception $e) {
            throw new Exception('Cannot connect to the target database: ' . $e->getMessage(), $e->getCode(), $e);
        }
        return $db;
    }
}
