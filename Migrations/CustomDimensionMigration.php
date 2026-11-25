<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\Migrations;

use Piwik\Common;
use Piwik\Db;
use Piwik\Plugins\Migration\PluginData\TableDefinition;
use Piwik\Plugins\Migration\TargetDb;

class CustomDimensionMigration extends BaseMigration
{
    /**
     * The log tables holding the values of the dimensions of each scope. Visit dimensions are copied to conversions.
     */
    private const LOG_TABLES = [
        'visit' => ['log_visit', 'log_conversion'],
        'action' => ['log_link_visit_action'],
    ];

    /**
     * @var array<int, array{dimension: array<string, mixed>, exists: bool}>|null
     */
    private $targetDimensions;

    public function validateStructure(TargetDb $targetDb)
    {
        return $this->checkTablesHaveSameStructure($targetDb, 'custom_dimensions');
    }

    public function getWarnings(Request $request, TargetDb $targetDb): array
    {
        if (!$request->isExistingTargetSite) {
            return [];
        }
        $newIds = $this->describeNewIds($request, $this->mapToTargetDimensions($request, $targetDb));
        if (empty($newIds)) {
            return [];
        }
        return [sprintf(
            'These custom dimensions get other IDs on the existing target site, as it has them with other IDs or uses their IDs for other dimensions: %s. '
            . 'A website setting them by ID, eg with setCustomDimension(), needs to use these IDs when tracking into the target Matomo.',
            implode(', ', $newIds)
        )];
    }

    /**
     * The ID and log column of each dimension on the target are decided before anything is written, as the visits,
     * segments and the data of other plugins reference them.
     */
    public function prepare(Request $request, TargetDb $targetDb): void
    {
        if (!$request->isExistingTargetSite) {
            return;
        }
        $this->targetDimensions = $this->mapToTargetDimensions($request, $targetDb);

        $request->customDimensionIndexes = array_fill_keys(array_keys(self::LOG_TABLES), []);
        foreach ($this->getSourceDimensions($request) as $id => $dimension) {
            $targetDimension = $this->targetDimensions[$id]['dimension'];
            $request->idMaps->set(TableDefinition::CUSTOM_DIMENSION, $id, (int) $targetDimension['idcustomdimension']);
            $request->customDimensionIndexes[$dimension['scope']][(int) $dimension['index']] = (int) $targetDimension['index'];
        }

        if ($request->archivesNotCopiedReason === null && !empty($this->describeNewIds($request, $this->targetDimensions))) {
            $request->archivesNotCopiedReason = 'custom dimensions have other IDs on the target site, which the reports reference';
        }
    }

    public function migrate(Request $request, TargetDb $targetDb)
    {
        $sourceDimensions = $this->getSourceDimensions($request);
        if (!$request->isExistingTargetSite) {
            $this->migrateEntities($request, $targetDb, 'custom_dimensions', 'custom dimensions');
            foreach (array_keys($sourceDimensions) as $id) {
                $request->idMaps->set(TableDefinition::CUSTOM_DIMENSION, $id, $id);
            }
            return;
        }

        $numAdded = 0;
        foreach ($this->targetDimensions ?? [] as $targetDimension) {
            if (!$targetDimension['exists']) {
                $targetDb->insert('custom_dimensions', $targetDimension['dimension']);
                $numAdded++;
            }
        }
        $this->log(sprintf('Found %s custom dimensions, added %s custom dimensions the existing site did not have', count($sourceDimensions), $numAdded));

        $newIds = $this->describeNewIds($request, $this->targetDimensions ?? []);
        if (!empty($newIds)) {
            $this->log('Custom dimensions with other IDs on the target site: ' . implode(', ', $newIds));
        }
    }

    /**
     * A dimension the existing target site has already, ie one with the same name and scope, is the same dimension
     * there. Another dimension keeps its ID and log column if the target site doesn't use them, otherwise it gets
     * the next free ID and the first free column, like a dimension created on the target site would.
     *
     * @return array<int, array{dimension: array<string, mixed>, exists: bool}> the target dimension of each source
     *         dimension, by source dimension ID
     */
    private function mapToTargetDimensions(Request $request, TargetDb $targetDb): array
    {
        $sourceDimensions = $this->getSourceDimensions($request);
        $existingDimensions = $this->byId($targetDb->fetchAll(
            'SELECT * FROM ' . $targetDb->quoteTable('custom_dimensions') . ' WHERE idsite = ? ORDER BY idcustomdimension',
            array($request->targetIdSite)
        ));

        $targetDimensions = [];
        $isMatched = [];
        foreach ([true, false] as $isSameIdOnly) {
            foreach ($sourceDimensions as $id => $dimension) {
                foreach ($existingDimensions as $existingId => $existingDimension) {
                    if (
                        !isset($targetDimensions[$id])
                        && !isset($isMatched[$existingId])
                        && (!$isSameIdOnly || $existingId === $id)
                        && $existingDimension['scope'] === $dimension['scope']
                        && $existingDimension['name'] === $dimension['name']
                    ) {
                        $targetDimensions[$id] = ['dimension' => $existingDimension, 'exists' => true];
                        $isMatched[$existingId] = true;
                    }
                }
            }
        }

        $usedIndexes = array_fill_keys(array_keys(self::LOG_TABLES), []);
        foreach ($existingDimensions as $existingDimension) {
            $usedIndexes[$existingDimension['scope']][] = (int) $existingDimension['index'];
        }
        $installedIndexes = $this->getInstalledIndexes($targetDb);
        $newDimensions = array_diff_key($sourceDimensions, $targetDimensions);
        foreach ($newDimensions as $id => $dimension) {
            $newDimensions[$id]['idsite'] = $request->targetIdSite;
            if (isset($existingDimensions[$id])) {
                $newDimensions[$id]['idcustomdimension'] = null;
            }
            $index = (int) $dimension['index'];
            $scope = $dimension['scope'];
            if (in_array($index, $usedIndexes[$scope] ?? [], true) || !in_array($index, $installedIndexes[$scope] ?? [], true)) {
                $newDimensions[$id]['index'] = null;
            } else {
                $usedIndexes[$scope][] = $index;
            }
        }

        $nextId = max(array_merge([0], array_keys($existingDimensions), array_map('intval', array_filter(array_column($newDimensions, 'idcustomdimension'))))) + 1;
        foreach ($newDimensions as $id => $dimension) {
            if ($dimension['idcustomdimension'] === null) {
                $dimension['idcustomdimension'] = $nextId++;
            }
            if ($dimension['index'] === null) {
                $scope = $dimension['scope'];
                $freeIndexes = array_values(array_diff($installedIndexes[$scope] ?? [], $usedIndexes[$scope] ?? []));
                if (empty($freeIndexes)) {
                    throw new \Exception(sprintf(
                        'The existing target site uses all custom dimensions of scope %s that the target Matomo has, so the custom dimension "%s" of the source site cannot be added. '
                        . 'Please add a custom dimension of this scope on the target Matomo by executing there "./console customdimensions:add-custom-dimension --scope=%s".',
                        $scope,
                        $dimension['name'],
                        $scope
                    ));
                }
                $dimension['index'] = $freeIndexes[0];
                $usedIndexes[$scope][] = $freeIndexes[0];
            }
            $targetDimensions[$id] = ['dimension' => $dimension, 'exists' => false];
        }

        ksort($targetDimensions);
        return $targetDimensions;
    }

    /**
     * @param array<int, array{dimension: array<string, mixed>, exists: bool}> $targetDimensions
     * @return string[] the dimensions getting another ID, eg '"Author" (ID 1 => 3)'
     */
    private function describeNewIds(Request $request, array $targetDimensions): array
    {
        $descriptions = [];
        foreach ($this->getSourceDimensions($request) as $id => $dimension) {
            $targetId = (int) $targetDimensions[$id]['dimension']['idcustomdimension'];
            if ($targetId !== $id) {
                $descriptions[] = sprintf('"%s" (ID %s => %s)', $dimension['name'], $id, $targetId);
            }
        }
        return $descriptions;
    }

    /**
     * @return array<string, int[]> the indexes of the log columns of each scope on the target
     */
    private function getInstalledIndexes(TargetDb $targetDb): array
    {
        $indexes = [];
        foreach (self::LOG_TABLES as $scope => $tables) {
            foreach ($tables as $table) {
                $columns = array_keys($targetDb->getTableColumns($targetDb->prefixTable($table)));
                $tableIndexes = [];
                foreach ($columns as $column) {
                    if (preg_match('/^custom_dimension_(\d+)$/', $column, $matches)) {
                        $tableIndexes[] = (int) $matches[1];
                    }
                }
                $indexes[$scope] = isset($indexes[$scope]) ? array_values(array_intersect($indexes[$scope], $tableIndexes)) : $tableIndexes;
            }
            sort($indexes[$scope]);
        }
        return $indexes;
    }

    /**
     * @return array<int, array<string, mixed>> by dimension ID
     */
    private function getSourceDimensions(Request $request): array
    {
        return $this->byId(Db::fetchAll('SELECT * FROM ' . Common::prefixTable('custom_dimensions') . ' WHERE idsite = ? ORDER BY idcustomdimension', array($request->sourceIdSite)));
    }

    /**
     * @param array<array<string, mixed>> $dimensions
     * @return array<int, array<string, mixed>>
     */
    private function byId(array $dimensions): array
    {
        $dimensionsById = [];
        foreach ($dimensions as $dimension) {
            $dimensionsById[(int) $dimension['idcustomdimension']] = $dimension;
        }
        return $dimensionsById;
    }
}
