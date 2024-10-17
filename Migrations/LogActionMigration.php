<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\Migrations;

use Piwik\Common;
use Piwik\Db;
use Piwik\Plugins\Migration\TargetDb;

class LogActionMigration
{
    private $sourceIdToTargetId = array();

    public function migrateAction($idAction, TargetDb $targetDb)
    {
        if (!$idAction) {
            return $idAction;
        }

        if (isset($this->sourceIdToTargetId[$idAction])) {
            return $this->sourceIdToTargetId[$idAction];
        }

        $row = Db::fetchRow('SELECT * FROM ' . Common::prefixTable('log_action') . ' WHERE idaction = ?', array($idAction));

        $this->sourceIdToTargetId[$idAction] = $this->findOrCreateActionOnTargetDB($targetDb, $row);
        return $this->sourceIdToTargetId[$idAction];
    }

    private function findOrCreateActionOnTargetDB(TargetDb $targetDb, $actionRow)
    {
        if (empty($actionRow)) {
            return;
        }

        $targetLogAction = $targetDb->prefixTable('log_action');
        $targetRow = $targetDb->fetchRow('SELECT idaction FROM ' . $targetLogAction . ' WHERE `hash` = ? and `type` = ?', array($actionRow['hash'], $actionRow['type']));
        if (!empty($targetRow)) {
            return $targetRow['idaction'];
        }

        unset($actionRow['idaction']);

        return $targetDb->insert('log_action', $actionRow);
    }
}
