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

    private function migrateAnnotationsIntoExistingSite(Request $request, TargetDb $targetDb): void
    {
        $annotations = \Piwik\Db::fetchAll('SELECT * FROM ' . Common::prefixTable('annotations') . ' WHERE idsite = ?', array($request->sourceIdSite));
        $numAdded = 0;
        foreach ($annotations as $annotation) {
            $exists = $targetDb->fetchOne(
                'SELECT 1 FROM ' . $targetDb->quoteTable('annotations') . ' WHERE idsite = ? AND `date` = ? AND note = ?',
                array($request->targetIdSite, $annotation['date'], $annotation['note'])
            );
            if (!$exists) {
                unset($annotation['id']);
                $annotation['idsite'] = $request->targetIdSite;
                $targetDb->insert('annotations', $annotation);
                $numAdded++;
            }
        }
        $this->log(sprintf('Found %s annotations, added %s annotations the existing site did not have', count($annotations), $numAdded));
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
            if ($request->isExistingTargetSite) {
                $this->migrateAnnotationsIntoExistingSite($request, $targetDb);
            } else {
                $this->migrateEntities($request, $targetDb, 'annotations', 'annotations', 'id');
            }

            return;
        }

        if ($request->isExistingTargetSite) {
            throw new \Exception('Migrating into an existing site requires Matomo 5.5.0 or newer.');
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
