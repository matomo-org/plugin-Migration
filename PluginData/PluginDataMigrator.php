<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\PluginData;

use Piwik\Archive\Chunk;
use Piwik\Common;
use Piwik\Config;
use Piwik\DataTable\Row;
use Piwik\Db;
use Piwik\DbHelper;
use Piwik\Plugin;
use Piwik\Plugins\Migration\Migrations\LogActionMigration;
use Piwik\Plugins\Migration\Migrations\Request;
use Piwik\Plugins\Migration\Migrations\SegmentsMigration;
use Piwik\Plugins\Migration\TargetDb;

/**
 * Copies the plugin tables described by plugin definitions, translating every reference to the target IDs.
 *
 * Tables belonging to the site are copied before the visits, tables belonging to visits are copied with each batch
 * of visits, and rows of shared tables are copied when a copied row references them.
 */
class PluginDataMigrator
{
    private const PHASE_SITE = 'site';
    private const PHASE_VISIT = 'visit';
    private const PHASE_SHARED = 'shared';
    /**
     * Raw data of the site that doesn't belong to visits, migrated after the visits, see TableDefinition::forSiteLog()
     */
    private const PHASE_SITE_LOG = 'site_log';

    private const PARENT_IDS_PER_QUERY = 1000;
    private const ROWS_PER_QUERY = 1000;

    /**
     * Rows of tables stored per key of the site hold large values, eg the keywords of a day, see
     * TableDefinition::forSiteKeys()
     */
    private const KEY_ROWS_PER_QUERY = 100;

    /**
     * Tables migrated by this plugin that have no plugin definition.
     */
    private const TABLES_MIGRATED_BY_CORE_MIGRATIONS = ['custom_dimensions'];

    /**
     * @var PluginDefinitions
     */
    private $definitions;

    /**
     * @var string[]
     */
    private $skippedPlugins;

    /**
     * @var array<string, array<int|string, int|string>>
     */
    private $sharedRowIds = [];

    /**
     * @var array<string, array<string, array<string, mixed>>> table => column => definition as in SHOW COLUMNS
     */
    private $sourceColumns = [];

    /**
     * @var array<string, array{migrated: int, skipped: int, existing: int}>
     */
    private $visitDataCounts = [];

    /**
     * @var array<string, array<string, true>>
     */
    private $uniqueKeysHandedOut = [];

    /**
     * @var array<string, int>
     */
    private $numChangedUniqueKeys = [];

    /**
     * The references to rows of the same table of the rows copied, which are set once all rows are copied, see
     * TableDefinition::referencesOwnTable()
     *
     * @var array<int, array{id: int|string, column: string, sourceId: int|string}>
     */
    private $pendingOwnTableReferences = [];

    /**
     * @var int|null
     */
    private $targetIdSite;

    /**
     * @var int
     */
    private $numCopiedSegmentsOfAllWebsites = 0;

    /**
     * @var array<int|string> target IDs of the segments of all websites added to the target site
     */
    private $copiedSegmentsOfAllWebsites = [];

    /**
     * @var array<string, string> source segment hash => hash of its translated definition
     */
    private $segmentHashes = [];

    /**
     * @var PluginDefinition[]|null
     */
    private $definitionsToMigrate;

    /**
     * @var array<int, array{table: TableDefinition, phase: string|null, plugin: string}>|null
     */
    private $tablesToMigrate;

    /**
     * @param string[] $skippedPlugins plugins whose data is not migrated
     */
    public function __construct(PluginDefinitions $definitions, array $skippedPlugins = [])
    {
        $this->definitions = $definitions;
        $this->skippedPlugins = $skippedPlugins;
    }

    /**
     * The definitions of the plugins whose data is migrated: plugins that are activated on the source and not skipped.
     *
     * @return PluginDefinition[]
     */
    public function getDefinitionsToMigrate(): array
    {
        if ($this->definitionsToMigrate === null) {
            $pluginManager = Plugin\Manager::getInstance();
            $this->definitionsToMigrate = [];
            foreach ($this->definitions->getAll() as $definition) {
                $pluginName = $definition->getPluginName();
                if (
                    !in_array($pluginName, $this->skippedPlugins, true)
                    && $pluginManager->isPluginActivated($pluginName)
                    && $pluginManager->isPluginLoaded($pluginName)
                ) {
                    $this->definitionsToMigrate[] = $definition;
                }
            }
            // reports can be about the data of other plugins, which needs to be migrated before
            usort($this->definitionsToMigrate, function (PluginDefinition $a, PluginDefinition $b) {
                return (int) $this->referencesReports($a) <=> (int) $this->referencesReports($b);
            });
        }
        return $this->definitionsToMigrate;
    }

    private function referencesReports(PluginDefinition $definition): bool
    {
        foreach ($definition->getTables() as $tableDefinition) {
            if ($tableDefinition->referencesReports()) {
                return true;
            }
        }
        return false;
    }

    /**
     * The report parameters holding an ID, also of the plugins whose data is not migrated, as their reports are then
     * about data that doesn't exist on the target.
     *
     * @return array<string, string> report parameter => table
     */
    public function getReportParameters(): array
    {
        $parameters = ['idGoal' => TableDefinition::GOAL, 'idDimension' => TableDefinition::CUSTOM_DIMENSION];
        foreach ($this->definitions->getAll() as $definition) {
            $parameters = array_merge($parameters, $definition->getReportParameters());
        }
        return $parameters;
    }

    /**
     * Segments of all websites are not migrated with the site. When migrated data uses one, eg a scheduled report, it
     * is copied as a segment of the target site, so it doesn't change the segments and the archiving of other sites
     * of the target Matomo. A segment the target has already for all websites or for the site is used instead.
     *
     * @param int|string $sourceId
     * @return int|string|null the target ID, null if the segment is not one of all websites
     */
    public function copySegmentOfAllWebsites($sourceId, IdMaps $maps, TargetDb $targetDb)
    {
        $segment = Db::fetchRow('SELECT * FROM ' . $this->quoteSourceTable('segment') . ' WHERE idsegment = ? AND enable_only_idsite = 0', [$sourceId]);
        if (empty($segment) || $this->targetIdSite === null) {
            return null;
        }
        [$targetId, $isAdded] = SegmentsMigration::copyToSite($segment, $this->targetIdSite, $maps, $targetDb, [0, $this->targetIdSite], $this->getSegmentTables());
        $maps->set(TableDefinition::SEGMENT, $sourceId, $targetId);
        if ($isAdded) {
            $this->numCopiedSegmentsOfAllWebsites++;
            $this->copiedSegmentsOfAllWebsites[] = $targetId;
        }
        return $targetId;
    }

    /**
     * Segments selecting plugin data by ID were copied before the plugin data, see
     * PluginDefinition::identifiesSegmentsBy(). Now that the plugin data of the site is migrated, the segments the
     * migration added are changed to select the rows by their target ID. Segments the target had already are kept,
     * and an eligible segment with the translated definition is used instead of a duplicate copy.
     *
     * @return int the number of segments changed
     */
    public function pointSegmentsToTargetIds(Request $request, TargetDb $targetDb): int
    {
        $segmentTables = $this->getSegmentTables();
        if (empty($segmentTables)) {
            return 0;
        }

        // like SegmentsMigration and copySegmentOfAllWebsites() reuse segments
        $targetIdSite = (int) $request->targetIdSite;
        $copiedSegments = array_fill_keys($request->copiedSegmentIds, [$targetIdSite]);
        foreach ($this->copiedSegmentsOfAllWebsites as $idSegment) {
            $copiedSegments[$idSegment] = [0, $targetIdSite];
        }

        $segmentTable = $targetDb->quoteTable('segment');
        $numChanged = 0;
        // Translate every copy before comparing definitions: a translated ID can equal another copy's source ID.
        foreach (array_keys($copiedSegments) as $idSegment) {
            // the copies belong to the target site, in a dry run nothing was inserted
            $segment = $targetDb->fetchRow("SELECT * FROM $segmentTable WHERE idsegment = ? AND enable_only_idsite = ?", [$idSegment, $targetIdSite]);
            if (!is_array($segment) || !is_string($segment['definition'] ?? null)) {
                continue;
            }
            $definition = SegmentsMigration::pointPluginDataToTargetIds($segment['definition'], $request->idMaps, $segmentTables);
            if ($definition === $segment['definition']) {
                continue;
            }
            $numChanged++;

            $sourceHash = is_string($segment['hash'] ?? null) ? $segment['hash'] : md5(urldecode($segment['definition']));
            $targetHash = md5(urldecode($definition));
            $this->segmentHashes[$sourceHash] = $targetHash;

            $columns = ['definition' => $definition];
            if (array_key_exists('hash', $segment)) {
                // like Matomo creates it, the archives of the segment are found by it
                $columns['hash'] = $targetHash;
            }
            $targetDb->update('segment', $columns, ['idsegment' => $idSegment]);
        }

        // Reuse was deferred even if an ID ultimately stays the same, so compare those copies too.
        $pendingSegments = array_fill_keys(array_keys($copiedSegments), true);
        foreach ($copiedSegments as $idSegment => $sitesToReuse) {
            unset($pendingSegments[$idSegment]);
            $segment = $targetDb->fetchRow("SELECT * FROM $segmentTable WHERE idsegment = ? AND enable_only_idsite = ?", [$idSegment, $targetIdSite]);
            if (
                !is_array($segment) || !is_string($segment['definition'] ?? null)
                || !SegmentsMigration::hasPluginDataIds($segment['definition'], $segmentTables)
            ) {
                continue;
            }
            // A copy of a site segment must not merge into a later all-websites copy which could itself be
            // replaced by a global target segment. Reuse only existing segments and copies already settled.
            $pendingIds = array_map('intval', array_keys($pendingSegments));
            $existingId = $targetDb->fetchOne(
                "SELECT idsegment FROM $segmentTable WHERE enable_only_idsite IN (" . Common::getSqlStringFieldsArray($sitesToReuse) . ')'
                . ' AND `definition` = ? AND deleted = ? AND idsegment <> ?'
                . ($pendingIds ? ' AND idsegment NOT IN (' . Common::getSqlStringFieldsArray($pendingIds) . ')' : '')
                . ' ORDER BY enable_only_idsite DESC, idsegment LIMIT 1',
                array_merge($sitesToReuse, [$segment['definition'], $segment['deleted'], $idSegment], $pendingIds)
            );
            if ($existingId) {
                $this->replaceSegment($idSegment, $this->toId($existingId), $request->idMaps, $targetDb);
            }
        }
        return $numChanged;
    }

    /**
     * The reporting pages identified by the ID of a goal, custom dimension or plugin data, also of the plugins whose
     * data is not migrated, as their pages then don't exist on the target, see
     * PluginDefinition::identifiesSubcategoriesBy()
     *
     * @return array<string, string> category => table
     */
    public function getSubcategoryTables(): array
    {
        $tables = [
            'Goals_Goals' => TableDefinition::GOAL,
            'General_Visitors' => TableDefinition::CUSTOM_DIMENSION,
            'General_Actions' => TableDefinition::CUSTOM_DIMENSION,
        ];
        foreach ($this->definitions->getAll() as $definition) {
            $tables = array_merge($tables, $definition->getSubcategoryTables());
        }
        return $tables;
    }

    /**
     * The segments selecting plugin data by ID of the plugins whose data is migrated, see
     * PluginDefinition::identifiesSegmentsBy()
     *
     * @return array<string, string> segment => table
     */
    public function getSegmentTables(): array
    {
        $segmentTables = [];
        foreach ($this->getDefinitionsToMigrate() as $definition) {
            $segmentTables = array_merge($segmentTables, $definition->getSegmentTables());
        }
        return $segmentTables;
    }

    /**
     * Removes a segment the migration added and points what references it to the segment the target has already
     *
     * @param int|string $idSegment
     * @param int|string $existingIdSegment
     */
    private function replaceSegment($idSegment, $existingIdSegment, IdMaps $maps, TargetDb $targetDb): void
    {
        foreach ($maps->getAll(TableDefinition::SEGMENT) as $sourceId => $targetId) {
            if ($targetId == $idSegment) {
                $maps->set(TableDefinition::SEGMENT, $sourceId, $existingIdSegment);
            }
        }
        // the plugin data of the site is migrated, the copy is only referenced by rows this migration added
        foreach ($this->getTablesWithPhase() as ['table' => $tableDefinition, 'phase' => $phase]) {
            if ($phase !== self::PHASE_SITE) {
                continue;
            }
            foreach ($tableDefinition->getReferences($this->getSourceColumns($tableDefinition->getTable())) as $column => $referencedTable) {
                if ($referencedTable === TableDefinition::SEGMENT) {
                    $targetDb->update($tableDefinition->getTable(), [$column => $existingIdSegment], [$column => $idSegment]);
                }
            }
        }
        $targetDb->delete('segment', ['idsegment' => $idSegment]);
    }

    public function getNumCopiedSegmentsOfAllWebsites(): int
    {
        return $this->numCopiedSegmentsOfAllWebsites;
    }

    /**
     * @return string[] the names of the skipped plugins that have no definition, to report probable typos
     */
    public function getUnknownSkippedPlugins(): array
    {
        $unknown = [];
        foreach ($this->skippedPlugins as $pluginName) {
            if ($this->definitions->get($pluginName) === null) {
                $unknown[] = $pluginName;
            }
        }
        return $unknown;
    }

    /**
     * Checks the definitions are complete for the tables the source has and that the target has the same tables.
     *
     * @return string[] errors
     */
    public function validate(TargetDb $targetDb, bool $withVisitData): array
    {
        $errors = [];
        foreach ($this->getUnknownSkippedPlugins() as $pluginName) {
            $errors[] = sprintf('There is no plugin data definition for the plugin "%s" given in --skip-plugin-data. Plugins having one: %s', $pluginName, implode(', ', $this->getPluginNames()) ?: '(none)');
        }

        $phases = $this->getPhases();
        foreach ($this->getTablesWithPhase() as ['table' => $tableDefinition, 'phase' => $phase, 'plugin' => $pluginName]) {
            $table = $tableDefinition->getTable();
            if ($phase === null) {
                $errors[] = sprintf('The parent table "%s" of the table "%s" of plugin %s is not a table migrated before that has an auto increment or unique key column.', (string) $tableDefinition->getParentTable(), $table, $pluginName);
                continue;
            }
            if ($this->isRawData($phase) && !$withVisitData) {
                continue;
            }
            if ($phase === self::PHASE_SITE_LOG && $tableDefinition->getAutoIncrementColumn() === null && !$tableDefinition->isStoredPerSiteKey()) {
                $errors[] = sprintf('The table "%s" of plugin %s holds raw data of the site, it needs an auto increment column.', $table, $pluginName);
                continue;
            }

            if (!$this->doesSourceTableExist($table)) {
                $errors[] = sprintf('The table "%s" of plugin %s does not exist in the source DB. Please make sure the plugin is installed correctly.', Common::prefixTable($table), $pluginName);
                continue;
            }
            $targetTable = $targetDb->prefixTable($table);
            if (!$targetDb->doesTableExist($targetTable)) {
                $errors[] = sprintf(
                    'The table "%s" of plugin %s does not exist in the target DB. Please install and activate the plugin on the target Matomo, or use --skip-plugin-data=%s to not migrate its data.',
                    $targetTable,
                    $pluginName,
                    $pluginName
                );
                continue;
            }

            $errors = array_merge($errors, $this->validateTableDefinition($tableDefinition, $pluginName, $phase));
        }

        foreach ($this->getDefinitionsToMigrate() as $definition) {
            foreach ($definition->getSiteStepTables() as $table) {
                if (!$this->doesSourceTableExist($table)) {
                    $errors[] = sprintf('The table "%s" of plugin %s does not exist in the source DB. Please make sure the plugin is installed correctly.', Common::prefixTable($table), $definition->getPluginName());
                } elseif (!$targetDb->doesTableExist($targetDb->prefixTable($table))) {
                    $errors[] = sprintf(
                        'The table "%s" of plugin %s does not exist in the target DB. Please install and activate the plugin on the target Matomo, or use --skip-plugin-data=%s to not migrate its data.',
                        $targetDb->prefixTable($table),
                        $definition->getPluginName(),
                        $definition->getPluginName()
                    );
                }
            }
            foreach ($definition->getArchiveRecordPatterns() as $pattern => $table) {
                if (@preg_match($pattern, '') === false) {
                    $errors[] = sprintf('The archive record pattern "%s" of plugin %s is not a valid regular expression.', $pattern, $definition->getPluginName());
                } elseif (($phases[$table] ?? null) !== self::PHASE_SITE || $this->getTableDefinition($table)?->getIdColumn() === null) {
                    $errors[] = sprintf('The archive record pattern "%s" of plugin %s must refer to a site table having an auto increment or unique key column, "%s" is not one.', $pattern, $definition->getPluginName(), $table);
                }
            }
            foreach ($definition->getArchiveRowIds() as ['recordPattern' => $recordPattern, 'table' => $table, 'column' => $column, 'valuePattern' => $valuePattern]) {
                if (@preg_match($recordPattern, '') === false || @preg_match($valuePattern, '') === false) {
                    $errors[] = sprintf('The archive record pattern "%s" or value pattern "%s" of plugin %s is not a valid regular expression.', $recordPattern, $valuePattern, $definition->getPluginName());
                } elseif (($phases[$table] ?? null) !== self::PHASE_SITE || $this->getTableDefinition($table)?->getIdColumn() === null) {
                    $errors[] = sprintf('The IDs in the column "%s" of report rows of plugin %s must refer to a site table having an auto increment or unique key column, "%s" is not one.', $column, $definition->getPluginName(), $table);
                }
            }
            foreach ($definition->getArchiveColumnNameIds() as ['recordPattern' => $recordPattern, 'table' => $table, 'columnPattern' => $columnPattern]) {
                if (@preg_match($recordPattern, '') === false || @preg_match($columnPattern, '') === false) {
                    $errors[] = sprintf('The archive record pattern "%s" or column pattern "%s" of plugin %s is not a valid regular expression.', $recordPattern, $columnPattern, $definition->getPluginName());
                } elseif (!in_array($phases[$table] ?? null, [self::PHASE_SITE, self::PHASE_SHARED], true) || $this->getTableDefinition($table)?->getIdColumn() === null) {
                    $errors[] = sprintf('The IDs in the column names of report rows of plugin %s must refer to a site or shared table having an auto increment or unique key column, "%s" is not one.', $definition->getPluginName(), $table);
                }
            }
            foreach ($definition->getSegmentTables() as $segment => $table) {
                if (($phases[$table] ?? null) !== self::PHASE_SITE || $this->getTableDefinition($table)?->getIdColumn() === null) {
                    $errors[] = sprintf('The segment "%s" of plugin %s must refer to a site table having an auto increment or unique key column, "%s" is not one.', $segment, $definition->getPluginName(), $table);
                }
            }
            foreach ($definition->getSubcategoryTables() as $category => $table) {
                if (($phases[$table] ?? null) !== self::PHASE_SITE || $this->getTableDefinition($table)?->getIdColumn() === null) {
                    $errors[] = sprintf('The reporting pages of the category "%s" of plugin %s must refer to a site table having an auto increment or unique key column, "%s" is not one.', $category, $definition->getPluginName(), $table);
                }
            }
        }

        return $errors;
    }

    /**
     * @return string[] errors
     */
    private function validateTableDefinition(TableDefinition $tableDefinition, string $pluginName, string $phase): array
    {
        $table = $tableDefinition->getTable();
        $columns = $this->getSourceColumns($table);
        $errors = [];

        $missingColumns = array_diff(array_merge($tableDefinition->getClassifiedColumns(), $tableDefinition->getIdentityColumns()), $columns);
        if (!empty($missingColumns)) {
            $errors[] = sprintf('The table "%s" of plugin %s has no column %s, the definition of its data does not match the installed plugin version.', $table, $pluginName, implode(', ', $missingColumns));
        }

        if ($tableDefinition->isStoredPerSiteKey() && !in_array($tableDefinition->getParentColumn(), $this->getSourcePrimaryKey($table), true)) {
            $errors[] = sprintf('The table "%s" of plugin %s is stored per key of the site, its primary key needs to include the column "%s".', $table, $pluginName, (string) $tableDefinition->getParentColumn());
        }

        $unclassifiedColumns = $tableDefinition->getUnclassifiedColumns($columns);
        if (!empty($unclassifiedColumns)) {
            $errors[] = sprintf(
                'The definition of the table "%s" of plugin %s does not say how to migrate the column(s) %s. They may hold IDs that are different on the target, so the table cannot be migrated safely. '
                . 'The definition probably needs to be updated for the installed plugin version.',
                $table,
                $pluginName,
                implode(', ', $unclassifiedColumns)
            );
        }

        if (!empty($tableDefinition->getOwnTableReferences()) && ($phase !== self::PHASE_SITE || $tableDefinition->getIdColumn() === null)) {
            $errors[] = sprintf('The table "%s" of plugin %s references its own rows, which is only supported for tables belonging to the site that have an auto increment or unique key column.', $table, $pluginName);
        }
        if ($tableDefinition->getUniqueValueColumn() !== null && (!$tableDefinition->isShared() || !in_array($tableDefinition->getUniqueValueColumn(), $tableDefinition->getMatchColumns(), true))) {
            $errors[] = sprintf('The table "%s" of plugin %s keeps the column "%s" unique, which is only supported for shared tables matching rows by this column.', $table, $pluginName, $tableDefinition->getUniqueValueColumn());
        }
        if (
            $tableDefinition->getSeveralSitesCondition() !== null
            && ($phase !== self::PHASE_SITE || $tableDefinition->getParentTable() !== TableDefinition::SITE || $tableDefinition->getSiteLinkTable() !== null
                || $tableDefinition->getIdColumn() === null || empty($tableDefinition->getIdentityColumns()))
        ) {
            $errors[] = sprintf(
                'The table "%s" of plugin %s includes rows of several sites, which is only supported for tables belonging to the site through a site column that have an auto increment or unique key column and identity columns.',
                $table,
                $pluginName
            );
        }

        $phasesOfTablesBefore = $this->getPhases($table);
        foreach ($tableDefinition->getReferences($columns) as $column => $referencedTable) {
            if ($referencedTable === $table || !$this->canReference($phase, $referencedTable, $phasesOfTablesBefore)) {
                $errors[] = sprintf('The column "%s" of the table "%s" of plugin %s references the table "%s", which is not migrated before it.', $column, $table, $pluginName, $referencedTable);
            }
        }

        return $errors;
    }

    /**
     * @param array<string, string> $phases
     */
    private function canReference(string $phase, string $referencedTable, array $phases): bool
    {
        $siteDataMigratedBefore = [TableDefinition::SITE, TableDefinition::SEGMENT, TableDefinition::GOAL, TableDefinition::CUSTOM_DIMENSION, TableDefinition::ACTION];
        if (in_array($referencedTable, $siteDataMigratedBefore, true)) {
            return $phase !== self::PHASE_SHARED || $referencedTable === TableDefinition::ACTION;
        }
        if (in_array($referencedTable, [TableDefinition::VISIT, TableDefinition::LINK_VISIT_ACTION], true)) {
            return $phase === self::PHASE_VISIT;
        }

        $referencedPhase = $phases[$referencedTable] ?? null;
        if ($referencedPhase === self::PHASE_SHARED) {
            return true;
        }
        if ($phase === self::PHASE_SHARED) {
            return false;
        }
        return $referencedPhase === self::PHASE_SITE || ($referencedPhase === self::PHASE_VISIT && $phase === self::PHASE_VISIT);
    }

    /**
     * @param array<string, TableDefinition> $tablesBefore the tables defined before having a phase
     * @param array<string, string> $phases phase of each of these tables
     */
    private function getPhase(TableDefinition $tableDefinition, array $tablesBefore, array $phases): ?string
    {
        $parentTable = $tableDefinition->getParentTable();
        if ($parentTable === null) {
            return self::PHASE_SHARED;
        }
        if ($parentTable === TableDefinition::SITE) {
            return $tableDefinition->getTimeColumn() !== null || $tableDefinition->isStoredPerSiteKey() ? self::PHASE_SITE_LOG : self::PHASE_SITE;
        }
        if ($parentTable === TableDefinition::VISIT || $parentTable === TableDefinition::LINK_VISIT_ACTION) {
            return self::PHASE_VISIT;
        }
        if (
            !isset($tablesBefore[$parentTable])
            || $tablesBefore[$parentTable]->getIdColumn() === null
            || $phases[$parentTable] === self::PHASE_SHARED
            || $phases[$parentTable] === self::PHASE_SITE_LOG
        ) {
            return null;
        }
        return $phases[$parentTable];
    }

    /**
     * @return array<int, array{table: TableDefinition, phase: string|null, plugin: string}> the tables to migrate
     *         in migration order, with their phase or null if they have no valid parent
     */
    private function getTablesWithPhase(): array
    {
        if ($this->tablesToMigrate === null) {
            $this->tablesToMigrate = [];
            $tablesBefore = [];
            $phases = [];
            foreach ($this->getDefinitionsToMigrate() as $definition) {
                foreach ($definition->getTables() as $tableDefinition) {
                    $phase = $this->getPhase($tableDefinition, $tablesBefore, $phases);
                    if ($phase !== null) {
                        $tablesBefore[$tableDefinition->getTable()] = $tableDefinition;
                        $phases[$tableDefinition->getTable()] = $phase;
                    }
                    $this->tablesToMigrate[] = ['table' => $tableDefinition, 'phase' => $phase, 'plugin' => $definition->getPluginName()];
                }
            }
        }
        return $this->tablesToMigrate;
    }

    /**
     * @param string|null $untilTable only return the phases of the tables migrated before this one
     * @return array<string, string> table => phase
     */
    private function getPhases(?string $untilTable = null): array
    {
        $phases = [];
        foreach ($this->getTablesWithPhase() as ['table' => $tableDefinition, 'phase' => $phase]) {
            if ($tableDefinition->getTable() === $untilTable) {
                break;
            }
            if ($phase !== null) {
                $phases[$tableDefinition->getTable()] = $phase;
            }
        }
        return $phases;
    }

    public function hasVisitData(): bool
    {
        return !empty($this->getTablesOfPhase(self::PHASE_VISIT));
    }

    /**
     * Copies the tables belonging to the site.
     *
     * @return array<string, array{migrated: int, skipped: int, existing: int, duplicate: int}> number of migrated and skipped rows per table
     */
    public function migrateSiteData(Request $request, TargetDb $targetDb): array
    {
        $maps = $request->idMaps;
        $resolver = new IdResolver($maps, $this, new LogActionMigration(), $targetDb);
        $this->targetIdSite = (int) $request->targetIdSite;

        $counts = [];
        foreach ($this->getTablesOfPhase(self::PHASE_SITE) as $tableDefinition) {
            $counts[$tableDefinition->getTable()] = $this->copyRows($tableDefinition, $maps, $resolver, $targetDb);
        }
        return $counts;
    }

    /**
     * Copies the rows belonging to a batch of visits that has just been copied.
     *
     * @param array<int|string, int|string> $visitIdMap source idvisit => target idvisit
     * @param array<int|string, int|string> $linkVisitActionIdMap source idlink_va => target idlink_va
     */
    public function migrateVisitData(Request $request, TargetDb $targetDb, array $visitIdMap, array $linkVisitActionIdMap, LogActionMigration $logActionMigration): void
    {
        $maps = new IdMaps($request->idMaps);
        $maps->setAll(TableDefinition::VISIT, $visitIdMap);
        $maps->setAll(TableDefinition::LINK_VISIT_ACTION, $linkVisitActionIdMap);
        $resolver = new IdResolver($maps, $this, $logActionMigration, $targetDb);

        foreach ($this->getTablesOfPhase(self::PHASE_VISIT) as $tableDefinition) {
            $table = $tableDefinition->getTable();
            $counts = $this->copyRows($tableDefinition, $maps, $resolver, $targetDb);
            $this->visitDataCounts[$table] = [
                'migrated' => ($this->visitDataCounts[$table]['migrated'] ?? 0) + $counts['migrated'],
                'skipped' => ($this->visitDataCounts[$table]['skipped'] ?? 0) + $counts['skipped'],
                'existing' => 0,
            ];
        }
    }

    /**
     * @return array<string, int> per table, the number of rows whose unique key was taken on the target and changed
     */
    public function getNumChangedUniqueKeys(): array
    {
        return $this->numChangedUniqueKeys;
    }

    /**
     * @return array<string, array{migrated: int, skipped: int, existing: int}> number of migrated and skipped rows per table
     */
    public function getVisitDataCounts(): array
    {
        $counts = [];
        foreach ($this->getTablesOfPhase(self::PHASE_VISIT) as $tableDefinition) {
            $table = $tableDefinition->getTable();
            $counts[$table] = $this->visitDataCounts[$table] ?? ['migrated' => 0, 'skipped' => 0, 'existing' => 0];
        }
        return $counts;
    }

    /**
     * @return array{migrated: int, skipped: int, existing: int, duplicate: int}
     */
    private function copyRows(TableDefinition $tableDefinition, IdMaps $maps, IdResolver $resolver, TargetDb $targetDb): array
    {
        $table = $tableDefinition->getTable();
        $parentTable = (string) $tableDefinition->getParentTable();
        $parentColumn = (string) $tableDefinition->getParentColumn();
        $idColumn = $tableDefinition->getIdColumn();
        $columns = $this->getSourceColumns($table);

        $counts = ['migrated' => 0, 'skipped' => 0, 'existing' => 0, 'duplicate' => 0];
        $parentIds = array_keys($maps->getAll($parentTable));
        $siteLinkTable = $tableDefinition->getSiteLinkTable();
        if ($siteLinkTable !== null) {
            // the rows are those the link table links to the site
            $linkedRows = $this->fetchRows(
                sprintf('SELECT DISTINCT %s FROM %s WHERE `idsite` IN (%s)', $this->quoteIdentifier($parentColumn), $this->quoteSourceTable($siteLinkTable), Common::getSqlStringFieldsArray($parentIds)),
                $parentIds
            );
            $parentIds = array_map([$this, 'toId'], array_column($linkedRows, $parentColumn));
        }

        foreach (array_chunk($parentIds, self::PARENT_IDS_PER_QUERY) as $parentIdsChunk) {
            foreach ($this->fetchRowsOfParents($table, $parentColumn, $parentIdsChunk, $idColumn) as $row) {
                $counts[$this->copyRow($tableDefinition, $row, $columns, $maps, $resolver, $targetDb)]++;
            }
        }

        $severalSitesCondition = $tableDefinition->getSeveralSitesCondition();
        if ($severalSitesCondition !== null && $parentTable === TableDefinition::SITE && $siteLinkTable === null) {
            foreach ($parentIds as $idSite) {
                $orderBy = $idColumn !== null ? sprintf(' ORDER BY %s', $this->quoteIdentifier($idColumn)) : '';
                $sql = sprintf('SELECT * FROM %s WHERE %s <> ? AND (%s)%s', $this->quoteSourceTable($table), $this->quoteIdentifier($parentColumn), $severalSitesCondition, $orderBy);
                $bind = array_merge([$idSite], array_fill(0, substr_count($severalSitesCondition, '?'), $idSite));
                foreach ($this->fetchRows($sql, $bind) as $row) {
                    // copied as a row of the site
                    $row[$parentColumn] = $idSite;
                    $counts[$this->copyRow($tableDefinition, $row, $columns, $maps, $resolver, $targetDb, true)]++;
                }
            }
        }
        $this->setOwnTableReferences($tableDefinition, $maps, $targetDb);

        return $counts;
    }

    /**
     * Points the references to rows of the same table to the rows they were copied to, now that all rows are copied.
     */
    private function setOwnTableReferences(TableDefinition $tableDefinition, IdMaps $maps, TargetDb $targetDb): void
    {
        $table = $tableDefinition->getTable();
        foreach ($this->pendingOwnTableReferences as ['id' => $targetId, 'column' => $column, 'sourceId' => $sourceId]) {
            $targetReference = $maps->get($table, $sourceId);
            if ($targetReference !== null) {
                $targetDb->update($table, [$column => $targetReference], [(string) $tableDefinition->getIdColumn() => $targetId]);
            }
        }
        $this->pendingOwnTableReferences = [];
    }

    /**
     * @param array<string, mixed> $row
     * @param string[] $columns
     * @param bool $isOfSeveralSites whether the row is of several sites, see TableDefinition::includesRowsOfSeveralSites()
     * @return 'migrated'|'skipped'|'existing'|'duplicate'
     */
    private function copyRow(TableDefinition $tableDefinition, array $row, array $columns, IdMaps $maps, IdResolver $resolver, TargetDb $targetDb, bool $isOfSeveralSites = false): string
    {
        $table = $tableDefinition->getTable();
        $idColumn = $tableDefinition->getIdColumn();
        $autoIncrementColumn = $tableDefinition->getAutoIncrementColumn();
        $uniqueKeyColumn = $tableDefinition->getUniqueKeyColumn();

        if ($isOfSeveralSites && $idColumn !== null) {
            // before translating the other columns, which can copy rows of shared tables
            $identityColumns = array_merge([(string) $tableDefinition->getParentColumn()], $tableDefinition->getIdentityColumns());
            $identityRow = $this->transformRow($tableDefinition, $row, $columns, $resolver, $identityColumns);
            $existing = $identityRow !== null ? $this->findExistingRow($tableDefinition, $identityRow, $targetDb) : null;
            if ($existing !== null && in_array((string) $this->toId($existing[$idColumn]), array_map('strval', $maps->getAll($table)), true)) {
                // another row was copied to it, eg a row of the site with the same name
                return 'duplicate';
            }
        }

        $targetRow = $this->transformRow($tableDefinition, $row, $columns, $resolver);
        if ($targetRow === null) {
            return 'skipped';
        }

        if ($autoIncrementColumn !== null) {
            unset($targetRow[$autoIncrementColumn]);
        } elseif ($uniqueKeyColumn !== null) {
            $targetRow[$uniqueKeyColumn] = $this->getFreeUniqueKey($tableDefinition, $this->toId($row[$uniqueKeyColumn]), $targetDb);
        }
        $ownTableReferences = [];
        foreach ($tableDefinition->getOwnTableReferences() as $column => $isReference) {
            $value = $row[$column] ?? null;
            if ($value !== null && $value !== '' && $value !== '0' && $value !== 0 && ($isReference === null || $isReference($row))) {
                $ownTableReferences[$column] = $this->toId($value);
                $targetRow[$column] = $this->getEmptyValue($table, $column);
            }
        }

        $targetId = $targetDb->insert($table, $targetRow);

        if ($autoIncrementColumn !== null) {
            $maps->set($table, $this->toId($row[$autoIncrementColumn]), $targetId);
        } elseif ($uniqueKeyColumn !== null) {
            $targetId = $this->toId($targetRow[$uniqueKeyColumn]);
            $maps->set($table, $this->toId($row[$uniqueKeyColumn]), $targetId);
        }
        foreach ($ownTableReferences as $column => $sourceId) {
            $this->pendingOwnTableReferences[] = ['id' => $targetId, 'column' => $column, 'sourceId' => $sourceId];
        }
        return 'migrated';
    }

    /**
     * Copies the raw data of the site that does not belong to visits, including rows added while migrating.
     *
     * @return array<string, array{migrated: int, skipped: int, existing: int, duplicate: int, present: int}> by table
     */
    public function migrateSiteLogData(Request $request, TargetDb $targetDb, LogActionMigration $logActionMigration): array
    {
        $counts = [];
        foreach ($this->getTablesOfPhase(self::PHASE_SITE_LOG) as $tableDefinition) {
            $table = $tableDefinition->getTable();
            if ($tableDefinition->isStoredPerSiteKey()) {
                $counts[$table] = $this->copyRowsOfSiteKeys($tableDefinition, $request, $targetDb, $logActionMigration);
                continue;
            }
            $sourceTable = $this->quoteSourceTable($table);
            $idColumn = (string) $tableDefinition->getAutoIncrementColumn();
            $where = sprintf('%s = ?', $this->quoteIdentifier((string) $tableDefinition->getParentColumn()));
            $bind = [$request->sourceIdSite];
            $columns = $this->getSourceColumns($table);
            $tableCounts = ['migrated' => 0, 'skipped' => 0, 'existing' => 0, 'duplicate' => 0, 'present' => 0];

            $range = $this->fetchRows(sprintf('SELECT MIN(%s) AS first, MAX(%s) AS last FROM %s WHERE %s', $this->quoteIdentifier($idColumn), $this->quoteIdentifier($idColumn), $sourceTable, $where), $bind);
            $first = $range[0]['first'] ?? null;
            if (!is_numeric($first)) {
                $counts[$table] = $tableCounts;
                continue;
            }
            // like the visits, the rows are read in the order of the primary key, see LogMigration
            $sql = sprintf('SELECT * FROM %s FORCE INDEX (PRIMARY) WHERE %s > ? AND %s', $sourceTable, $this->quoteIdentifier($idColumn), $where)
                . sprintf(' ORDER BY %s ASC LIMIT %d', $this->quoteIdentifier($idColumn), self::ROWS_PER_QUERY);
            $lastId = (int) $first - 1;
            do {
                $rows = $this->fetchRows($sql, array_merge([$lastId], $bind));
                // the IDs of these rows aren't referenced, so they are forgotten after each batch
                $maps = new IdMaps($request->idMaps);
                $resolver = new IdResolver($maps, $this, $logActionMigration, $targetDb);
                foreach ($rows as $row) {
                    $tableCounts[$this->copyRow($tableDefinition, $row, $columns, $maps, $resolver, $targetDb)]++;
                    $lastId = (int) $this->toId($row[$idColumn]);
                }
            } while (count($rows) === self::ROWS_PER_QUERY);
            $counts[$table] = $tableCounts;
        }
        return $counts;
    }

    /**
     * Copies the rows of a table stored per key of the site, see TableDefinition::forSiteKeys()
     *
     * @return array{migrated: int, skipped: int, existing: int, duplicate: int, present: int}
     */
    private function copyRowsOfSiteKeys(TableDefinition $tableDefinition, Request $request, TargetDb $targetDb, LogActionMigration $logActionMigration): array
    {
        $table = $tableDefinition->getTable();
        $keyColumn = (string) $tableDefinition->getParentColumn();
        $columns = $this->getSourceColumns($table);
        $primaryKey = $this->getSourcePrimaryKey($table);
        $counts = ['migrated' => 0, 'skipped' => 0, 'existing' => 0, 'duplicate' => 0, 'present' => 0];

        $where = sprintf('%s = ?', $this->quoteIdentifier($keyColumn));
        // the rows of a key are read in the order of the primary key
        $pageColumns = array_values(array_diff($primaryKey, [$keyColumn]));
        $pageColumnList = implode(', ', array_map(function ($column) {
            return $this->quoteIdentifier($column);
        }, $pageColumns));
        $sourceTable = $this->quoteSourceTable($table);

        foreach (array_unique($tableDefinition->getSiteKeys($request->sourceIdSite) ?? []) as $key) {
            $lastRow = null;
            do {
                $sql = sprintf('SELECT * FROM %s WHERE %s', $sourceTable, $where);
                $bind = [$key];
                if ($lastRow !== null) {
                    $sql .= sprintf(' AND (%s) > (%s)', $pageColumnList, Common::getSqlStringFieldsArray($pageColumns));
                    foreach ($pageColumns as $column) {
                        $value = $lastRow[$column] ?? null;
                        $bind[] = is_int($value) || is_string($value) ? $value : null;
                    }
                }
                $sql .= ($pageColumnList !== '' ? ' ORDER BY ' . $pageColumnList : '') . ' LIMIT ' . self::KEY_ROWS_PER_QUERY;
                $rows = $this->fetchRows($sql, $bind);
                $resolver = new IdResolver(new IdMaps($request->idMaps), $this, $logActionMigration, $targetDb);
                foreach ($rows as $row) {
                    $counts[$this->copyRowOfSiteKey($tableDefinition, $row, $columns, $primaryKey, $resolver, $targetDb)]++;
                    $lastRow = $row;
                }
            } while ($pageColumnList !== '' && count($rows) === self::KEY_ROWS_PER_QUERY);
        }
        return $counts;
    }

    /**
     * @param array<string, mixed> $row
     * @param string[] $columns
     * @param string[] $primaryKey
     * @return 'migrated'|'skipped'|'present'
     */
    private function copyRowOfSiteKey(TableDefinition $tableDefinition, array $row, array $columns, array $primaryKey, IdResolver $resolver, TargetDb $targetDb): string
    {
        $targetRow = $this->transformRow($tableDefinition, $row, $columns, $resolver);
        if ($targetRow === null) {
            return 'skipped';
        }

        // eg imported for one of the sites of the target
        $where = [];
        $bind = [];
        foreach ($primaryKey as $column) {
            $where[] = sprintf('%s = ?', $targetDb->quoteIdentifier($column));
            $bind[] = $targetRow[$column];
        }
        $sql = sprintf('SELECT 1 FROM %s WHERE %s LIMIT 1', $targetDb->quoteTable($tableDefinition->getTable()), implode(' AND ', $where));
        if ($targetDb->fetchRow($sql, $bind)) {
            return 'present';
        }

        $targetDb->insert($tableDefinition->getTable(), $targetRow);
        return 'migrated';
    }

    /**
     * The row already copied to the target site with the same parent and identity, see identifiedBy().
     *
     * @param array<string, mixed> $targetRow the row to insert, with the references translated
     * @return array<string, mixed>|null
     */
    private function findExistingRow(TableDefinition $tableDefinition, array $targetRow, TargetDb $targetDb): ?array
    {
        $identityColumns = $tableDefinition->getIdentityColumns();
        if (empty($identityColumns)) {
            return null;
        }

        $targetTable = $targetDb->quoteTable($tableDefinition->getTable());
        $where = [];
        $bind = [];
        $siteLinkTable = $tableDefinition->getSiteLinkTable();
        if ($siteLinkTable !== null) {
            // the rows of the site are the ones its link table links to it
            $idColumn = (string) $tableDefinition->getParentColumn();
            $where[] = sprintf('EXISTS (SELECT 1 FROM %s l WHERE l.%s = t.%s AND l.idsite = ?)', $targetDb->quoteTable($siteLinkTable), $targetDb->quoteIdentifier($idColumn), $targetDb->quoteIdentifier($idColumn));
            $bind[] = $this->targetIdSite;
        } else {
            $parentColumn = (string) $tableDefinition->getParentColumn();
            $where[] = sprintf('t.%s <=> ?', $targetDb->quoteIdentifier($parentColumn));
            $bind[] = $targetRow[$parentColumn] ?? null;
        }
        foreach ($identityColumns as $column) {
            $where[] = sprintf('t.%s <=> ?', $targetDb->quoteIdentifier($column));
            $bind[] = $targetRow[$column] ?? null;
        }

        /** @var array<string, mixed>|false $row */
        $row = $targetDb->fetchRow(sprintf('SELECT * FROM %s t WHERE %s LIMIT 1', $targetTable, implode(' AND ', $where)), $bind);
        return is_array($row) && !empty($row) ? $row : null;
    }

    /**
     * @param int|string $key
     * @return int|string the key if the target doesn't have it yet, otherwise a newly generated one
     */
    private function getFreeUniqueKey(TableDefinition $tableDefinition, $key, TargetDb $targetDb)
    {
        $table = $tableDefinition->getTable();
        $column = (string) $tableDefinition->getUniqueKeyColumn();
        $targetTable = $targetDb->quoteTable($table);
        $sql = sprintf('SELECT 1 FROM %s WHERE %s = ? LIMIT 1', $targetTable, $targetDb->quoteIdentifier($column));
        // in a dry run nothing is inserted, so the keys handed out are remembered to not hand them out twice
        $keysHandedOut = $this->uniqueKeysHandedOut[$table] ?? [];
        $sourceKey = $key;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            if (!isset($keysHandedOut[(string) $key]) && !$targetDb->fetchRow($sql, [$key])) {
                $this->uniqueKeysHandedOut[$table][(string) $key] = true;
                if ($key !== $sourceKey) {
                    $this->numChangedUniqueKeys[$table] = ($this->numChangedUniqueKeys[$table] ?? 0) + 1;
                }
                return $key;
            }
            $newKey = $tableDefinition->generateUniqueKey();
            if ($newKey === null) {
                $maxKey = $targetDb->fetchOne(sprintf('SELECT MAX(%s) FROM %s', $targetDb->quoteIdentifier($column), $targetTable));
                $newKey = max(array_merge([is_numeric($maxKey) ? (int) $maxKey : 0], array_map('intval', array_keys($keysHandedOut)))) + 1;
            }
            $key = $newKey;
        }
        throw new \Exception(sprintf('Could not find a free value for the column %s of the table %s on the target', $column, $tableDefinition->getTable()));
    }

    /**
     * Fetches the rows of a chunk of parents. The parents are grouped so a query returns at most ROWS_PER_QUERY rows,
     * a parent having more rows is paged through on its own, in the order of the index on the parent column. Paging
     * through the rows of several parents would make MySQL sort all their rows again for each page.
     *
     * @param array<int|string> $parentIds
     * @return \Generator<array<string, mixed>>
     */
    private function fetchRowsOfParents(string $table, string $parentColumn, array $parentIds, ?string $idColumn): \Generator
    {
        $sourceTable = $this->quoteSourceTable($table);
        $numRowsPerParent = $this->fetchRows(
            sprintf(
                'SELECT %s AS parent, COUNT(*) AS num FROM %s WHERE %s IN (%s) GROUP BY %s',
                $this->quoteIdentifier($parentColumn),
                $sourceTable,
                $this->quoteIdentifier($parentColumn),
                Common::getSqlStringFieldsArray($parentIds),
                $this->quoteIdentifier($parentColumn)
            ),
            $parentIds
        );
        $orderBy = implode(', ', array_map(function ($column) {
            return $this->quoteIdentifier($column);
        }, $idColumn !== null ? [$idColumn] : $this->getSourcePrimaryKey($table)));
        $orderBy = $orderBy === '' ? '' : ' ORDER BY ' . $orderBy;

        $group = [];
        $groupSize = 0;
        foreach ($numRowsPerParent as $parent) {
            $parentId = $this->toId($parent['parent']);
            $numRows = is_numeric($parent['num']) ? (int) $parent['num'] : 0;
            if ($numRows > self::ROWS_PER_QUERY) {
                yield from $this->fetchRowsOfParent($table, $parentColumn, $parentId, $idColumn, $orderBy);
                continue;
            }
            if ($groupSize + $numRows > self::ROWS_PER_QUERY) {
                yield from $this->fetchRows(sprintf('SELECT * FROM %s WHERE %s IN (%s)%s', $sourceTable, $this->quoteIdentifier($parentColumn), Common::getSqlStringFieldsArray($group), $orderBy), $group);
                $group = [];
                $groupSize = 0;
            }
            $group[] = $parentId;
            $groupSize += $numRows;
        }
        if (!empty($group)) {
            yield from $this->fetchRows(sprintf('SELECT * FROM %s WHERE %s IN (%s)%s', $sourceTable, $this->quoteIdentifier($parentColumn), Common::getSqlStringFieldsArray($group), $orderBy), $group);
        }
    }

    /**
     * @param int|string $parentId
     * @return \Generator<array<string, mixed>>
     */
    private function fetchRowsOfParent(string $table, string $parentColumn, $parentId, ?string $idColumn, string $orderBy): \Generator
    {
        $sql = sprintf('SELECT * FROM %s WHERE %s = ?', $this->quoteSourceTable($table), $this->quoteIdentifier($parentColumn));
        if ($idColumn !== null) {
            // the index on the parent column holds the primary key, so the rows are read in this order without sorting
            $lastId = null;
            do {
                $bind = [$parentId];
                $pageSql = $sql;
                if ($lastId !== null) {
                    $pageSql .= sprintf(' AND %s > ?', $this->quoteIdentifier($idColumn));
                    $bind[] = $lastId;
                }
                $rows = $this->fetchRows($pageSql . sprintf(' ORDER BY %s ASC LIMIT %d', $this->quoteIdentifier($idColumn), self::ROWS_PER_QUERY), $bind);
                foreach ($rows as $row) {
                    $lastId = $this->toId($row[$idColumn]);
                    yield $row;
                }
            } while (count($rows) === self::ROWS_PER_QUERY);
            return;
        }

        if ($orderBy === '') {
            // without a key there's no stable order to page through
            yield from $this->fetchRows($sql, [$parentId]);
            return;
        }
        $offset = 0;
        do {
            $rows = $this->fetchRows($sql . sprintf('%s LIMIT %d OFFSET %d', $orderBy, self::ROWS_PER_QUERY, $offset), [$parentId]);
            yield from $rows;
            $offset += self::ROWS_PER_QUERY;
        } while (count($rows) === self::ROWS_PER_QUERY);
    }

    /**
     * Translates the references of a source row.
     *
     * @param array<string, mixed> $row
     * @param string[] $columns the columns of the table
     * @param string[]|null $onlyColumns the columns to translate, null for all
     * @return array<string, mixed>|null the row to insert into the target, or null if it references a row that wasn't migrated
     */
    private function transformRow(TableDefinition $tableDefinition, array $row, array $columns, IdResolver $resolver, ?array $onlyColumns = null): ?array
    {
        $references = $tableDefinition->getReferences($columns);
        $transforms = $tableDefinition->getTransforms();
        if ($onlyColumns !== null) {
            $references = array_intersect_key($references, array_flip($onlyColumns));
            $transforms = array_intersect_key($transforms, array_flip($onlyColumns));
        }

        $targetRow = $row;
        foreach ($references as $column => $referencedTable) {
            $value = $row[$column] ?? null;
            if ($value === null || $value === '' || $value === '0' || $value === 0) {
                continue; // no reference
            }
            $targetId = $resolver->resolve($referencedTable, $this->toId($value));
            if ($targetId === null) {
                return null;
            }
            $targetRow[$column] = $targetId;
        }

        foreach ($transforms as $column => $transform) {
            try {
                $targetRow[$column] = $transform($row[$column] ?? null, $row, $resolver);
            } catch (UnresolvableReferenceException $e) {
                return null;
            }
        }

        return $targetRow;
    }

    public function getSharedTable(string $table): ?TableDefinition
    {
        $tableDefinition = $this->getTableDefinition($table);
        return $tableDefinition !== null && $tableDefinition->isShared() ? $tableDefinition : null;
    }

    /**
     * Copies a row of a shared table unless it was copied before or the target has the same row.
     *
     * @param int|string $sourceId
     * @return int|string|null the target ID, or null if the source row doesn't exist
     */
    public function copySharedRow(TableDefinition $tableDefinition, $sourceId, IdResolver $resolver, TargetDb $targetDb)
    {
        $table = $tableDefinition->getTable();
        if (isset($this->sharedRowIds[$table][$sourceId])) {
            return $this->sharedRowIds[$table][$sourceId];
        }

        $autoIncrementColumn = (string) $tableDefinition->getAutoIncrementColumn();
        $rows = $this->fetchRows(sprintf('SELECT * FROM %s WHERE %s = ?', $this->quoteSourceTable($table), $this->quoteIdentifier($autoIncrementColumn)), [$sourceId]);
        if (empty($rows)) {
            return null;
        }

        $targetRow = $this->transformRow($tableDefinition, $rows[0], $this->getSourceColumns($table), $resolver);
        if ($targetRow === null) {
            return null;
        }
        unset($targetRow[$autoIncrementColumn]);

        $targetId = null;
        $uniqueColumn = $tableDefinition->getUniqueValueColumn();
        $uniqueValue = $uniqueColumn !== null ? $targetRow[$uniqueColumn] : null;
        for ($attempt = 1; $targetId === null && !empty($tableDefinition->getMatchColumns()); $attempt++) {
            if ($uniqueColumn !== null && $attempt > 1) {
                if ($attempt > 100) {
                    throw new \Exception(sprintf('Could not find a free value for the column %s of the table %s on the target', $uniqueColumn, $table));
                }
                $targetRow[$uniqueColumn] = sprintf('%s (%d)', is_scalar($uniqueValue) ? $uniqueValue : '', $attempt);
            }
            $targetId = $this->findMatchingSharedRow($tableDefinition, $targetRow, $targetDb);
            if ($uniqueColumn === null || !$this->isValueTaken($table, $uniqueColumn, $targetRow[$uniqueColumn], $targetDb)) {
                break;
            }
        }

        if ($targetId === null) {
            $targetId = $targetDb->insert($table, $targetRow);
        }

        $this->sharedRowIds[$table][$sourceId] = $targetId;
        return $targetId;
    }

    /**
     * @param array<string, mixed> $targetRow
     * @return int|string|null the ID of the target row having the same values in the match columns
     */
    private function findMatchingSharedRow(TableDefinition $tableDefinition, array $targetRow, TargetDb $targetDb)
    {
        $autoIncrementColumn = (string) $tableDefinition->getAutoIncrementColumn();
        $where = [];
        $bind = [];
        foreach ($tableDefinition->getMatchColumns() as $column) {
            $where[] = sprintf('%s <=> ?', $targetDb->quoteIdentifier($column));
            $bind[] = $targetRow[$column];
        }
        $sql = sprintf('SELECT %s FROM %s WHERE %s LIMIT 1', $targetDb->quoteIdentifier($autoIncrementColumn), $targetDb->quoteTable($tableDefinition->getTable()), implode(' AND ', $where));
        $existingRow = $targetDb->fetchRow($sql, $bind);
        return is_array($existingRow) && isset($existingRow[$autoIncrementColumn]) ? $this->toId($existingRow[$autoIncrementColumn]) : null;
    }

    /**
     * @param mixed $value
     */
    private function isValueTaken(string $table, string $column, $value, TargetDb $targetDb): bool
    {
        return (bool) $targetDb->fetchRow(sprintf('SELECT 1 FROM %s WHERE %s = ? LIMIT 1', $targetDb->quoteTable($table), $targetDb->quoteIdentifier($column)), [$value]);
    }

    /**
     * The name an archive record gets on the target. The patterns of every known plugin are applied, including
     * skipped or deactivated plugins, so no record is copied with an ID that means something else on the target.
     *
     * @return string|null the name, or null if the record belongs to a row that wasn't migrated
     */
    public function getTargetArchiveRecordName(string $name, IdMaps $maps): ?string
    {
        // Matomo locates a segment's archived reports through its done<hash>.<plugin> marker.
        if (preg_match('/^done([a-f0-9]{32})(?=\.|$)/', $name, $matches) && isset($this->segmentHashes[$matches[1]])) {
            return substr_replace($name, $this->segmentHashes[$matches[1]], 4, 32);
        }
        foreach ($this->definitions->getAll() as $definition) {
            foreach ($definition->getArchiveRecordPatterns() as $pattern => $table) {
                if (!preg_match($pattern, $name, $matches, PREG_OFFSET_CAPTURE) || !isset($matches[1])) {
                    continue;
                }

                [$sourceId, $offset] = $matches[1];
                $targetId = $maps->get($table, $this->toId($sourceId));
                if ($targetId === null) {
                    return null;
                }
                return substr($name, 0, $offset) . $targetId . substr($name, $offset + strlen($sourceId));
            }
        }
        return $name;
    }

    /**
     * The value a blob archive record gets on the target: the IDs the labels or other columns of the report rows hold
     * are translated for the records of some plugins, see PluginDefinition::getArchiveRowIds(). Like the names of the
     * records, the IDs of every known plugin are translated.
     *
     * @param string $name the name of the record on the source
     * @param mixed $value
     * @return mixed
     */
    public function getTargetArchiveBlobValue(string $name, $value, IdMaps $maps)
    {
        $chunk = new Chunk();
        $recordName = $chunk->getRecordNameWithoutChunkAppendix($name);
        $rowIds = [];
        $columnNameIds = [];
        foreach ($this->definitions->getAll() as $definition) {
            foreach ($definition->getArchiveRowIds() as $archiveRowIds) {
                if (preg_match($archiveRowIds['recordPattern'], $recordName)) {
                    $rowIds[] = $archiveRowIds;
                }
            }
            foreach ($definition->getArchiveColumnNameIds() as $archiveColumnNameIds) {
                if (preg_match($archiveColumnNameIds['recordPattern'], $recordName)) {
                    $columnNameIds[] = $archiveColumnNameIds;
                }
            }
        }
        if ((empty($rowIds) && empty($columnNameIds)) || !is_string($value)) {
            return $value;
        }

        // like Matomo stores them, see ArchiveWriter
        $uncompressed = @gzuncompress($value);
        $data = $uncompressed === false ? $value : $uncompressed;
        if ($chunk->isRecordNameAChunk($name)) {
            // the serialized rows of several subtables
            $tables = Common::safe_unserialize($data, []);
            if (!is_array($tables)) {
                return $value;
            }
            foreach ($tables as $idTable => $rows) {
                $targetRows = is_string($rows) ? $this->translateRowIds($rows, $rowIds, $columnNameIds, $maps) : null;
                if ($targetRows === null) {
                    return $value;
                }
                $tables[$idTable] = $targetRows;
            }
            $data = serialize($tables);
        } else {
            $data = $this->translateRowIds($data, $rowIds, $columnNameIds, $maps);
            if ($data === null) {
                return $value;
            }
        }

        if ($uncompressed === false) {
            return $data;
        }
        $compressionLevel = Config::getInstance()->General['archive_blob_compression_level'] ?? -1;
        return gzcompress($data, min(max(-1, is_numeric($compressionLevel) ? (int) $compressionLevel : -1), 9));
    }

    /**
     * @param array<int, array{recordPattern: string, table: string, column: string, valuePattern: string}> $rowIds
     * @param array<int, array{recordPattern: string, table: string, columnPattern: string}> $columnNameIds
     * @return string|null the serialized rows with their IDs translated, null if they can't be read
     */
    private function translateRowIds(string $serializedRows, array $rowIds, array $columnNameIds, IdMaps $maps): ?string
    {
        $rows = Common::safe_unserialize($serializedRows, []);
        if (!is_array($rows)) {
            return null;
        }
        foreach ($rows as $idRow => $row) {
            $columns = is_array($row) ? $row[Row::COLUMNS] ?? null : null;
            if (!is_array($row) || !is_array($columns)) {
                continue;
            }
            $isLabelTranslated = false;
            // the summary, totals and metadata rows have negative IDs, they are about no row of a table
            foreach ($idRow < 0 ? [] : $rowIds as ['table' => $table, 'column' => $column, 'valuePattern' => $valuePattern]) {
                $value = $columns[$column] ?? null;
                if (
                    !is_scalar($value)
                    || ($column === 'label' && $isLabelTranslated)
                    || !preg_match($valuePattern, (string) $value, $matches, PREG_OFFSET_CAPTURE)
                    || !isset($matches[1])
                    || in_array($matches[1][0], ['', '0'], true)
                ) {
                    continue;
                }
                [$sourceId, $offset] = $matches[1];
                $targetId = $maps->get($table, $sourceId);
                if ($targetId === null && $column === 'label') {
                    // the row is about data that wasn't migrated
                    unset($rows[$idRow]);
                    continue 2;
                }
                if ($targetId === null) {
                    unset($columns[$column]);
                    continue;
                }
                $targetValue = substr_replace((string) $value, (string) $targetId, $offset, strlen($sourceId));
                $columns[$column] = is_int($value) ? (int) $targetValue : $targetValue;
                $isLabelTranslated = $isLabelTranslated || $column === 'label';
            }
            if (!empty($columnNameIds)) {
                $columns = $this->translateColumnNames($columns, $columnNameIds, $maps);
            }
            $row[Row::COLUMNS] = $columns;
            $rows[$idRow] = $row;
        }
        return serialize($rows);
    }

    /**
     * @param array<int|string, mixed> $columns
     * @param array<int, array{recordPattern: string, table: string, columnPattern: string}> $columnNameIds
     * @return array<int|string, mixed>
     */
    private function translateColumnNames(array $columns, array $columnNameIds, IdMaps $maps): array
    {
        $targetColumns = [];
        foreach ($columns as $column => $value) {
            foreach ($columnNameIds as ['table' => $table, 'columnPattern' => $columnPattern]) {
                if (!preg_match($columnPattern, (string) $column, $matches, PREG_OFFSET_CAPTURE) || !isset($matches[1])) {
                    continue;
                }
                [$sourceId, $offset] = $matches[1];
                $targetId = $this->getSharedTable($table) !== null ? $this->sharedRowIds[$table][$sourceId] ?? null : $maps->get($table, $sourceId);
                if ($targetId === null) {
                    // the column is about data that wasn't migrated
                    continue 2;
                }
                $column = substr_replace((string) $column, (string) $targetId, $offset, strlen($sourceId));
                break;
            }
            $targetColumns[$column] = $value;
        }
        return $targetColumns;
    }

    /**
     * The source tables that have rows of the site but are not migrated, because no plugin definition describes them
     * or because their plugin is skipped. Tables of core are not reported, these are migrated or not site specific.
     *
     * @return string[] prefixed table names
     */
    public function getTablesNotMigrated(int $idSite, TargetDb $targetDb): array
    {
        $prefix = Common::prefixTable('');
        $targetPrefix = $targetDb->prefixTable('');
        $knownTables = array_merge(
            array_keys(DbHelper::getTablesCreateSql()),
            self::TABLES_MIGRATED_BY_CORE_MIGRATIONS,
            $this->getTablesToMigrate()
        );
        $pluginManager = Plugin\Manager::getInstance();
        if ($pluginManager->isPluginActivated('CustomAlerts') && $pluginManager->isPluginLoaded('CustomAlerts')) {
            // Provider migrates these tables separately, after the premium-report ID maps have been built.
            $knownTables = array_merge($knownTables, ['alert', 'alert_site', 'alert_triggered']);
        }

        $tablesNotMigrated = [];
        foreach (Db::fetchAll('SHOW TABLES') as $row) {
            $prefixedTable = (string) reset($row);
            if ($prefix !== '' && strpos($prefixedTable, $prefix) !== 0) {
                continue;
            }
            $table = substr($prefixedTable, strlen($prefix));
            if (
                in_array($table, $knownTables, true)
                || strpos($table, 'archive_') === 0
                || ($targetPrefix !== $prefix && $targetPrefix !== '' && strpos($prefixedTable, $targetPrefix) === 0)
            ) {
                // the special case of migrating to the same DB with another prefix, see ArchiveMigration
                continue;
            }

            if (in_array('idsite', $this->getSourceColumns($table), true)) {
                $hasRowsOfSite = Db::fetchOne(sprintf('SELECT 1 FROM %s WHERE `idsite` = ? LIMIT 1', $this->quoteIdentifier($prefixedTable)), [$idSite]);
                if ($hasRowsOfSite) {
                    $tablesNotMigrated[] = $prefixedTable;
                }
            }
        }
        return $tablesNotMigrated;
    }

    /**
     * @return string[] unprefixed names of the tables migrated, in migration order, and of the tables the site steps of
     *                  the definitions migrate, see PluginDefinition::migratesSiteWith()
     */
    public function getTablesToMigrate(bool $withVisitData = true): array
    {
        $tables = [];
        foreach ($this->getTablesWithPhase() as ['table' => $tableDefinition, 'phase' => $phase]) {
            if ($phase !== null && ($withVisitData || !$this->isRawData($phase))) {
                $tables[] = $tableDefinition->getTable();
            }
        }
        foreach ($this->getDefinitionsToMigrate() as $definition) {
            $tables = array_merge($tables, $definition->getSiteStepTables());
        }
        return $tables;
    }

    /**
     * Runs the site steps of the definitions, see PluginDefinition::migratesSiteWith()
     *
     * @return string[] the messages of the steps
     */
    public function migrateWithSiteSteps(Request $request, TargetDb $targetDb): array
    {
        $messages = [];
        foreach ($this->getDefinitionsToMigrate() as $definition) {
            foreach ($definition->getSiteSteps() as $migrate) {
                $messages = array_merge($messages, $migrate($request, $targetDb));
            }
        }
        return $messages;
    }

    /**
     * Raw data is migrated with the visits, so it's skipped with --skip-logs
     */
    private function isRawData(string $phase): bool
    {
        return $phase === self::PHASE_VISIT || $phase === self::PHASE_SITE_LOG;
    }

    /**
     * @return TableDefinition[]
     */
    private function getTablesOfPhase(string $phase): array
    {
        $tables = [];
        foreach ($this->getTablesWithPhase() as ['table' => $tableDefinition, 'phase' => $tablePhase]) {
            if ($tablePhase === $phase) {
                $tables[] = $tableDefinition;
            }
        }
        return $tables;
    }

    private function getTableDefinition(string $table): ?TableDefinition
    {
        foreach ($this->getTablesWithPhase() as ['table' => $tableDefinition]) {
            if ($tableDefinition->getTable() === $table) {
                return $tableDefinition;
            }
        }
        return null;
    }

    /**
     * @return string[]
     */
    private function getPluginNames(): array
    {
        return array_map(function (PluginDefinition $definition) {
            return $definition->getPluginName();
        }, $this->definitions->getAll());
    }

    /**
     * @return string[]
     */
    private function getSourceColumns(string $table): array
    {
        return array_map('strval', array_keys($this->getSourceColumnDefinitions($table)));
    }

    /**
     * @return array<string, array<string, mixed>> column => definition as in SHOW COLUMNS
     */
    private function getSourceColumnDefinitions(string $table): array
    {
        if (!isset($this->sourceColumns[$table])) {
            /** @var array<array{Field: string}&array<string, mixed>> $columns */
            $columns = Db::fetchAll('SHOW COLUMNS FROM ' . $this->quoteSourceTable($table));
            $this->sourceColumns[$table] = [];
            foreach ($columns as $column) {
                $this->sourceColumns[$table][$column['Field']] = $column;
            }
        }
        return $this->sourceColumns[$table];
    }

    /**
     * The value of a column that holds no value: NULL if the column is nullable, otherwise 0 or ''
     *
     * @return int|string|null
     */
    private function getEmptyValue(string $table, string $column)
    {
        $definition = $this->getSourceColumnDefinitions($table)[$column] ?? [];
        if (($definition['Null'] ?? 'YES') === 'YES') {
            return null;
        }
        $type = $definition['Type'] ?? '';
        return is_string($type) && preg_match('/int|decimal|float|double|bit/i', $type) ? 0 : '';
    }

    private function quoteSourceTable(string $table): string
    {
        return $this->quoteIdentifier(Common::prefixTable($table));
    }

    private function doesSourceTableExist(string $table): bool
    {
        return (bool) Db::fetchOne('SHOW TABLES LIKE ?', [addcslashes(Common::prefixTable($table), '\\_%')]);
    }

    private function quoteIdentifier(string $identifier): string
    {
        // Quote a single name, including embedded backticks or dots, through the source adapter.
        /** @var \Zend_Db_Adapter_Abstract $db Migration runs outside the tracker, using the regular DB adapter. */
        $db = Db::get();
        return $db->quoteIdentifier([$identifier]);
    }

    /**
     * @return string[]
     */
    private function getSourcePrimaryKey(string $table): array
    {
        $keys = Db::fetchAll(sprintf("SHOW KEYS FROM %s WHERE Key_name = 'PRIMARY'", $this->quoteSourceTable($table)));
        usort($keys, function ($a, $b) {
            return $a['Seq_in_index'] <=> $b['Seq_in_index'];
        });
        return array_map(function ($key) {
            return (string) $key['Column_name'];
        }, $keys);
    }

    /**
     * @param array<int|string|null> $bind
     * @return array<int, array<string, mixed>>
     */
    private function fetchRows(string $sql, array $bind): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = Db::fetchAll($sql, $bind);
        return $rows;
    }

    /**
     * @param mixed $value
     * @return int|string
     */
    private function toId($value)
    {
        if (is_int($value) || is_string($value)) {
            return $value;
        }
        if (is_float($value) || is_bool($value)) {
            return (int) $value;
        }
        throw new \InvalidArgumentException('An ID must be a scalar value');
    }
}
