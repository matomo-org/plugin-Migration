<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\Migrations;

class Provider
{
    /**
     * @param $skipLogs
     * @param $skipArchives
     * @return BaseMigration[]
     */
    public function getAllMigrations($skipLogs, $skipArchives)
    {
        $migrations = [
            new SiteMigration(),
            new SiteUrlMigration(),
            new SiteSettingMigration(),
            new GoalsMigration()
        ];

        if (!$skipLogs) {
            $migrations[] = new LogMigration();
        }

        if (!$skipArchives) {
            $migrations[] = new ArchiveMigration();
        }

        return $migrations;
    }
}
