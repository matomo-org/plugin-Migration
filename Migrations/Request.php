<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\Migrations;

use Piwik\Date;
use Piwik\Plugins\Migration\PluginData\IdMaps;
use Piwik\Site;

class Request
{
    /**
     * @var int
     */
    public $sourceIdSite;

    /**
     * @var int
     */
    public $targetIdSite;

    /**
     * Whether the data is migrated into a site that existed on the target before, instead of creating a new site
     *
     * @var bool
     */
    public $isExistingTargetSite = false;

    /** @var bool Whether the operator confirms destination tracking and archiving remain stopped throughout migration. */
    public $targetSiteIsOffline = false;

    /**
     * Why the reports are not copied but need to be processed on the target, null if they are copied
     *
     * @var string|null
     */
    public $archivesNotCopiedReason;

    /**
     * First day of the data to migrate in the timezone of the site, eg "2024-01-01", null to migrate all data
     *
     * @var string|null
     */
    public $startDate;

    /**
     * Last day of the data to migrate in the timezone of the site, null to migrate all data
     *
     * @var string|null
     */
    public $endDate;

    /**
     * The target ID of each migrated row, for the tables other rows may reference
     *
     * @var IdMaps
     */
    public $idMaps;

    /**
     * For each scope, the index of the target log column of the custom dimensions by their source index, eg
     * ['visit' => [1 => 3]] when the values of custom_dimension_1 go to custom_dimension_3. Null if the values stay in
     * their column, otherwise columns not holding a dimension of the source site are emptied.
     *
     * @var array<string, array<int, int>>|null
     */
    public $customDimensionIndexes;

    /**
     * The target IDs of the segments the migration added to the target site, as segments that select plugin data by ID
     * are changed once the plugin data is migrated, see PluginDataMigrator::pointSegmentsToTargetIds()
     *
     * @var array<int|string>
     */
    public $copiedSegmentIds = [];

    public function __construct()
    {
        $this->idMaps = new IdMaps();
    }

    public function hasDateRange(): bool
    {
        return $this->startDate !== null || $this->endDate !== null;
    }

    /**
     * @return array{string, string} the first and last second of the date range in UTC, as visits are stored in UTC
     */
    public function getUtcDateTimeRange(): array
    {
        $timezone = Site::getTimezoneFor($this->sourceIdSite);
        $start = $this->startDate === null ? '1991-08-06 00:00:00' : Date::factory($this->startDate)->setTimezone($timezone)->getDateStartUTC();
        $end = $this->endDate === null ? '2999-12-31 23:59:59' : Date::factory($this->endDate)->setTimezone($timezone)->getDateEndUTC();
        return [$start, $end];
    }
}
