<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\Migrations;

use Piwik\Plugins\Migration\PluginData\IdMaps;

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
     * The target ID of each migrated row, for the tables other rows may reference
     *
     * @var IdMaps
     */
    public $idMaps;

    /**
     * @var array<int|string> target IDs of copied segments whose plugin references are translated afterwards
     */
    public $copiedSegmentIds = [];

    public function __construct()
    {
        $this->idMaps = new IdMaps();
    }
}
