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
 * The custom reports of the site, and the custom metrics they use, which are shared by all sites.
 *
 * The reports of all websites and of several websites are shown for the site and archived for it, they are copied as
 * reports of the site. A report on the page of another report, in the category of custom reports, has the ID of that
 * report as subcategory.
 */
final class CustomReports
{
    private const CATEGORY = 'CustomReports_CustomReports';

    public static function get(): PluginDefinition
    {
        $definition = new PluginDefinition('CustomReports');

        // a custom metric of the target with the same name and formula is used, another one with the same name keeps it
        $definition->addTable(TableDefinition::shared('custom_metrics', 'idcustommetric', ['name', 'formula'])
            ->transforms('formula', [self::class, 'pointFormulaToTargetIds'])
            ->keepsUnique('name'));
        $definition->addTable(TableDefinition::forSite('custom_reports')
            ->autoIncrement('idcustomreport')
            ->includesRowsOfSeveralSites('idsite = 0 OR (idsite = -1 AND FIND_IN_SET(?, multiple_idsites))')
            ->transforms('multiple_idsites', function ($multipleIdSites) {
                return $multipleIdSites === null ? null : '';
            })
            ->transforms('dimensions', [self::class, 'pointDimensionsToTargetIds'])
            ->transforms('metrics', [self::class, 'pointMetricsToTargetIds'])
            ->transforms('segment_filter', function ($segment, array $row, IdResolver $resolver) {
                return is_string($segment) && $segment !== '' ? $resolver->resolveSegmentDefinition($segment) : $segment;
            })
            ->transforms('subcategory', function ($subcategory, array $row, IdResolver $resolver) {
                if (!is_string($subcategory) || $subcategory === '' || !is_string($row['category']) || $row['category'] === self::CATEGORY) {
                    return $subcategory;
                }
                // a page that doesn't exist on the target, the report gets a page of its own in the category
                return $resolver->resolveSubcategory($row['category'], $subcategory) ?? '';
            })
            ->referencesOwnTable('subcategory', function (array $row) {
                return $row['category'] === self::CATEGORY && is_string($row['subcategory']) && ctype_digit($row['subcategory']);
            })
            ->identifiedBy('name'));

        $definition->renamesArchiveRecords('/^CustomReports_customreport_(\d+)(?=_)/', 'custom_reports');
        $definition->translatesArchiveRowColumnNames('/^CustomReports_customreport_/', 'custom_metrics', '/^custom_metric_(\d+)$/');
        $definition->identifiesReportsBy('idCustomReport', 'custom_reports');

        $definition->addNote(
            'The custom reports of all websites or of several websites are copied as reports of the site, so they do not change the other websites of the target Matomo. '
            . 'The custom metrics they use are shared by all websites: a custom metric the target Matomo has with the same name and formula is used, '
            . 'a copy of a custom metric whose name the target Matomo uses for another one gets a number appended to its name.'
        );

        return $definition;
    }

    /**
     * The dimensions of a report, eg ["CustomDimension.CustomDimension3"], can be custom dimensions.
     *
     * @param mixed $dimensions
     * @param array<string, mixed> $row
     * @return mixed
     */
    public static function pointDimensionsToTargetIds($dimensions, array $row, IdResolver $resolver)
    {
        $ids = is_string($dimensions) && $dimensions !== '' ? json_decode($dimensions, true) : null;
        if (!is_array($ids)) {
            return $dimensions;
        }
        $targetIds = [];
        foreach ($ids as $id) {
            $targetIds[] = is_string($id) ? self::pointToTargetId($id, '/^CustomDimension\.CustomDimension(\d+)$/', TableDefinition::CUSTOM_DIMENSION, $resolver) ?? $id : $id;
        }
        return $targetIds === $ids ? $dimensions : json_encode($targetIds);
    }

    /**
     * The metrics of a report, eg ["nb_visits","goal_1_conversion","custom_metric_2"], can be about goals and custom
     * dimensions, or be custom metrics. A custom metric that doesn't exist anymore is removed.
     *
     * @param mixed $metrics
     * @param array<string, mixed> $row
     * @return mixed
     */
    public static function pointMetricsToTargetIds($metrics, array $row, IdResolver $resolver)
    {
        $names = is_string($metrics) && $metrics !== '' ? json_decode($metrics, true) : null;
        if (!is_array($names)) {
            return $metrics;
        }
        $targetNames = [];
        foreach ($names as $name) {
            if (!is_string($name)) {
                $targetNames[] = $name;
                continue;
            }
            $targetName = self::pointToTargetId($name, '/^custom_metric_(\d+)$/', 'custom_metrics', $resolver);
            if ($targetName !== null) {
                $targetNames[] = self::pointMetricToTargetIds($targetName, $resolver);
            }
        }
        return $targetNames === $names ? $metrics : json_encode($targetNames);
    }

    /**
     * The formula of a custom metric names metrics as operands, eg "{{nb_visits}} / {{site1_goal_2_conversion}}". The
     * metrics of goals and custom dimensions name the site they are about, an operand about the migrated site is
     * changed to name the target site.
     *
     * @param mixed $formula
     * @param array<string, mixed> $row
     * @return mixed
     */
    public static function pointFormulaToTargetIds($formula, array $row, IdResolver $resolver)
    {
        if (!is_string($formula)) {
            return $formula;
        }
        return preg_replace_callback('/\{\{site([1-9][0-9]*)_([a-zA-Z0-9_]+)\}\}/', function (array $matches) use ($resolver, $row) {
            $idSite = $resolver->resolve(TableDefinition::SITE, $matches[1]);
            if ($idSite === null) {
                if ($resolver->isTargetSiteId($matches[1])) {
                    $metricId = $row['idcustommetric'] ?? null;
                    throw new \RuntimeException(sprintf(
                        'Cannot migrate custom metric %s: its formula references unrelated source website %s, which has the same ID as the destination website. '
                        . 'Keeping the formula would change its result. Review its site-specific references or explicitly exclude CustomReports with --skip-plugin-data=CustomReports before retrying.',
                        is_scalar($metricId) ? $metricId : '(unknown)',
                        $matches[1]
                    ));
                }
                return $matches[0]; // the metric of another site, which has no value for the reports of the site
            }
            return '{{site' . $idSite . '_' . self::pointMetricToTargetIds($matches[2], $resolver) . '}}';
        }, $formula);
    }

    /**
     * A metric about a goal, eg "goal_2_conversion", or about a custom dimension, eg
     * "nb_uniq_customdimension_customdimension3"
     */
    private static function pointMetricToTargetIds(string $metric, IdResolver $resolver): string
    {
        // the IDs of goals and custom dimensions are always resolved, see IdResolver::resolve()
        $metric = self::pointToTargetId($metric, '/^goal_(\d+)_/', TableDefinition::GOAL, $resolver) ?? $metric;
        return self::pointToTargetId($metric, '/customdimension_customdimension(\d+)$/', TableDefinition::CUSTOM_DIMENSION, $resolver) ?? $metric;
    }

    /**
     * @return string|null the value with the ID in the capturing group of the pattern translated, null if the row
     *                     wasn't migrated
     */
    private static function pointToTargetId(string $value, string $pattern, string $table, IdResolver $resolver): ?string
    {
        if (!preg_match($pattern, $value, $matches, PREG_OFFSET_CAPTURE)) {
            return $value;
        }
        [$sourceId, $offset] = $matches[1];
        $targetId = $resolver->resolve($table, $sourceId);
        return $targetId === null ? null : substr_replace($value, (string) $targetId, $offset, strlen($sourceId));
    }
}
