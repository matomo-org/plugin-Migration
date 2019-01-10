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
use Piwik\Plugins\Migration\TargetDb;

class GoalsMigration extends BaseMigration
{
    public function validateStructure(TargetDb $targetDb)
    {
        return $this->checkTablesHaveSameStructure($targetDb, 'goal');
    }

    public function migrate(Request $request, TargetDb $targetDb)
    {
        $rows = Db::fetchAll('SELECT * FROM ' . Common::prefixTable('goal') . ' WHERE idsite = ?', array($request->sourceIdSite));

        $this->log(sprintf('Found %s goals', count($rows)));

        foreach ($rows as $row) {
            $row['idsite'] = $request->targetIdSite;
            $targetDb->insert('goal', $row);
        }
    }
}
