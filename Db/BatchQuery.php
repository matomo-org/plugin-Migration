<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\Db;

use Piwik\Db;

class BatchQuery
{
    /**
     * @var int
     */
    private $limit;

    public function __construct($limit = 10000)
    {
        $this->limit = $limit;
    }

    /**
     * Make sure to use an order by primary key or so
     * @param $sql
     * @param array $bind
     * @return \Generator
     * @throws \Exception
     */
    public function generateQuery($sql, $bind = array())
    {
        $offset = 0;
        $query = $this->makeQuery($sql, $offset);

        while ($rows = Db::fetchAll($query, $bind)) {
            $offset += $this->limit;
            $query = $this->makeQuery($sql, $offset);
            yield $rows;
        }
    }

    private function makeQuery($sql, $offset)
    {
        return $sql . ' LIMIT ' . (int)$this->limit . ' OFFSET ' . (int) $offset;
    }
}
