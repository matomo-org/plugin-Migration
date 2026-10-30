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
 * The crashes of the site, merged into groups or not, and the crashes that happened in the visits with their stack
 * traces. The rows of the reports link to the crashes by ID.
 */
final class CrashAnalytics
{
    public static function get(): PluginDefinition
    {
        $definition = new PluginDefinition('CrashAnalytics');

        // like the tracker finds a crash, crashes merged into a group reference the crash the group is shown as
        $definition->addTable(TableDefinition::forSite('log_crash')
            ->autoIncrement('idlogcrash')
            ->referencesOwnTable('group_idlogcrash')
            ->identifiedBy('crash_type', 'crc32_hash', 'message', 'resource_uri'));
        // when a crash or group was seen, keyed by the crash it is shown as
        $definition->addTable(TableDefinition::childOf('log_crash_group', 'log_crash', 'idlogcrash')
            ->identifiedBy('idlogcrash'));
        // the stack traces, shared by all sites
        $definition->addTable(TableDefinition::shared('log_crash_stack', 'idlogcrashstack', ['hash', 'compressed', 'value']));
        $definition->addTable(TableDefinition::forVisit('log_crash_event')
            ->autoIncrement('idlogcrashevent')
            ->references('idlogcrash', 'log_crash')
            ->references('idlogcrashstack', 'log_crash_stack')
            ->copies('idpageview'));

        $definition->translatesArchiveRowColumn('/^CrashAnalytics_/', 'log_crash', 'idlogcrash');
        $definition->identifiesSegmentsBy('crashId', 'log_crash');

        return $definition;
    }
}
