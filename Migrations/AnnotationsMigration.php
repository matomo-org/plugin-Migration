<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\Migrations;

use Piwik\Common;
use Piwik\Option;
use Piwik\Plugins\Annotations\AnnotationList;
use Piwik\Plugins\Migration\TargetDb;

class AnnotationsMigration extends BaseMigration
{
    /**
     * @param TargetDb $targetDb
     * @return array
     */
    public function validateStructure(TargetDb $targetDb)
    {
        // since Matomo 5.5.0, annotations are stored in their own table
        // and the AnnotationList class had been removed
        if (!class_exists(AnnotationList::class)) {
            return $this->checkTablesHaveSameStructure($targetDb, 'annotations');
        }

        return [];
    }

    /**
     * @param Request $request
     * @param TargetDb $targetDb
     * @return void
     */
    public function migrate(Request $request, TargetDb $targetDb)
    {
        // since Matomo 5.5.0, annotations are stored in their own table
        // and the AnnotationList class had been removed
        if (!class_exists(AnnotationList::class)) {
            $this->migrateEntities($request, $targetDb, 'annotations', 'annotations', 'id');

            return;
        }

        // before Matomo 5.5.0, annotations were stored in the options table
        $sourceName = AnnotationList::getAnnotationCollectionOptionName($request->sourceIdSite);
        $targetName = AnnotationList::getAnnotationCollectionOptionName($request->targetIdSite);

        $annotations = Option::get($sourceName);
        if ($annotations) {
            $annotationItems = Common::safe_unserialize($annotations) ?: [];
            $this->log(sprintf('Found %d annotations', count($annotationItems)));

            $targetDb->insert('option', array(
                'option_name' => $targetName,
                'option_value' => $annotations,
                'autoload' => 0
            ));
        }
    }
}
