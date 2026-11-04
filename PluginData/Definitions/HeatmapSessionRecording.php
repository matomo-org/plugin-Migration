<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\PluginData\Definitions;

use Piwik\Plugins\Migration\PluginData\PluginDefinition;
use Piwik\Plugins\Migration\PluginData\TableDefinition;

/**
 * The heatmaps and session recordings configured for the site, with the recorded pageviews and their events. The
 * reports are processed from the recorded data when they are viewed, so there are no archives to rename.
 */
final class HeatmapSessionRecording
{
    public static function get(): PluginDefinition
    {
        $definition = new PluginDefinition('HeatmapSessionRecording');

        // a heatmap or recording that repeats itself is copied when it ends, the copy references its source
        $definition->addTable(TableDefinition::forSite('site_hsr')
            ->autoIncrement('idsitehsr')
            ->referencesOwnTable('repeat_source_idsitehsr')
            ->identifiedBy('record_type', 'name', 'created_date'));
        // a recorded pageview, which can belong to several heatmaps and recordings
        $definition->addTable(TableDefinition::forVisit('log_hsr')
            ->autoIncrement('idloghsr')
            ->copies('idhsrview', 'idpageview'));
        $definition->addTable(TableDefinition::childOf('log_hsr_site', 'log_hsr', 'idloghsr')
            ->references('idsitehsr', 'site_hsr'));
        // texts of the events, eg the DOM of the page, shared by all sites
        $definition->addTable(TableDefinition::shared('log_hsr_blob', 'idhsrblob', ['hash', 'compressed', 'value']));
        $definition->addTable(TableDefinition::childOf('log_hsr_event', 'log_hsr', 'idloghsr')
            ->autoIncrement('idhsrevent')
            ->references('idselector', TableDefinition::ACTION)
            ->references('idhsrblob', 'log_hsr_blob'));

        $definition->identifiesSubcategoriesBy('HeatmapSessionRecording_Heatmaps', 'site_hsr');
        $definition->identifiesSubcategoriesBy('HeatmapSessionRecording_SessionRecordings', 'site_hsr');

        return $definition;
    }
}
