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

class SiteUrlMigration extends BaseMigration
{
    public function validateStructure(TargetDb $targetDb)
    {
        return $this->checkTablesHaveSameStructure($targetDb, 'site_url');
    }

    public function migrate(Request $request, TargetDb $targetDb)
    {
        if (!$request->isExistingTargetSite) {
            $this->migrateEntities($request, $targetDb, 'site_url', 'site urls');
            return;
        }

        // the existing site keeps its URLs, the source URLs it doesn't have yet are added
        $targetUrls = $targetDb->fetchCol('SELECT url FROM ' . $targetDb->quoteTable('site_url') . ' WHERE idsite = ?', array($request->targetIdSite));
        $targetUrls[] = $targetDb->fetchOne('SELECT main_url FROM ' . $targetDb->quoteTable('site') . ' WHERE idsite = ?', array($request->targetIdSite));
        $sourceUrls = Db::fetchAll('SELECT url FROM ' . Common::prefixTable('site_url') . ' WHERE idsite = ?', array($request->sourceIdSite));
        $sourceUrls[] = Db::fetchRow('SELECT main_url AS url FROM ' . Common::prefixTable('site') . ' WHERE idsite = ?', array($request->sourceIdSite));

        // compared like the DB compares them, which ignores the case
        $targetUrls = array_map('mb_strtolower', array_filter($targetUrls));
        $numAdded = 0;
        foreach ($sourceUrls as $row) {
            if (!empty($row['url']) && !in_array(mb_strtolower($row['url']), $targetUrls, true)) {
                $targetDb->insert('site_url', array('idsite' => $request->targetIdSite, 'url' => $row['url']));
                $targetUrls[] = mb_strtolower($row['url']);
                $numAdded++;
            }
        }
        $this->log(sprintf('Added %s site urls the existing site did not have', $numAdded));
    }
}
