<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\Migrations;

use Piwik\Plugins\Migration\TargetDb;

class CustomDimensionMigration extends BaseMigration
{
    public function validateStructure(TargetDb $targetDb)
    {
        return $this->checkTablesHaveSameStructure($targetDb, 'custom_dimensions');
    }

    public function migrate(Request $request, TargetDb $targetDb)
    {
        $this->migrateEntities($request, $targetDb, 'custom_dimensions', 'custom dimensions');
    }
}
