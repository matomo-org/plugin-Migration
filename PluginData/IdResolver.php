<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\PluginData;

use Piwik\Plugins\Migration\Migrations\LogActionMigration;
use Piwik\Plugins\Migration\Migrations\SegmentsMigration;
use Piwik\Plugins\Migration\TargetDb;

/**
 * Translates the ID of a source row to the ID of the row it was copied to on the target.
 */
final class IdResolver
{
    /**
     * @var IdMaps
     */
    private $maps;

    /**
     * @var PluginDataMigrator
     */
    private $migrator;

    /**
     * @var LogActionMigration
     */
    private $logActionMigration;

    /**
     * @var TargetDb
     */
    private $targetDb;

    public function __construct(IdMaps $maps, PluginDataMigrator $migrator, LogActionMigration $logActionMigration, TargetDb $targetDb)
    {
        $this->maps = $maps;
        $this->migrator = $migrator;
        $this->logActionMigration = $logActionMigration;
        $this->targetDb = $targetDb;
    }

    public function getTargetDb(): TargetDb
    {
        return $this->targetDb;
    }

    /**
     * @param string $table unprefixed name of the table the ID belongs to
     * @param int|string $sourceId
     * @return int|string|null the target ID, or null if the source row wasn't migrated or doesn't exist
     */
    public function resolve(string $table, $sourceId)
    {
        if ($table === TableDefinition::ACTION) {
            /** @var int|string|null $targetId */
            $targetId = $this->logActionMigration->migrateAction($sourceId, $this->targetDb);
            return $targetId;
        }

        if ($table === TableDefinition::GOAL || $table === TableDefinition::CUSTOM_DIMENSION) {
            return $this->maps->get($table, $sourceId) ?? $sourceId;
        }

        if ($table === TableDefinition::SEGMENT) {
            return $this->maps->get($table, $sourceId) ?? $this->migrator->copySegmentOfAllWebsites($sourceId, $this->maps, $this->targetDb);
        }

        $sharedTable = $this->migrator->getSharedTable($table);
        if ($sharedTable !== null) {
            return $this->migrator->copySharedRow($sharedTable, $sourceId, $this, $this->targetDb);
        }

        return $this->maps->get($table, $sourceId);
    }

    /**
     * Translates the IDs a segment definition of the source holds, eg "visitConvertedGoalId==1;funnels_name==3": the
     * IDs of goals, custom dimensions, and of plugin data migrated before, see PluginDefinition::identifiesSegmentsBy().
     */
    public function resolveSegmentDefinition(string $definition): string
    {
        $definition = SegmentsMigration::pointToTargetIds($definition, $this->maps);
        return SegmentsMigration::pointPluginDataToTargetIds($definition, $this->maps, $this->migrator->getSegmentTables());
    }

    /**
     * Translates the ID of a reporting page, eg the subcategory "3" of the category "Goals_Goals" is the page of the
     * goal 3, see PluginDefinition::identifiesSubcategoriesBy(). Other subcategories are kept.
     *
     * @return string|null the subcategory on the target, null if the page is about data that wasn't migrated
     */
    public function resolveSubcategory(string $category, string $subcategory): ?string
    {
        $table = $this->migrator->getSubcategoryTables()[$category] ?? null;
        $pattern = $table === TableDefinition::CUSTOM_DIMENSION ? '/^customdimension(\d+)$/' : '/^(\d+)$/';
        if ($table === null || !preg_match($pattern, $subcategory, $matches, PREG_OFFSET_CAPTURE)) {
            return $subcategory;
        }
        [$sourceId, $offset] = $matches[1];
        $targetId = $this->resolve($table, $sourceId);
        return $targetId === null ? null : substr_replace($subcategory, (string) $targetId, $offset, strlen($sourceId));
    }

    /**
     * Translates the IDs a unique report ID of the report metadata contains, eg "Goals_get_idGoal--1".
     *
     * @return string|null the unique ID of the report on the target, null if the report is about data that wasn't
     *                     migrated
     */
    public function resolveReport(string $uniqueId): ?string
    {
        if (!preg_match_all('/_([a-zA-Z]+)--(\d+)(?=_|$)/', $uniqueId, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return $uniqueId;
        }

        $tables = $this->migrator->getReportParameters();
        $targetUniqueId = $uniqueId;
        foreach (array_reverse($matches) as $match) {
            [$parameter, $sourceId, $offset] = [$match[1][0], $match[2][0], $match[2][1]];
            if (!isset($tables[$parameter])) {
                continue; // a parameter that isn't an ID, or of a plugin without definition
            }
            $targetId = $this->resolve($tables[$parameter], $sourceId);
            if ($targetId === null) {
                return null;
            }
            $targetUniqueId = substr_replace($targetUniqueId, (string) $targetId, $offset, strlen($sourceId));
        }
        return $targetUniqueId;
    }
}
