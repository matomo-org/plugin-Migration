<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\PluginData\Definitions;

use Piwik\Common;
use Piwik\Date;
use Piwik\Db;
use Piwik\Option;
use Piwik\Plugins\Migration\Migrations\Request;
use Piwik\Plugins\Migration\PluginData\PluginDefinition;
use Piwik\Plugins\Migration\TargetDb;
use Piwik\SettingsPiwik;

/**
 * The sites a roll-up aggregates, which are other sites. Each site the migration migrates is remembered on the target
 * Matomo, so a roll-up gets the target sites its sites were migrated to, and a migrated site is added to the roll-ups it
 * belongs to that were migrated before. A roll-up of all websites aggregates all sites of the target, like the plugin
 * adds a new site to it.
 */
final class RollUpReporting
{
    private const ROLL_UP_TYPE = 'rollup';

    /**
     * The option the plugin marks a roll-up of all websites with, followed by its ID
     */
    private const ALL_WEBSITES_OPTION = 'rollup_all_sites_idsite_';

    /**
     * The option of the target remembering the target site a site of the source was migrated to, followed by a key of
     * the source Matomo and the ID of the source site
     */
    private const MIGRATED_SITE_OPTION = 'MigrationSourceSite_';

    public static function get(): PluginDefinition
    {
        $definition = new PluginDefinition('RollUpReporting');
        $definition->migratesSiteWith([self::class, 'migrateRollUps'], 'site_rollup');
        return $definition;
    }

    /**
     * @return string[] messages about the roll-ups
     */
    public static function migrateRollUps(Request $request, TargetDb $targetDb): array
    {
        $sourceIdSite = (int) $request->sourceIdSite;
        $targetIdSite = (int) $request->targetIdSite;
        self::rememberTargetSite($sourceIdSite, $targetIdSite, $targetDb);
        $rollUpTable = self::quoteSourceTable('site_rollup');
        $messages = [];
        $hasUnscopedMapping = false;

        // the roll-ups of the source the site belongs to, the ones not migrated yet get the site when they are
        $rollUpsWithSite = [];
        $rollUpsNotMigrated = [];
        foreach (Db::fetchAll("SELECT parent_idsite FROM $rollUpTable WHERE idsite = ? ORDER BY parent_idsite", [$sourceIdSite]) as $row) {
            $idRollUp = self::getTargetSite((int) $row['parent_idsite'], $targetDb, $hasUnscopedMapping);
            if ($idRollUp === null || !self::isRollUp($idRollUp, $targetDb)) {
                $rollUpsNotMigrated[] = (int) $row['parent_idsite'];
            } elseif (self::addSiteToRollUp($targetIdSite, $idRollUp, $targetDb)) {
                $rollUpsWithSite[] = $idRollUp;
            }
        }
        if (!empty($rollUpsNotMigrated)) {
            $messages[] = sprintf('The site belongs to the roll-ups %s of the source Matomo, which were not migrated yet: it is added to them when they are migrated', self::describeSourceSites($rollUpsNotMigrated));
        }

        if (!self::isRollUp($sourceIdSite)) {
            // like the plugin adds a new site to the roll-ups of all websites
            foreach (self::getRollUpsOfAllWebsites($targetDb) as $idRollUp) {
                if (self::addSiteToRollUp($targetIdSite, $idRollUp, $targetDb)) {
                    $rollUpsWithSite[] = $idRollUp;
                }
            }
        } elseif (Option::get(self::ALL_WEBSITES_OPTION . $sourceIdSite)) {
            self::setOption(self::ALL_WEBSITES_OPTION . $targetIdSite, (string) $targetIdSite, $targetDb);
            $siteTable = $targetDb->quoteTable('site');
            foreach ($targetDb->fetchAll("SELECT idsite FROM $siteTable WHERE `type` <> ? AND idsite <> ? ORDER BY idsite", [self::ROLL_UP_TYPE, $targetIdSite]) as $row) {
                self::addSiteToRollUp(self::toInt($row['idsite']), $targetIdSite, $targetDb);
            }
            self::syncRollUp($targetIdSite, $targetDb);
            $messages[] = 'Note: The roll-up of all websites aggregates all sites of the target Matomo. Its copied reports are about the sites of the source Matomo, '
                . 'to process them again from the sites of the target Matomo, execute there ' . self::getInvalidationCommand([$targetIdSite], $request);
        } else {
            // the sites of the roll-up, the ones not migrated yet are added when they are
            $sitesNotMigrated = [];
            foreach (Db::fetchAll("SELECT idsite FROM $rollUpTable WHERE parent_idsite = ? ORDER BY idsite", [$sourceIdSite]) as $row) {
                $idSite = self::getTargetSite((int) $row['idsite'], $targetDb, $hasUnscopedMapping);
                if ($idSite === null) {
                    $sitesNotMigrated[] = (int) $row['idsite'];
                } else {
                    self::addSiteToRollUp($idSite, $targetIdSite, $targetDb);
                }
            }
            self::syncRollUp($targetIdSite, $targetDb);
            if (!empty($sitesNotMigrated)) {
                $messages[] = sprintf('The roll-up aggregates the sites %s of the source Matomo, which were not migrated yet: they are added to it when they are migrated', self::describeSourceSites($sitesNotMigrated));
            }
        }

        // A changed descendant affects every containing roll-up, even when its direct membership already existed.
        // Synchronize children before parents; cycle detection also handles inconsistent existing memberships.
        $affectedRollUps = self::getContainingRollUps($targetIdSite, $targetDb);
        foreach ($affectedRollUps as $idRollUp) {
            self::syncRollUp($idRollUp, $targetDb);
        }
        if (!empty($affectedRollUps)) {
            $messages[] = sprintf(
                empty($rollUpsWithSite)
                    ? 'Note: The site contributes to the roll-ups %s of the target Matomo. To include its data in their reports, execute there %s'
                    : 'Note: The site was added to the roll-ups %s of the target Matomo. To include its data in their reports, execute there %s',
                implode(', ', $affectedRollUps),
                self::getInvalidationCommand($affectedRollUps, $request)
            );
        }
        if ($hasUnscopedMapping) {
            $messages[] = 'Note: Earlier roll-up site mappings do not identify the source database and table prefix and cannot be reused safely. '
                . 'Reconfigure those roll-up memberships on the target Matomo after verifying the source and target site IDs.';
        }
        return $messages;
    }

    /**
     * @return int[] all containing roll-ups, with descendants before their ancestors
     */
    private static function getContainingRollUps(int $idSite, TargetDb $targetDb): array
    {
        $visited = [];
        $rollUps = [];
        self::collectContainingRollUps($idSite, $targetDb, $visited, $rollUps);
        // The starting site is appended last and is already synchronized if it is itself a roll-up.
        array_pop($rollUps);
        return array_reverse($rollUps);
    }

    /**
     * @param array<int, bool> $visited
     * @param int[] $rollUps
     */
    private static function collectContainingRollUps(int $idSite, TargetDb $targetDb, array &$visited, array &$rollUps): void
    {
        if (isset($visited[$idSite])) {
            return;
        }
        $visited[$idSite] = true;
        $rollUpTable = $targetDb->quoteTable('site_rollup');
        $siteTable = $targetDb->quoteTable('site');
        $parents = $targetDb->fetchAll(
            "SELECT r.parent_idsite FROM $rollUpTable r JOIN $siteTable s ON s.idsite = r.parent_idsite"
            . ' WHERE r.idsite = ? AND s.`type` = ? ORDER BY r.parent_idsite DESC',
            [$idSite, self::ROLL_UP_TYPE]
        );
        foreach ($parents as $parent) {
            self::collectContainingRollUps(self::toInt($parent['parent_idsite']), $targetDb, $visited, $rollUps);
        }
        $rollUps[] = $idSite;
    }

    /**
     * Like the plugin, the roll-up gets the ecommerce and site search settings of its sites, and the creation date of
     * the oldest one unless the plugin is configured otherwise.
     */
    private static function syncRollUp(int $idRollUp, TargetDb $targetDb): void
    {
        $idSites = self::getSitesOfRollUp($idRollUp, $targetDb);
        if (empty($idSites)) {
            return;
        }
        $siteTable = $targetDb->quoteTable('site');
        $sites = $targetDb->fetchRow(
            "SELECT MAX(ecommerce) AS ecommerce, MAX(sitesearch) AS sitesearch, MIN(ts_created) AS ts_created FROM $siteTable WHERE idsite IN (" . Common::getSqlStringFieldsArray($idSites) . ')',
            $idSites
        );
        if (!is_array($sites)) {
            return;
        }
        $columns = ['ecommerce' => self::toInt($sites['ecommerce']), 'sitesearch' => self::toInt($sites['sitesearch'])];
        $settingTable = $targetDb->quoteTable('plugin_setting');
        $syncCreationDate = $targetDb->fetchOne("SELECT setting_value FROM $settingTable WHERE plugin_name = 'RollUpReporting' AND setting_name = 'metric' AND user_login = ''");
        $created = $targetDb->fetchOne("SELECT ts_created FROM $siteTable WHERE idsite = ?", [$idRollUp]);
        if (!in_array($syncCreationDate, ['0', '', 'false'], true) && is_string($sites['ts_created']) && (!is_string($created) || $sites['ts_created'] < $created)) {
            $columns['ts_created'] = $sites['ts_created'];
        }
        $targetDb->update('site', $columns, ['idsite' => $idRollUp]);
    }

    /**
     * @return int[] the sites a roll-up aggregates, also through roll-ups it aggregates, which aren't roll-ups
     */
    private static function getSitesOfRollUp(int $idRollUp, TargetDb $targetDb): array
    {
        $rollUpTable = $targetDb->quoteTable('site_rollup');
        $siteTable = $targetDb->quoteTable('site');
        $idSites = [];
        $rollUpsToVisit = [$idRollUp];
        $visited = [];
        while (!empty($rollUpsToVisit)) {
            $id = (int) array_pop($rollUpsToVisit);
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;
            $sites = $targetDb->fetchAll("SELECT s.idsite, s.`type` FROM $rollUpTable r JOIN $siteTable s ON s.idsite = r.idsite WHERE r.parent_idsite = ?", [$id]);
            foreach ($sites as $site) {
                $idSite = self::toInt($site['idsite']);
                if ($site['type'] !== self::ROLL_UP_TYPE) {
                    $idSites[$idSite] = $idSite;
                } elseif (!isset($visited[$idSite])) {
                    $rollUpsToVisit[] = $idSite;
                }
            }
        }
        return array_values($idSites);
    }

    /**
     * @return bool whether the site was added, false if the roll-up has it already
     */
    private static function addSiteToRollUp(int $idSite, int $idRollUp, TargetDb $targetDb): bool
    {
        $rollUpTable = $targetDb->quoteTable('site_rollup');
        if ($idSite === $idRollUp || $targetDb->fetchOne("SELECT 1 FROM $rollUpTable WHERE parent_idsite = ? AND idsite = ?", [$idRollUp, $idSite])) {
            return false;
        }
        $targetDb->insert('site_rollup', ['parent_idsite' => $idRollUp, 'idsite' => $idSite]);
        return true;
    }

    /**
     * @return int[] the roll-ups of all websites of the target
     */
    private static function getRollUpsOfAllWebsites(TargetDb $targetDb): array
    {
        $optionTable = $targetDb->quoteTable('option');
        $idRollUps = [];
        foreach ($targetDb->fetchAll("SELECT option_name FROM $optionTable WHERE option_name LIKE ?", [self::ALL_WEBSITES_OPTION . '%']) as $row) {
            if (is_string($row['option_name']) && preg_match('/^' . self::ALL_WEBSITES_OPTION . '([1-9][0-9]*)$/', $row['option_name'], $matches) && self::isRollUp((int) $matches[1], $targetDb)) {
                $idRollUps[] = (int) $matches[1];
            }
        }
        sort($idRollUps);
        return $idRollUps;
    }

    /**
     * @param TargetDb|null $targetDb null for a site of the source
     */
    private static function isRollUp(int $idSite, ?TargetDb $targetDb = null): bool
    {
        $type = $targetDb === null
            ? Db::fetchOne('SELECT `type` FROM ' . self::quoteSourceTable('site') . ' WHERE idsite = ?', [$idSite])
            : $targetDb->fetchOne('SELECT `type` FROM ' . $targetDb->quoteTable('site') . ' WHERE idsite = ?', [$idSite]);
        return $type === self::ROLL_UP_TYPE;
    }

    private static function rememberTargetSite(int $sourceIdSite, int $targetIdSite, TargetDb $targetDb): void
    {
        self::setOption(self::getMigratedSiteOption($sourceIdSite), (string) $targetIdSite, $targetDb);
    }

    /**
     * @return int|null the existing target site a site of the source was migrated to
     */
    private static function getTargetSite(int $sourceIdSite, TargetDb $targetDb, bool &$hasUnscopedMapping): ?int
    {
        $idSite = $targetDb->fetchOne('SELECT option_value FROM ' . $targetDb->quoteTable('option') . ' WHERE option_name = ?', [self::getMigratedSiteOption($sourceIdSite)]);
        if (!is_numeric($idSite) || !$targetDb->fetchOne('SELECT 1 FROM ' . $targetDb->quoteTable('site') . ' WHERE idsite = ?', [$idSite])) {
            // The old salt-only key may belong to another database or prefix. Never use it as a fallback.
            $legacyOption = self::MIGRATED_SITE_OPTION . substr(hash('sha256', 'Migration' . SettingsPiwik::getSalt()), 0, 16) . '_' . $sourceIdSite;
            $legacyId = $targetDb->fetchOne('SELECT option_value FROM ' . $targetDb->quoteTable('option') . ' WHERE option_name = ?', [$legacyOption]);
            if (is_numeric($legacyId) && $targetDb->fetchOne('SELECT 1 FROM ' . $targetDb->quoteTable('site') . ' WHERE idsite = ?', [$legacyId])) {
                $hasUnscopedMapping = true;
            }
            return null;
        }
        return (int) $idSite;
    }

    /**
     * A source can have several Matomo databases or table prefixes sharing a salt, especially in WordPress multisite.
     * Version the key because the old salt-only mappings cannot tell those sources apart.
     */
    private static function getMigratedSiteOption(int $sourceIdSite): string
    {
        $source = serialize([SettingsPiwik::getSalt(), Db::fetchOne('SELECT DATABASE()'), Common::prefixTable('')]);
        return self::MIGRATED_SITE_OPTION . 'v2_' . substr(hash('sha256', $source), 0, 32) . '_' . $sourceIdSite;
    }

    private static function setOption(string $name, string $value, TargetDb $targetDb): void
    {
        $targetDb->delete('option', ['option_name' => $name]);
        $targetDb->insert('option', ['option_name' => $name, 'option_value' => $value, 'autoload' => 0]);
    }

    /**
     * @param mixed $value
     */
    private static function toInt($value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param int[] $idSites
     */
    private static function describeSourceSites(array $idSites): string
    {
        $names = array_column(Db::fetchAll('SELECT idsite, name FROM ' . self::quoteSourceTable('site') . ' WHERE idsite IN (' . Common::getSqlStringFieldsArray($idSites) . ')', $idSites), 'name', 'idsite');
        return implode(', ', array_map(function ($idSite) use ($names) {
            return sprintf('"%s" (ID %s)', $names[$idSite] ?? '', $idSite);
        }, $idSites));
    }

    /**
     * @param int[] $idSites
     */
    private static function getInvalidationCommand(array $idSites, Request $request): string
    {
        $created = Db::fetchOne('SELECT ts_created FROM ' . self::quoteSourceTable('site') . ' WHERE idsite = ?', [$request->sourceIdSite]);
        return sprintf(
            '"./console core:invalidate-report-data --sites=%s --dates=%s,%s" and "./console core:archive".',
            implode(',', $idSites),
            Date::factory(is_string($created) ? $created : 'today')->toString(),
            Date::today()->toString()
        );
    }

    private static function quoteSourceTable(string $table): string
    {
        /** @var \Zend_Db_Adapter_Abstract $db Migration runs outside the tracker, using the regular DB adapter. */
        $db = Db::get();
        return $db->quoteIdentifier([Common::prefixTable($table)]);
    }
}
