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
 * The goals of the site the attribution reports are processed for. The records of these reports contain the goal
 * ID, which is the same on the target unless the reports aren't copied.
 */
final class MultiChannelConversionAttribution
{
    public static function get(): PluginDefinition
    {
        $definition = new PluginDefinition('MultiChannelConversionAttribution');
        $definition->addTable(TableDefinition::forSite('goal_attribution')->identifiedBy('idgoal'));
        return $definition;
    }
}
