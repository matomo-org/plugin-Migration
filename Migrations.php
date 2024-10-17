<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration;

use Piwik\Plugins\Migration\Migrations\BaseMigration;
use Piwik\Plugins\Migration\Migrations\Request;

class Migrations
{
    /**
     * @var callable|null
     */
    private $callback;

    private $dryRun = false;

    public function enableDryRun()
    {
        $this->dryRun = true;
    }

    /**
     * @param BaseMigration[] $migrations
     * @param Request $request
     * @param TargetDb $targetDb
     */
    public function migrate($migrations, Request $request, TargetDb $targetDb)
    {
        $targetDb->beginTransaction();

        try {
            foreach ($migrations as $migration) {
                $this->log('Processing ' . $migration->getName());
                $migration->migrate($request, $targetDb);
                $this->log('Processed ' . $migration->getName());
            }
        } catch (\Exception $e) {
            /**
             * Since php8, PDO::inTransaction() now reports the actual transaction state of the connection, rather than
             * an approximation maintained by PDO. If a query that is subject to "implicit commit" is executed,
             * PDO::inTransaction() will subsequently return false, as a transaction is no longer active
             */
            //inTransaction check fixes warning raised due to implicit commit change
            if ($this->isInTransaction($targetDb)) {
                $targetDb->rollBack();
            }
            if ($this->dryRun) {
                $this->log($e->getTraceAsString());
            }
            throw $e;
        }
        /**
         * Since php8, PDO::inTransaction() now reports the actual transaction state of the connection, rather than an
         * approximation maintained by PDO. If a query that is subject to "implicit commit" is executed,
         * PDO::inTransaction() will subsequently return false, as a transaction is no longer active
         */
        //inTransaction check fixes warning raised due to implicit commit change
        if ($this->isInTransaction($targetDb)) {
            $targetDb->commit();
        }
    }

    private function log($message)
    {
        if ($this->callback) {
            call_user_func($this->callback, $message);
        }
    }

    public function onLog($callback)
    {
        $this->callback = $callback;
    }

    private function isInTransaction($targetDb)
    {
        $inTransactionMethodExists = method_exists($targetDb->getDb()->getConnection(), 'inTransaction');

        return (!$inTransactionMethodExists || $targetDb->getDb()->getConnection()->inTransaction());
    }
}
