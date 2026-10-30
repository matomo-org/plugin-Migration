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
 * The videos and audios the visits played, with the parts of the media played. Media are identified by their URL and
 * title, the reports have no IDs.
 */
final class MediaAnalytics
{
    public static function get(): PluginDefinition
    {
        $definition = new PluginDefinition('MediaAnalytics');
        // a view of a media is identified by an ID the tracker generates, the played parts by the same ID
        $definition->addTable(TableDefinition::forVisit('log_media')->copies('idview'));
        $definition->addTable(TableDefinition::forVisit('log_media_plays')->copies('idview'));
        return $definition;
    }
}
