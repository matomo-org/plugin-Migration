<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\PluginData;

/**
 * Describes how the rows of one plugin table are found in the source DB and which of their columns point to rows of
 * other tables, so the rows can be copied to the target DB with every reference translated to the target IDs.
 *
 * Every table belongs to a parent: the rows copied are those whose parent column holds the ID of a parent row that
 * was copied before. The parent is either the migrated site, a visit, a visit action, or another plugin table.
 * Shared tables have no parent; their rows are copied when a migrated row references them.
 */
final class TableDefinition
{
    public const SITE = 'site';
    public const VISIT = 'log_visit';
    public const LINK_VISIT_ACTION = 'log_link_visit_action';
    public const ACTION = 'log_action';
    public const SEGMENT = 'segment';
    /**
     * Goals and custom dimensions keep their ID, unless the existing target site uses it for another one. IDs that
     * aren't of a goal or dimension of the site stay the same, eg the ecommerce goals 0 and -1.
     */
    public const GOAL = 'goal';
    public const CUSTOM_DIMENSION = 'custom_dimensions';

    /**
     * Columns whose meaning is the same in every Matomo table, so definitions don't need to repeat them.
     */
    private const CONVENTIONAL_REFERENCES = [
        'idsite' => self::SITE,
        'idvisit' => self::VISIT,
        'idlink_va' => self::LINK_VISIT_ACTION,
        'idaction' => self::ACTION,
        'idgoal' => self::GOAL,
        'idcustomdimension' => self::CUSTOM_DIMENSION,
    ];

    private const CONVENTIONAL_COPIES = [
        'idvisitor', // random visitor ID, the same on any Matomo
    ];

    /**
     * Columns matching this pattern likely hold an ID and must be accounted for by the definition, eg idform,
     * heatmap_ids or repeat_source_idsitehsr.
     */
    private const ID_LIKE_COLUMN = '/^id|_id|idsites?$/i';

    /**
     * @var string
     */
    private $table;

    /**
     * @var string|null
     */
    private $parentTable;

    /**
     * @var string|null
     */
    private $parentColumn;

    /**
     * @var string|null
     */
    private $autoIncrementColumn;

    /**
     * @var string|null
     */
    private $uniqueKeyColumn;

    /**
     * @var string|null
     */
    private $siteLinkTable;

    /**
     * @var string|null
     */
    private $timeColumn;

    /**
     * @var string|null
     */
    private $severalSitesCondition;

    /**
     * @var (callable(int): string[])|null
     */
    private $getSiteKeys;

    /**
     * @var string|null
     */
    private $dateColumn;

    /**
     * @var string|null
     */
    private $uniqueValueColumn;

    /**
     * @var (callable(): (int|string))|null
     */
    private $generateUniqueKey;

    /**
     * @var array<string, string>
     */
    private $references = [];

    /**
     * @var array<string, (callable(array<string, mixed>): bool)|null>
     */
    private $ownTableReferences = [];

    /**
     * @var string[]
     */
    private $copiedColumns = [];

    /**
     * @var array<string, callable(mixed, array<string, mixed>, IdResolver): mixed>
     */
    private $transforms = [];

    /**
     * @var string[]
     */
    private $matchColumns = [];

    /**
     * @var string[]
     */
    private $identityColumns = [];

    /**
     * @var bool
     */
    private $referencesReports = false;

    private function __construct(string $table, ?string $parentTable, ?string $parentColumn)
    {
        $this->table = $table;
        $this->parentTable = $parentTable;
        $this->parentColumn = $parentColumn;
        if ($parentTable !== null && $parentColumn !== null) {
            $this->references[$parentColumn] = $parentTable;
        }
    }

    /**
     * A table whose rows belong to a site, eg the heatmaps or forms configured for a site.
     *
     * @param string $table unprefixed table name
     */
    public static function forSite(string $table, string $siteColumn = 'idsite'): self
    {
        return new self($table, self::SITE, $siteColumn);
    }

    /**
     * A table whose rows belong to sites through a link table, eg alerts that are defined for several sites in a
     * table alert_site (idalert, idsite). The rows linked to the site are copied, the link table needs to be defined
     * as a site table after this one, so only the link to the migrated site is copied.
     *
     * @param string $table unprefixed table name
     * @param string $linkTable unprefixed name of the table linking the rows to sites with an idsite column
     * @param string $idColumn the column identifying a row, in both tables
     */
    public static function forSiteVia(string $table, string $linkTable, string $idColumn): self
    {
        $definition = new self($table, self::SITE, $idColumn);
        unset($definition->references[$idColumn]);
        $definition->siteLinkTable = $linkTable;
        return $definition;
    }

    /**
     * A table of raw data of a site that does not belong to visits. The rows are skipped with --skip-logs.
     * The table needs an auto increment column, and other tables cannot reference it.
     *
     * @param string $table unprefixed table name
     * @param string $timeColumn the column holding the time of a row in UTC, eg server_time
     */
    public static function forSiteLog(string $table, string $timeColumn, string $siteColumn = 'idsite'): self
    {
        $definition = new self($table, self::SITE, $siteColumn);
        $definition->timeColumn = $timeColumn;
        return $definition;
    }

    /**
     * A table of raw data that isn't stored per site, but per key a site is configured with, eg the keywords imported
     * from a search console are stored per property, which several sites can import. The rows of the keys of the
     * migrated site are copied, unless the target has a row with the same primary key already, eg imported for one of
     * its sites. Like visits, the rows are skipped with --skip-logs. The primary key of the table needs to include
     * the key column, and other tables cannot reference it.
     *
     * @param string $table unprefixed table name
     * @param string $keyColumn the column holding the key, eg the URL of a property
     * @param string $dateColumn the column holding the day of a row, rows of the day 0000-00-00 have no date
     * @param callable(int): string[] $getSiteKeys returns the keys a site of the source is configured with
     */
    public static function forSiteKeys(string $table, string $keyColumn, string $dateColumn, callable $getSiteKeys): self
    {
        $definition = new self($table, self::SITE, $keyColumn);
        unset($definition->references[$keyColumn]);
        $definition->dateColumn = $dateColumn;
        $definition->getSiteKeys = $getSiteKeys;
        return $definition;
    }

    /**
     * A table whose rows belong to a visit. These are migrated along with the visits, so they are skipped with --skip-logs.
     *
     * @param string $table unprefixed table name
     */
    public static function forVisit(string $table, string $visitColumn = 'idvisit'): self
    {
        return new self($table, self::VISIT, $visitColumn);
    }

    /**
     * A table whose rows belong to the rows of another table, eg the events of a session recording.
     *
     * @param string $table unprefixed table name
     * @param string $parentTable unprefixed name of a table that is migrated earlier and has an auto increment column,
     *                            or one of the core tables log_visit or log_link_visit_action
     * @param string $parentColumn the column of $table holding the parent ID
     */
    public static function childOf(string $table, string $parentTable, string $parentColumn): self
    {
        return new self($table, $parentTable, $parentColumn);
    }

    /**
     * A table holding rows that are shared by many sites, like log_action. A row is copied once the first migrated
     * row referencing it is copied. When match columns are given, a target row having the same values is reused
     * instead of copying the row.
     *
     * @param string $table unprefixed table name
     * @param string[] $matchColumns
     */
    public static function shared(string $table, string $autoIncrementColumn, array $matchColumns = []): self
    {
        $definition = new self($table, null, null);
        $definition->autoIncrementColumn = $autoIncrementColumn;
        $definition->matchColumns = $matchColumns;
        return $definition;
    }

    /**
     * The column of a shared table has a unique key, eg a name. When the target has a row with the value that doesn't
     * have the same values in the match columns, see shared(), the copy gets the value with a number appended, eg
     * "Conversion rate (2)", or the row having that value if it matches.
     */
    public function keepsUnique(string $column): self
    {
        $this->uniqueValueColumn = $column;
        return $this;
    }

    public function getUniqueValueColumn(): ?string
    {
        return $this->uniqueValueColumn;
    }

    /**
     * Rows of all sites or of several sites, eg reports defined for all websites, are copied as rows of the migrated
     * site too, as the site has data about them, eg the archives of the reports. This way they don't change the other
     * sites of the target Matomo. The SQL condition selects these rows, each ? in it stands for the ID of the migrated
     * site, eg "idsite = 0 OR FIND_IN_SET(?, idsites)". Only for tables belonging to the site, see forSite(), that have
     * an auto increment or unique key column and identity columns, see identifiedBy().
     *
     * These rows are copied after the rows of the site. A row is not copied when the target site has a row with the
     * same identity, see identifiedBy(), eg because it was migrated before: references to the row then point to that
     * row. If that row was copied from another row in this migration, eg a row of the site having the same name, the
     * row is skipped, as the target site can't have both.
     */
    public function includesRowsOfSeveralSites(string $condition): self
    {
        $this->severalSitesCondition = $condition;
        return $this;
    }

    public function getSeveralSitesCondition(): ?string
    {
        return $this->severalSitesCondition;
    }

    /**
     * The column is generated by the target DB, the source value is remembered so references to it can be translated.
     */
    public function autoIncrement(string $column): self
    {
        $this->autoIncrementColumn = $column;
        return $this;
    }

    /**
     * The column is a unique key that is not generated by the DB, eg a random string. The value is kept, unless the
     * target has a row with this value already, then the callback generates another one, or without callback the
     * next integer after the highest one. References to the column are translated like references to an auto
     * increment column.
     *
     * @param (callable(): (int|string))|null $generateKey
     */
    public function uniqueKey(string $column, ?callable $generateKey = null): self
    {
        $this->uniqueKeyColumn = $column;
        $this->generateUniqueKey = $generateKey;
        return $this;
    }

    /**
     * The column holds the ID of a row of another table, which is translated to the ID of the copied row.
     * A row referencing a row that wasn't copied is skipped. Empty values (NULL, 0, '') are kept.
     *
     * @param string $table unprefixed name of a plugin table defined earlier, or one of the core tables site,
     *                      log_visit, log_link_visit_action, log_action or segment
     */
    public function references(string $column, string $table): self
    {
        $this->references[$column] = $table;
        return $this;
    }

    /**
     * The column holds the ID of another row of the same table or of the row itself, eg the heatmap a heatmap was
     * copied from. As that row may be copied after the row referencing it, the column is empty when the row is
     * inserted, NULL if the column is nullable, otherwise '' or 0, and set once all rows of the table are copied. It
     * stays empty if the referenced row wasn't copied, eg because it was deleted. Only supported for tables belonging
     * to the site that have an auto increment or unique key column.
     *
     * @param (callable(array<string, mixed>): bool)|null $isReference tells whether the column of a source row holds
     *        such an ID, when it depends on other columns. The column is then also transformed with transforms(),
     *        whose value is kept for the rows for which this returns false.
     */
    public function referencesOwnTable(string $column, ?callable $isReference = null): self
    {
        $this->ownTableReferences[$column] = $isReference;
        return $this;
    }

    /**
     * @return array<string, (callable(array<string, mixed>): bool)|null> column => callback telling whether a row
     *         holds a reference in it, see referencesOwnTable()
     */
    public function getOwnTableReferences(): array
    {
        return $this->ownTableReferences;
    }

    /**
     * The column holds a JSON array of IDs of rows of another table, eg "[3,5]". Each ID is translated, IDs of rows
     * that weren't copied are removed.
     */
    public function referencesInJsonList(string $column, string $table): self
    {
        return $this->transforms($column, function ($value, array $row, IdResolver $resolver) use ($table) {
            if (!is_string($value) || $value === '') {
                return $value;
            }
            $ids = json_decode($value, true);
            if (!is_array($ids)) {
                return $value;
            }

            $targetIds = [];
            foreach ($ids as $id) {
                if (!is_int($id) && !is_string($id)) {
                    continue;
                }
                $targetId = $resolver->resolve($table, $id);
                if ($targetId !== null) {
                    $targetIds[] = is_string($id) ? (string) $targetId : (int) $targetId;
                }
            }
            return json_encode($targetIds);
        });
    }

    /**
     * The column holds the unique ID of a report as in the report metadata, eg "Goals_get_idGoal--1". The IDs of
     * goals, custom dimensions and plugin data it contains are translated, see PluginDefinition::identifiesReportsBy().
     * A row referencing a report about plugin data that wasn't copied is skipped.
     */
    public function referencesReport(string $column): self
    {
        $this->referencesReports = true;
        return $this->transforms($column, function ($value, array $row, IdResolver $resolver) {
            if (!is_string($value) || $value === '') {
                return $value;
            }
            $targetValue = $resolver->resolveReport($value);
            if ($targetValue === null) {
                throw new UnresolvableReferenceException(sprintf('The report %s is about data that was not migrated', $value));
            }
            return $targetValue;
        });
    }

    /**
     * The column holds a JSON array of unique IDs of reports, eg '["VisitsSummary_get","Goals_get_idGoal--1"]', see
     * referencesReport(). Reports about plugin data that wasn't copied are removed, a row left without report is
     * skipped.
     */
    public function referencesReportsInJsonList(string $column): self
    {
        $this->referencesReports = true;
        return $this->transforms($column, function ($value, array $row, IdResolver $resolver) {
            $reports = is_string($value) && $value !== '' ? json_decode($value, true) : null;
            if (!is_array($reports) || empty($reports)) {
                return $value;
            }

            $targetReports = [];
            foreach ($reports as $report) {
                $targetReport = is_string($report) ? $resolver->resolveReport($report) : $report;
                if ($targetReport !== null) {
                    $targetReports[] = $targetReport;
                }
            }
            if (empty($targetReports)) {
                throw new UnresolvableReferenceException('All reports are about data that was not migrated');
            }
            return json_encode($targetReports);
        });
    }

    /**
     * Whether the table references reports, which may be about the data of other plugins. Such tables are migrated
     * after the tables of the plugins that don't reference reports.
     */
    public function referencesReports(): bool
    {
        return $this->referencesReports;
    }

    /**
     * Identifies equivalent rows when copying data shared by several sites. A site-specific row takes precedence
     * over a shared row with the same values in these columns, and references use the site-specific copy.
     */
    public function identifiedBy(string ...$columns): self
    {
        $this->identityColumns = $columns;
        return $this;
    }

    /**
     * @return string[]
     */
    public function getIdentityColumns(): array
    {
        return $this->identityColumns;
    }

    /**
     * The columns look like IDs but hold values that stay valid on the target, eg an ID generated by the tracker.
     */
    public function copies(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->copiedColumns[] = $column;
        }
        return $this;
    }

    /**
     * The column is computed by the callback, eg for serialized or JSON values containing IDs.
     * The callback receives the source value, the source row and a resolver to look up target IDs,
     * and can throw an UnresolvableReferenceException to skip the row.
     *
     * @param callable(mixed, array<string, mixed>, IdResolver): mixed $transform
     */
    public function transforms(string $column, callable $transform): self
    {
        $this->transforms[$column] = $transform;
        return $this;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getParentTable(): ?string
    {
        return $this->parentTable;
    }

    public function getParentColumn(): ?string
    {
        return $this->parentColumn;
    }

    public function isShared(): bool
    {
        return $this->parentTable === null;
    }

    public function getAutoIncrementColumn(): ?string
    {
        return $this->autoIncrementColumn;
    }

    /**
     * The column identifying a row, which other rows can reference: the auto increment or unique key column.
     */
    public function getIdColumn(): ?string
    {
        return $this->autoIncrementColumn ?? $this->uniqueKeyColumn;
    }

    /**
     * @return int|string|null a new unique key, null if keys are integers that are generated by incrementing
     */
    public function generateUniqueKey()
    {
        return $this->generateUniqueKey ? ($this->generateUniqueKey)() : null;
    }

    /**
     * The column holding the time of a row of a table of raw site data, see forSiteLog()
     */
    public function getTimeColumn(): ?string
    {
        return $this->timeColumn;
    }

    /**
     * @return string[]|null the keys a site of the source is configured with, null if the table isn't stored per key,
     *                       see forSiteKeys()
     */
    public function getSiteKeys(int $idSite): ?array
    {
        return $this->getSiteKeys !== null ? ($this->getSiteKeys)($idSite) : null;
    }

    public function isStoredPerSiteKey(): bool
    {
        return $this->getSiteKeys !== null;
    }

    /**
     * The column holding the day of a row of a table stored per key, see forSiteKeys()
     */
    public function getDateColumn(): ?string
    {
        return $this->dateColumn;
    }

    public function getSiteLinkTable(): ?string
    {
        return $this->siteLinkTable;
    }

    public function getUniqueKeyColumn(): ?string
    {
        return $this->uniqueKeyColumn;
    }

    /**
     * @return string[]
     */
    public function getMatchColumns(): array
    {
        return $this->matchColumns;
    }

    /**
     * @return array<string, callable(mixed, array<string, mixed>, IdResolver): mixed>
     */
    public function getTransforms(): array
    {
        return $this->transforms;
    }

    /**
     * The references of the table, including the conventional ones of the given columns that the definition
     * doesn't classify otherwise.
     *
     * @param string[] $columns the columns the table has
     * @return array<string, string> column => referenced table
     */
    public function getReferences(array $columns): array
    {
        $references = [];
        foreach ($columns as $column) {
            if ($this->isClassifiedExplicitly($column)) {
                continue;
            }
            if (isset(self::CONVENTIONAL_REFERENCES[$column])) {
                $references[$column] = self::CONVENTIONAL_REFERENCES[$column];
            } elseif (strpos($column, 'idaction_') === 0) {
                $references[$column] = self::ACTION;
            }
        }

        return array_merge($references, $this->references);
    }

    /**
     * The columns that look like IDs but which the definition doesn't say how to migrate. Copying them as they are
     * could make them point to unrelated rows on the target, so a table having any can't be migrated.
     *
     * @param string[] $columns the columns the table has
     * @return string[]
     */
    public function getUnclassifiedColumns(array $columns): array
    {
        $references = $this->getReferences($columns);
        $unclassified = [];
        foreach ($columns as $column) {
            if (
                preg_match(self::ID_LIKE_COLUMN, $column)
                && !isset($references[$column])
                && !$this->isClassifiedExplicitly($column)
                && !in_array($column, self::CONVENTIONAL_COPIES, true)
            ) {
                $unclassified[] = $column;
            }
        }
        return $unclassified;
    }

    /**
     * @return string[]
     */
    public function getClassifiedColumns(): array
    {
        $columns = array_merge(
            array_keys($this->references),
            array_keys($this->ownTableReferences),
            $this->copiedColumns,
            array_keys($this->transforms),
            $this->matchColumns
        );
        if ($this->getIdColumn() !== null) {
            $columns[] = $this->getIdColumn();
        }
        if ($this->timeColumn !== null) {
            $columns[] = $this->timeColumn;
        }
        if ($this->getSiteKeys !== null) {
            $columns[] = (string) $this->parentColumn;
            $columns[] = (string) $this->dateColumn;
        }
        return array_values(array_unique($columns));
    }

    private function isClassifiedExplicitly(string $column): bool
    {
        return $column === $this->autoIncrementColumn
            || $column === $this->uniqueKeyColumn
            || isset($this->references[$column])
            || isset($this->transforms[$column])
            || array_key_exists($column, $this->ownTableReferences)
            || in_array($column, $this->copiedColumns, true);
    }
}
