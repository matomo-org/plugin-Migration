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
 * The A/B tests of the site with their variations, and the variation each visit got. The rows of the reports are the
 * variations, by ID.
 */
final class AbTesting
{
    public static function get(): PluginDefinition
    {
        $definition = new PluginDefinition('AbTesting');

        // the names of the experiments of a site are unique
        $definition->addTable(TableDefinition::forSite('experiments')
            ->autoIncrement('idexperiment')
            ->transforms('success_metrics', [self::class, 'pointSuccessMetricsToTargetGoals'])
            ->identifiedBy('name'));
        // how the significance of a success metric is computed
        $definition->addTable(TableDefinition::childOf('experiments_strategy', 'experiments', 'idexperiment')
            ->transforms('metric', [self::class, 'pointMetricToTargetGoal'])
            ->identifiedBy('metric'));
        $definition->addTable(TableDefinition::childOf('experiments_variations', 'experiments', 'idexperiment')
            ->autoIncrement('idvariation')
            ->identifiedBy('name'));
        // the original version is the variation 0
        $definition->addTable(TableDefinition::forVisit('log_abtesting')
            ->references('idexperiment', 'experiments')
            ->references('idvariation', 'experiments_variations'));

        // eg AbTesting_experiment_3 or AbTesting_experiment_3_sample_nb_revenue_goal_1
        $definition->renamesArchiveRecords('/^AbTesting_(?:experiment|estimated_unique_visitors_bucket_data|estimated_unique_visitors_entered_bucket_data)_(\d+)(?=_|$)/', 'experiments');
        // the rows are the variations, eg "4", or the variations and buckets of unique visitors, eg "4_12"
        $definition->translatesArchiveRowLabels('/^AbTesting_/', 'experiments_variations', '/^(\d+)(?=_|$)/');
        $definition->identifiesReportsBy('idExperiment', 'experiments');
        $definition->identifiesSubcategoriesBy('AbTesting_Experiments', 'experiments');

        return $definition;
    }

    /**
     * The success metrics of an experiment, eg [{"metric":"nb_conversions_goal_3"}], can be about a goal.
     *
     * @param mixed $successMetrics
     * @param array<string, mixed> $row
     * @return mixed
     */
    public static function pointSuccessMetricsToTargetGoals($successMetrics, array $row, IdResolver $resolver)
    {
        $metrics = is_string($successMetrics) && $successMetrics !== '' ? json_decode($successMetrics, true) : null;
        if (!is_array($metrics)) {
            return $successMetrics;
        }
        $targetMetrics = [];
        foreach ($metrics as $i => $metric) {
            if (is_array($metric) && is_string($metric['metric'] ?? null)) {
                $metric['metric'] = self::pointMetricToTargetGoal($metric['metric'], $row, $resolver);
            }
            $targetMetrics[$i] = $metric;
        }
        return $targetMetrics == $metrics ? $successMetrics : json_encode($targetMetrics);
    }

    /**
     * The metrics of a goal include its ID, eg "nb_conversions_goal_3" or "avg_nb_revenue_goal_3".
     *
     * @param mixed $metric
     * @param array<string, mixed> $row
     * @return mixed
     */
    public static function pointMetricToTargetGoal($metric, array $row, IdResolver $resolver)
    {
        if (!is_string($metric)) {
            return $metric;
        }
        return preg_replace_callback('/^((?:avg_)?nb_(?:conversions|revenue)_goal_)(\d+)$/', function ($matches) use ($resolver) {
            return $matches[1] . $resolver->resolve(TableDefinition::GOAL, $matches[2]);
        }, $metric);
    }
}
