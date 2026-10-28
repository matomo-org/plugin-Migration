<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\Migrations;

use Piwik\Plugins\Migration\PluginData\PluginDataMigrator;
use Piwik\Plugins\Migration\TargetDb;

/**
 * Migrates the site data of plugins that have a plugin data definition, eg the heatmaps configured for the site.
 * Their visit data is migrated by the LogMigration and their archives by the ArchiveMigration.
 */
class PluginDataMigration extends BaseMigration
{
    /**
     * @var PluginDataMigrator
     */
    private $migrator;

    /**
     * @var bool
     */
    private $withVisitData;

    public function __construct(PluginDataMigrator $migrator, bool $withVisitData)
    {
        $this->migrator = $migrator;
        $this->withVisitData = $withVisitData;
    }

    /**
     * @return string[]
     */
    public function validateStructure(TargetDb $targetDb)
    {
        $errors = $this->migrator->validate($targetDb, $this->withVisitData);

        foreach ($this->migrator->getTablesToMigrate($this->withVisitData) as $table) {
            if ($targetDb->doesTableExist($targetDb->prefixTable($table))) {
                $errors = array_merge($errors, $this->checkTablesHaveSameStructure($targetDb, $table));
            }
        }

        return $errors;
    }

    public function migrate(Request $request, TargetDb $targetDb): void
    {
        foreach ($this->migrator->getTablesNotMigrated($request->sourceIdSite, $targetDb) as $table) {
            $this->log(sprintf('The table %s has data of this site that is not migrated, as no plugin data definition of an activated plugin describes it', $table));
        }

        $pluginNames = array_map(function ($definition) {
            return $definition->getPluginName();
        }, $this->migrator->getDefinitionsToMigrate());
        $this->log(sprintf('Found %s plugins with data to migrate%s', count($pluginNames), $pluginNames ? ': ' . implode(', ', $pluginNames) : ''));

        $countsPerTable = $this->migrator->migrateSiteData($request, $targetDb);
        foreach ($countsPerTable as $table => $counts) {
            $this->logRowCounts($table, $counts);
        }
        if ($this->migrator->getNumCopiedSegmentsOfAllWebsites()) {
            $this->log(sprintf('Added %s segments of all websites that the migrated data uses as segments of the target site', $this->migrator->getNumCopiedSegmentsOfAllWebsites()));
        }
        $numChangedSegments = $this->migrator->pointSegmentsToTargetIds($request, $targetDb);
        if ($numChangedSegments) {
            $this->log(sprintf('Changed %s segments selecting plugin data by ID to select it by its ID on the target', $numChangedSegments));
        }
        foreach ($this->migrator->getNumChangedUniqueKeys() as $table => $numChanged) {
            $this->log(sprintf('The key of %s rows of %s was used on the target already and was changed', $numChanged, $table));
        }
        foreach ($this->migrator->migrateWithSiteSteps($request, $targetDb) as $message) {
            $this->log($message);
        }

        foreach ($this->migrator->getDefinitionsToMigrate() as $definition) {
            $hasData = false;
            foreach ($definition->getTables() as $tableDefinition) {
                // the data stored per key of the site is migrated later, with the visits
                $hasData = $hasData || !empty($countsPerTable[$tableDefinition->getTable()]['migrated'])
                    || !empty($tableDefinition->getSiteKeys($request->sourceIdSite));
            }
            if ($hasData) {
                foreach ($definition->getNotes() as $note) {
                    $this->log('Note: ' . $note);
                }
            }
        }
    }
}
