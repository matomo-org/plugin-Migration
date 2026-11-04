<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\PluginData;

use Piwik\Plugins\Migration\PluginData\Definitions\AbTesting;
use Piwik\Plugins\Migration\PluginData\Definitions\AdvertisingConversionExport;
use Piwik\Plugins\Migration\PluginData\Definitions\CrashAnalytics;
use Piwik\Plugins\Migration\PluginData\Definitions\CustomReports;
use Piwik\Plugins\Migration\PluginData\Definitions\FormAnalytics;
use Piwik\Plugins\Migration\PluginData\Definitions\Funnels;
use Piwik\Plugins\Migration\PluginData\Definitions\HeatmapSessionRecording;
use Piwik\Plugins\Migration\PluginData\Definitions\MediaAnalytics;
use Piwik\Plugins\Migration\PluginData\Definitions\MultiChannelConversionAttribution;
use Piwik\Plugins\Migration\PluginData\Definitions\RollUpReporting;
use Piwik\Plugins\Migration\PluginData\Definitions\SearchEngineKeywordsPerformance;
use Piwik\Plugins\Migration\PluginData\Definitions\SEOWebVitals;

/**
 * The plugin data definitions shipped with this plugin, for plugins that don't provide their own.
 */
final class BuiltInDefinitions
{
    /**
     * @return PluginDefinition[]
     */
    public static function get(): array
    {
        return [
            HeatmapSessionRecording::get(),
            Funnels::get(),
            MultiChannelConversionAttribution::get(),
            FormAnalytics::get(),
            AbTesting::get(),
            MediaAnalytics::get(),
            SEOWebVitals::get(),
            CrashAnalytics::get(),
            AdvertisingConversionExport::get(),
            CustomReports::get(),
            SearchEngineKeywordsPerformance::get(),
            RollUpReporting::get(),
        ];
    }
}
