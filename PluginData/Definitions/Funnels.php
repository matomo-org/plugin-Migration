<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\PluginData\Definitions;

use Piwik\Plugins\Migration\PluginData\IdResolver;
use Piwik\Plugins\Migration\PluginData\PluginDefinition;
use Piwik\Plugins\Migration\PluginData\TableDefinition;

/**
 * The funnels of the goals and the other funnels of the site with their steps, and the steps the visits went through,
 * which the plugin computes from the visits when processing the reports.
 */
final class Funnels
{
    /**
     * The step is reached by converting a goal, the pattern is the goal ID
     */
    private const PATTERN_TYPE_GOAL = 'goal_equals';

    public static function get(): PluginDefinition
    {
        $definition = new PluginDefinition('Funnels');

        // funnels that are changed or deleted are kept, their reports reference them
        $definition->addTable(TableDefinition::forSite('funnel')
            ->autoIncrement('idfunnel')
            ->identifiedBy('idgoal', 'name', 'created_date'));
        $definition->addTable(TableDefinition::childOf('funnel_steps', 'funnel', 'idfunnel')
            ->transforms('pattern', [self::class, 'pointGoalPatternToTargetId'])
            ->identifiedBy('position'));
        $definition->addTable(TableDefinition::forVisit('log_funnel')
            ->references('idfunnel', 'funnel'));

        // eg Funnels_entries_3, Funnels_funnel_nb_conversions_3_2 of revision 2
        $definition->renamesArchiveRecords('/^Funnels_(?:entries|exits|flow|funnel_sum_entries|funnel_sum_exits|funnel_nb_conversions)_(\d+)(?=_|$)/', 'funnel');
        $definition->identifiesReportsBy('idFunnel', 'funnel');
        // eg the visits of a funnel shown from its report, selected by the funnel ID
        $definition->identifiesSegmentsBy('funnels_name', 'funnel');
        $definition->identifiesSubcategoriesBy('Funnels_Funnels', 'funnel');

        return $definition;
    }

    /**
     * @param mixed $pattern
     * @param array<string, mixed> $row
     * @return mixed
     */
    public static function pointGoalPatternToTargetId($pattern, array $row, IdResolver $resolver)
    {
        if (($row['pattern_type'] ?? null) !== self::PATTERN_TYPE_GOAL || !is_scalar($pattern) || !ctype_digit((string) $pattern)) {
            return $pattern;
        }
        return (string) $resolver->resolve(TableDefinition::GOAL, (string) $pattern);
    }
}
