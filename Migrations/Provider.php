<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\Migrations;

use Piwik\Plugin;
use Piwik\Plugins\Migration\PluginData\PluginDataMigrator;
use Piwik\Plugins\Migration\PluginData\PluginDefinitions;

class Provider
{
    /**
     * @param $skipLogs
     * @param $skipArchives
     * @param string[] $skipPluginData plugins whose data is not migrated
     * @return BaseMigration[]
     */
    public function getAllMigrations($skipLogs, $skipArchives, array $skipPluginData = [])
    {
        $pluginDataMigrator = new PluginDataMigrator(PluginDefinitions::collect(), $skipPluginData);
        $migrations = [
            new SiteMigration(!$skipLogs),
            new SiteUrlMigration(),
            new SiteSettingMigration(),
            new GoalsMigration(),
            new SegmentsMigration($pluginDataMigrator),
            new AnnotationsMigration()
        ];

        $pluginManager = Plugin\Manager::getInstance();
        if (
            $pluginManager->isPluginActivated('CustomDimensions')
            && $pluginManager->isPluginLoaded('CustomDimensions')
        ) {
            $migrations[] = new CustomDimensionMigration();
        }

        $migrations[] = new PluginDataMigration($pluginDataMigrator, !$skipLogs);

        if (
            $pluginManager->isPluginActivated('CustomAlerts')
            && $pluginManager->isPluginLoaded('CustomAlerts')
        ) {
            $migrations[] = new CustomAlertsMigration($pluginDataMigrator);
        }

        if (!$skipLogs) {
            $migrations[] = new LogMigration($pluginDataMigrator);
        }

        if (!$skipArchives) {
            $migrations[] = new ArchiveMigration($pluginDataMigrator, !$skipLogs);
        }

        return $migrations;
    }
}
