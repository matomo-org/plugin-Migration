<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\PluginData;

use Piwik\Plugins\Migration\Migrations\Request;
use Piwik\Plugins\Migration\TargetDb;

/**
 * Describes the data of a plugin that is migrated along with a site: the plugin tables and the archive records
 * whose name contains the ID of a row of one of these tables.
 *
 * Tables are migrated in the order they are added, so a table must be added after the tables it references.
 */
final class PluginDefinition
{
    /**
     * @var string
     */
    private $pluginName;

    /**
     * @var TableDefinition[]
     */
    private $tables = [];

    /**
     * @var array<string, string>
     */
    private $archiveRecordPatterns = [];

    /**
     * @var array<string, string>
     */
    private $reportParameters = [];

    /**
     * @var array<string, string>
     */
    private $segmentTables = [];

    /**
     * @var array<string, string>
     */
    private $subcategoryTables = [];

    /**
     * @var array<int, array{recordPattern: string, table: string, column: string, valuePattern: string}>
     */
    private $archiveRowIds = [];

    /**
     * @var array<int, array{recordPattern: string, table: string, columnPattern: string}>
     */
    private $archiveColumnNameIds = [];

    /**
     * @var string[]
     */
    private $notes = [];

    /**
     * @var array<int, callable(Request, TargetDb): string[]>
     */
    private $siteSteps = [];

    /**
     * @var string[]
     */
    private $siteStepTables = [];

    public function __construct(string $pluginName)
    {
        $this->pluginName = $pluginName;
    }

    public function getPluginName(): string
    {
        return $this->pluginName;
    }

    public function addTable(TableDefinition $table): self
    {
        $this->tables[] = $table;
        return $this;
    }

    /**
     * @return TableDefinition[]
     */
    public function getTables(): array
    {
        return $this->tables;
    }

    /**
     * Archive records whose name matches the pattern are renamed, replacing the source ID in the capturing group by
     * the ID the row got on the target. Records of rows that weren't migrated are not copied, as they would otherwise
     * be shown for an unrelated row having that ID on the target.
     *
     * @param string $pattern a regular expression with one capturing group, eg '/^MyPlugin_report_(\d+)(?=_|$)/'
     * @param string $table unprefixed name of a table of this definition that has an auto increment column
     */
    public function renamesArchiveRecords(string $pattern, string $table): self
    {
        $this->archiveRecordPatterns[$pattern] = $table;
        return $this;
    }

    /**
     * @return array<string, string> pattern => table
     */
    public function getArchiveRecordPatterns(): array
    {
        return $this->archiveRecordPatterns;
    }

    /**
     * The rows of the reports in archive records whose name matches the pattern are labeled with the ID of a row of a
     * table, eg the variations of an A/B test. The labels are changed to the ID the row got on the target. Report rows
     * of rows that weren't migrated are removed.
     *
     * @param string $recordPattern a regular expression matching the names of blob archive records, eg '/^MyPlugin_/'
     * @param string $table unprefixed name of a table of this definition that has an auto increment column
     * @param string $labelPattern a regular expression with one capturing group matching the ID in a label, eg
     *                             '/^(\d+)(?=_|$)/' for a label "3_10". Labels that don't match are kept.
     */
    public function translatesArchiveRowLabels(string $recordPattern, string $table, string $labelPattern = '/^(\d+)$/'): self
    {
        $this->archiveRowIds[] = ['recordPattern' => $recordPattern, 'table' => $table, 'column' => 'label', 'valuePattern' => $labelPattern];
        return $this;
    }

    /**
     * The rows of the reports in archive records whose name matches the pattern hold the ID of a row of a table in a
     * column, eg the crash a row is about, to link to it. The ID is changed to the ID the row got on the target. The
     * column is removed from report rows of rows that weren't migrated.
     *
     * @param string $recordPattern a regular expression matching the names of blob archive records, eg '/^MyPlugin_/'
     * @param string $table unprefixed name of a table of this definition that has an auto increment column
     */
    public function translatesArchiveRowColumn(string $recordPattern, string $table, string $column): self
    {
        $this->archiveRowIds[] = ['recordPattern' => $recordPattern, 'table' => $table, 'column' => $column, 'valuePattern' => '/^(\d+)$/'];
        return $this;
    }

    /**
     * The IDs the rows of reports hold, see translatesArchiveRowLabels() and translatesArchiveRowColumn()
     *
     * @return array<int, array{recordPattern: string, table: string, column: string, valuePattern: string}>
     */
    public function getArchiveRowIds(): array
    {
        return $this->archiveRowIds;
    }

    /**
     * The rows of the reports in archive records whose name matches the pattern have columns named after the ID of a
     * row of a table, eg a metric defined by the plugin. The columns are renamed with the ID the row got on the
     * target. Columns of rows that weren't migrated are removed.
     *
     * @param string $recordPattern a regular expression matching the names of blob archive records, eg '/^MyPlugin_/'
     * @param string $table unprefixed name of a table of this definition that has an auto increment column
     * @param string $columnPattern a regular expression with one capturing group matching the ID in a column name, eg
     *                              '/^my_metric_(\d+)$/'
     */
    public function translatesArchiveRowColumnNames(string $recordPattern, string $table, string $columnPattern): self
    {
        $this->archiveColumnNameIds[] = ['recordPattern' => $recordPattern, 'table' => $table, 'columnPattern' => $columnPattern];
        return $this;
    }

    /**
     * @return array<int, array{recordPattern: string, table: string, columnPattern: string}>
     */
    public function getArchiveColumnNameIds(): array
    {
        return $this->archiveColumnNameIds;
    }

    /**
     * Reports of the plugin are about a row of a table, whose ID is a report parameter, eg the report with the unique
     * ID "MyPlugin_getItem_idItem--3" in the report metadata. When the scheduled reports, alerts or other data
     * referencing reports are migrated, the ID is translated to the ID the row got on the target.
     *
     * @param string $parameter the report parameter, eg 'idItem'
     * @param string $table unprefixed name of a table of this definition
     */
    public function identifiesReportsBy(string $parameter, string $table): self
    {
        $this->reportParameters[$parameter] = $table;
        return $this;
    }

    /**
     * @return array<string, string> report parameter => table
     */
    public function getReportParameters(): array
    {
        return $this->reportParameters;
    }

    /**
     * A segment of the plugin selects a row of a table by its ID when the value is a number, eg "myPluginItem==3".
     * Segments selecting a row this way are changed to select the row by the ID it got on the target.
     *
     * @param string $segment the segment, eg 'myPluginItem'
     * @param string $table unprefixed name of a table of this definition
     */
    public function identifiesSegmentsBy(string $segment, string $table): self
    {
        $this->segmentTables[$segment] = $table;
        return $this;
    }

    /**
     * @return array<string, string> segment => table
     */
    public function getSegmentTables(): array
    {
        return $this->segmentTables;
    }

    /**
     * The reporting pages of the plugin, the subcategories of a category, are identified by the ID of a row of a table,
     * eg one page per item with the subcategory ID "3". When data placing something on such a page is migrated, eg a
     * custom report, the ID is translated to the ID the row got on the target.
     *
     * @param string $category the category ID, eg 'MyPlugin_Items'
     * @param string $table unprefixed name of a table of this definition
     */
    public function identifiesSubcategoriesBy(string $category, string $table): self
    {
        $this->subcategoryTables[$category] = $table;
        return $this;
    }

    /**
     * @return array<string, string> category => table
     */
    public function getSubcategoryTables(): array
    {
        return $this->subcategoryTables;
    }

    /**
     * Migrates data of the plugin that table definitions can't describe, eg relationships between sites. The callback
     * is called after the tables of the site are migrated and returns the
     * messages to show, eg about data that couldn't be migrated. It writes to the target only through the target DB,
     * which does nothing in a dry run.
     *
     * @param callable(Request, TargetDb): string[] $migrate
     * @param string ...$tables unprefixed names of the tables it migrates, which need to exist on the target
     */
    public function migratesSiteWith(callable $migrate, string ...$tables): self
    {
        $this->siteSteps[] = $migrate;
        $this->siteStepTables = array_merge($this->siteStepTables, $tables);
        return $this;
    }

    /**
     * @return array<int, callable(Request, TargetDb): string[]>
     */
    public function getSiteSteps(): array
    {
        return $this->siteSteps;
    }

    /**
     * @return string[] the tables the site steps migrate, see migratesSiteWith()
     */
    public function getSiteStepTables(): array
    {
        return $this->siteStepTables;
    }

    /**
     * A message shown after migrating the data of the plugin, eg about a step to do on the target Matomo.
     */
    public function addNote(string $note): self
    {
        $this->notes[] = $note;
        return $this;
    }

    /**
     * @return string[]
     */
    public function getNotes(): array
    {
        return $this->notes;
    }
}
