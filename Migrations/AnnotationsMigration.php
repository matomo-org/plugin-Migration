<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\Migrations;

use Piwik\Option;
use Piwik\Plugins\Annotations\AnnotationList;
use Piwik\Plugins\Migration\TargetDb;

class AnnotationsMigration extends BaseMigration
{
    public function validateStructure(TargetDb $targetDb)
    {
        return array();
    }

    public function migrate(Request $request, TargetDb $targetDb)
    {
        $sourceName = AnnotationList::getAnnotationCollectionOptionName($request->sourceIdSite);
        $targetName = AnnotationList::getAnnotationCollectionOptionName($request->targetIdSite);

        $annotations = Option::get($sourceName);
        if ($annotations) {
            $this->log('Found annotations');

            $targetDb->insert('option', array(
                'option_name' => $targetName,
                'option_value' => $annotations,
                'autoload' => 0
            ));
        }
    }
}
