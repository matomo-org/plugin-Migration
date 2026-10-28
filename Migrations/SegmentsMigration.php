<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\Migrations;

use Piwik\Plugins\Migration\PluginData\IdMaps;
use Piwik\Plugins\Migration\PluginData\TableDefinition;
use Piwik\Plugins\Migration\TargetDb;

class SegmentsMigration extends BaseMigration
{
    public function validateStructure(TargetDb $targetDb)
    {
        return $this->checkTablesHaveSameStructure($targetDb, 'segment');
    }

    public function migrate(Request $request, TargetDb $targetDb)
    {
        $this->migrateEntities($request, $targetDb, 'segment', 'segments', 'idsegment', 'enable_only_idsite');
        $request->copiedSegmentIds = array_values($request->idMaps->getAll('segment'));
    }

    /**
     * Copies a segment as a segment of the target site, unless the target has the same segment already.
     *
     * @param array<string, mixed> $segment
     * @param int[] $sitesToReuse the sites whose segment with the same definition is used instead of a copy, 0 for
     *                            the segments of all websites
     * @param array<string, string> $pluginSegmentTables plugin IDs that must be translated before reusing a segment
     * @return array{int|string, bool} the target ID, and whether the segment was copied
     */
    public static function copyToSite(array $segment, int $targetIdSite, IdMaps $idMaps, TargetDb $targetDb, array $sitesToReuse, array $pluginSegmentTables = []): array
    {
        $definition = self::pointToTargetIds($segment['definition'], $idMaps);
        if ($definition !== $segment['definition']) {
            $segment['definition'] = $definition;
            if (array_key_exists('hash', $segment)) {
                // like Matomo creates it, the archives of the segment are found by it
                $segment['hash'] = md5(urldecode($definition));
            }
        }

        // Plugin rows have not all been copied yet. A matching source ID can select an unrelated target row.
        // These copies are compared with target segments once every plugin ID has been translated.
        if (!self::hasPluginDataIds($segment['definition'], $pluginSegmentTables)) {
            $existingId = $targetDb->fetchOne(
                'SELECT idsegment FROM `' . $targetDb->prefixTable('segment') . '` WHERE enable_only_idsite IN (' . implode(',', array_map('intval', $sitesToReuse)) . ')'
                . ' AND `definition` = ? AND deleted = ? ORDER BY enable_only_idsite DESC, idsegment LIMIT 1',
                array($segment['definition'], $segment['deleted'])
            );
            if ($existingId) {
                return [$existingId, false];
            }
        }

        unset($segment['idsegment']);
        $segment['enable_only_idsite'] = $targetIdSite;
        return [$targetDb->insert('segment', $segment), true];
    }

    /**
     * Changes the IDs of the goals and custom dimensions a segment definition selects to their IDs on the target, eg
     * "visitConvertedGoalId==1;dimension2==de" to "visitConvertedGoalId==4;dimension3==de". The definition is returned
     * as it is if no ID changes.
     */
    public static function pointToTargetIds(string $definition, IdMaps $idMaps): string
    {
        return self::replaceInConditions($definition, function (string $condition) use ($idMaps) {
            $targetCondition = (string) preg_replace_callback('/^dimension(\d+)(?=[=!<>%])/', function ($matches) use ($idMaps) {
                return 'dimension' . ($idMaps->get(TableDefinition::CUSTOM_DIMENSION, $matches[1]) ?? $matches[1]);
            }, $condition);
            return (string) preg_replace_callback('/^(visitConvertedGoalId(?:==|!=|%3D%3D|%21%3D))(\d+)$/i', function ($matches) use ($idMaps) {
                return $matches[1] . ($idMaps->get(TableDefinition::GOAL, $matches[2]) ?? $matches[2]);
            }, $targetCondition);
        });
    }

    /**
     * Changes the IDs of plugin data a segment definition selects to their IDs on the target, eg "funnels_name==3" to
     * "funnels_name==5", see PluginDefinition::identifiesSegmentsBy(). The definition is returned as it is if no ID
     * changes.
     *
     * @param array<string, string> $segmentTables segment => table of the rows it selects by ID
     */
    public static function pointPluginDataToTargetIds(string $definition, IdMaps $idMaps, array $segmentTables): string
    {
        return self::replaceInConditions($definition, function (string $condition) use ($idMaps, $segmentTables) {
            return (string) preg_replace_callback('/^([a-zA-Z0-9_.]+)(==|!=|%3D%3D|%21%3D)(\d+)$/i', function ($matches) use ($idMaps, $segmentTables) {
                if (!isset($segmentTables[$matches[1]])) {
                    return $matches[0];
                }
                return $matches[1] . $matches[2] . ($idMaps->get($segmentTables[$matches[1]], $matches[3]) ?? $matches[3]);
            }, $condition);
        });
    }

    /**
     * @param array<string, string> $segmentTables segment => table of the rows it selects by ID
     */
    public static function hasPluginDataIds(string $definition, array $segmentTables): bool
    {
        $hasPluginDataIds = false;
        self::replaceInConditions($definition, function (string $condition) use ($segmentTables, &$hasPluginDataIds) {
            if (preg_match('/^([a-zA-Z0-9_.]+)(==|!=|%3D%3D|%21%3D)(\d+)$/i', $condition, $matches) && isset($segmentTables[$matches[1]])) {
                $hasPluginDataIds = true;
            }
            return $condition;
        });
        return $hasPluginDataIds;
    }

    /**
     * @param callable(string): string $replace changes a condition of the definition, eg "visitCount==5"
     */
    private static function replaceInConditions(string $definition, callable $replace): string
    {
        // a definition can be URL encoded as a whole, then even its operators are
        $isEncoded = !preg_match('/[=<>!]/', $definition);
        $parts = preg_split('/([;,])/', $isEncoded ? urldecode($definition) : $definition, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $isChanged = false;
        foreach ($parts as $i => $part) {
            $targetPart = $replace($part);
            $isChanged = $isChanged || $targetPart !== $part;
            $parts[$i] = $targetPart;
        }
        if (!$isChanged) {
            return $definition;
        }
        return $isEncoded ? urlencode(implode('', $parts)) : implode('', $parts);
    }
}
