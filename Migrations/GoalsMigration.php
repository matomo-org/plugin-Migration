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

class GoalsMigration extends BaseMigration
{
    public function validateStructure(TargetDb $targetDb)
    {
        return $this->checkTablesHaveSameStructure($targetDb, 'goal');
    }

    public function getWarnings(Request $request, TargetDb $targetDb): array
    {
        if (!$request->isExistingTargetSite) {
            return [];
        }
        $newIds = $this->describeNewIds($request, $this->getTargetIds($request, $targetDb));
        if (empty($newIds)) {
            return [];
        }
        return [sprintf(
            'These goals get other IDs on the existing target site, as it has them with other IDs or uses their IDs for other goals: %s. '
            . 'A website tracking them by ID, eg with trackGoal(), needs to use these IDs when tracking into the target Matomo.',
            implode(', ', $newIds)
        )];
    }

    /**
     * The target ID of each goal is decided before anything is written, as the conversions, segments and the data of
     * other plugins reference goals by ID.
     */
    public function prepare(Request $request, TargetDb $targetDb): void
    {
        if (!$request->isExistingTargetSite) {
            return;
        }
        $targetIds = $this->getTargetIds($request, $targetDb);
        $request->idMaps->setAll(TableDefinition::GOAL, $targetIds);
        if ($request->archivesNotCopiedReason === null && !empty($this->describeNewIds($request, $targetIds))) {
            $request->archivesNotCopiedReason = 'goals have other IDs on the target site, which the reports reference';
        }
    }

    public function migrate(Request $request, TargetDb $targetDb)
    {
        $sourceGoals = $this->getSourceGoals($request);
        if (!$request->isExistingTargetSite) {
            $this->migrateEntities($request, $targetDb, 'goal', 'goals');
            foreach (array_keys($sourceGoals) as $id) {
                $request->idMaps->set(TableDefinition::GOAL, $id, $id);
            }
            return;
        }

        $targetIds = $request->idMaps->getAll(TableDefinition::GOAL);
        $targetGoals = $this->getTargetGoals($request, $targetDb);
        $numAdded = 0;
        foreach ($sourceGoals as $id => $goal) {
            if (isset($targetGoals[$targetIds[$id]])) {
                continue; // the existing site has the goal already, see getTargetIds()
            }
            $goal['idsite'] = $request->targetIdSite;
            $goal['idgoal'] = $targetIds[$id];
            $targetDb->insert('goal', $goal);
            $numAdded++;
        }
        $this->log(sprintf('Found %s goals, added %s goals the existing site did not have', count($sourceGoals), $numAdded));

        $newIds = $this->describeNewIds($request, $targetIds);
        if (!empty($newIds)) {
            $this->log('Goals with other IDs on the target site: ' . implode(', ', $newIds));
        }
    }

    /**
     * A goal the existing target site has already, eg from a migration before, is the same goal there. Another goal
     * keeps its ID if the target site doesn't use it, otherwise it gets the next free ID, like a goal created on the
     * target site would.
     *
     * @return array<int, int> source goal ID => target goal ID
     */
    private function getTargetIds(Request $request, TargetDb $targetDb): array
    {
        $sourceGoals = $this->getSourceGoals($request);
        $targetGoals = $this->getTargetGoals($request, $targetDb);

        $targetIds = [];
        $isMatched = [];
        foreach ($sourceGoals as $id => $goal) {
            if (isset($targetGoals[$id]) && $this->isSameGoal($goal, $targetGoals[$id])) {
                $targetIds[$id] = $id;
                $isMatched[$id] = true;
            }
        }
        foreach ($sourceGoals as $id => $goal) {
            foreach ($targetGoals as $targetId => $targetGoal) {
                if (!isset($targetIds[$id]) && !isset($isMatched[$targetId]) && $this->isSameGoal($goal, $targetGoal)) {
                    $targetIds[$id] = $targetId;
                    $isMatched[$targetId] = true;
                }
            }
        }
        foreach (array_keys($sourceGoals) as $id) {
            if (!isset($targetIds[$id]) && !isset($targetGoals[$id])) {
                $targetIds[$id] = $id;
            }
        }
        $nextId = max(array_merge([0], array_keys($targetGoals), $targetIds)) + 1;
        foreach (array_keys($sourceGoals) as $id) {
            if (!isset($targetIds[$id])) {
                $targetIds[$id] = $nextId++;
            }
        }

        return $targetIds;
    }

    /**
     * @param array<int, int> $targetIds
     * @return string[] the goals getting another ID, eg '"Newsletter" (ID 1 => 4)'
     */
    private function describeNewIds(Request $request, array $targetIds): array
    {
        $descriptions = [];
        foreach ($this->getSourceGoals($request) as $id => $goal) {
            if ($targetIds[$id] !== $id) {
                $descriptions[] = sprintf('"%s" (ID %s => %s)', $goal['name'], $id, $targetIds[$id]);
            }
        }
        return $descriptions;
    }

    /**
     * @param array<string, mixed> $goal
     * @param array<string, mixed> $targetGoal
     */
    private function isSameGoal(array $goal, array $targetGoal): bool
    {
        unset($goal['idsite'], $goal['idgoal']);
        return array_map('strval', $goal) == array_map('strval', array_intersect_key($targetGoal, $goal));
    }

    /**
     * @return array<int, array<string, mixed>> by goal ID
     */
    private function getSourceGoals(Request $request): array
    {
        return $this->byId(Db::fetchAll('SELECT * FROM ' . Common::prefixTable('goal') . ' WHERE idsite = ? ORDER BY idgoal', array($request->sourceIdSite)));
    }

    /**
     * @return array<int, array<string, mixed>> by goal ID, including deleted goals, as their ID isn't used again
     */
    private function getTargetGoals(Request $request, TargetDb $targetDb): array
    {
        return $this->byId($targetDb->fetchAll('SELECT * FROM ' . $targetDb->quoteTable('goal') . ' WHERE idsite = ? ORDER BY idgoal', array($request->targetIdSite)));
    }

    /**
     * @param array<array<string, mixed>> $goals
     * @return array<int, array<string, mixed>>
     */
    private function byId(array $goals): array
    {
        $goalsById = [];
        foreach ($goals as $goal) {
            $goalsById[(int) $goal['idgoal']] = $goal;
        }
        return $goalsById;
    }
}
