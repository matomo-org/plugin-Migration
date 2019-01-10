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

class SiteSettingMigration extends BaseMigration
{
    public function validateStructure(TargetDb $targetDb)
    {
        return $this->checkTablesHaveSameStructure($targetDb, 'site_setting');
    }

    public function migrate(Request $request, TargetDb $targetDb)
    {
        $rows = Db::fetchAll('SELECT * FROM ' . Common::prefixTable('site_setting') . ' WHERE idsite = ?', array($request->sourceIdSite));

        $this->log(sprintf('Found %s site settings', count($rows)));

        foreach ($rows as $row) {
            $row['idsite'] = $request->targetIdSite;
            unset($row['idsite_setting']);
            $targetDb->insert('site_setting', $row);
        }
    }
}
