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
 * The results of the PageSpeed audits of the pages of the site, which the reports are processed from. Past audits can't
 * be fetched again, so they are migrated whole, a page's audit of a day once.
 */
final class SEOWebVitals
{
    public static function get(): PluginDefinition
    {
        $definition = new PluginDefinition('SEOWebVitals');
        $definition->addTable(TableDefinition::forSite('log_webvitals_report')
            ->autoIncrement('idlogwebvitalsreport')
            ->identifiedBy('date', 'strategy', 'url'));
        return $definition;
    }
}
