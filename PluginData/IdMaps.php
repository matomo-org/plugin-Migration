<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\PluginData;

/**
 * Remembers for each table which target ID a migrated source row got. Maps created for a batch of visits fall back
 * to the maps of the whole migration, so the rows of a batch can reference the site data migrated before.
 */
final class IdMaps
{
    /**
     * @var array<string, array<int|string, int|string>>
     */
    private $maps = [];

    /**
     * @var IdMaps|null
     */
    private $parent;

    public function __construct(?IdMaps $parent = null)
    {
        $this->parent = $parent;
    }

    /**
     * @param int|string $sourceId
     * @param int|string $targetId
     */
    public function set(string $table, $sourceId, $targetId): void
    {
        $this->maps[$table][$sourceId] = $targetId;
    }

    /**
     * @param array<int|string, int|string> $map source ID => target ID
     */
    public function setAll(string $table, array $map): void
    {
        $this->maps[$table] = $map;
    }

    /**
     * @param int|string $sourceId
     * @return int|string|null the target ID, or null if the source row wasn't migrated
     */
    public function get(string $table, $sourceId)
    {
        if (isset($this->maps[$table])) {
            return $this->maps[$table][$sourceId] ?? null;
        }
        return $this->parent ? $this->parent->get($table, $sourceId) : null;
    }

    /**
     * @return array<int|string, int|string> source ID => target ID
     */
    public function getAll(string $table): array
    {
        if (isset($this->maps[$table])) {
            return $this->maps[$table];
        }
        return $this->parent ? $this->parent->getAll($table) : [];
    }
}
