<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\Commands;

use Piwik\Date;
use Piwik\Db;
use Piwik\Period\Factory as PeriodFactory;
use Piwik\Plugin\ConsoleCommand;
use Piwik\Plugins\Migration\Migrations;
use Piwik\Plugins\Migration\Migrations\Provider;
use Piwik\Plugins\Migration\Migrations\Request;
use Piwik\Plugins\Migration\TargetDb;
use Piwik\Site;

class Migrate extends ConsoleCommand
{
    protected function configure()
    {
        $this->setName('migration:measurable');
        $this->setDescription('Migrates a measurable/website from one Matomo instance to another Matomo');

        $this->addRequiredValueOption('source-idsite', null, 'Source Site ID you want to migrate');
        $this->addRequiredValueOption('target-db-host', null, 'Target database host');
        $this->addRequiredValueOption('target-db-username', null, 'Target database username');
        $this->addOptionalValueOption('target-db-password', null, 'Target database password');
        $this->addRequiredValueOption('target-db-name', null, 'Target database name');
        $this->addOptionalValueOption('target-db-prefix', null, 'Target database table prefix', '');
        $this->addRequiredValueOption('target-db-port', null, 'Target database port', '3306');
        $this->addOptionalValueOption('target-db-ssl-ca', null, 'The path name to the certificate authority file.', '/etc/ssl/certs/cert.pem');
        $this->addNoValueOption('target-db-enable-ssl', null, 'Used for establishing secure connections using SSL with target database host.');
        $this->addNoValueOption('target-db-ssl-no-verify', null, 'Disable server certificate validation of the target database host.');
        $this->addRequiredValueOption('target-idsite', null, 'ID of an existing site of the target Matomo to migrate the data into, instead of creating a new site, eg the site of Matomo for WordPress');
        $this->addNoValueOption('target-site-is-offline', null, 'Confirm that tracking and archiving for --target-idsite are stopped and will remain stopped throughout the migration. Required to copy archives into a compatible empty existing site.');
        $this->addRequiredValueOption('start-date', null, 'Only migrate the visits and reports from this day on, in the timezone of the site, eg "2024-01-01"');
        $this->addRequiredValueOption('end-date', null, 'Only migrate the visits and reports up to this day, in the timezone of the site, eg "2024-12-31"');
        $this->addNoValueOption('skip-logs', null, 'Skip migration of logs');
        $this->addNoValueOption('skip-archives', null, 'Skip migration of archives');
        $this->addRequiredValueOption('skip-plugin-data', null, 'Comma separated list of plugins whose data should not be migrated, eg "HeatmapSessionRecording,Funnels"');
        $this->addNoValueOption('dry-run', null, 'Enable debug mode where it does not insert anything.');
        $this->addNoValueOption('disable-db-transactions', null, 'Disable the usage of MySQL database transactions');
    }

    protected function doExecute(): int
    {
        $input = $this->getInput();
        $output = $this->getOutput();
        $this->checkRequiredOptions();
        $idSite = (int) $input->getOption('source-idsite');

        $this->checkSiteExists($idSite);
        $targetDb = $this->makeTargetDb();
        $this->checkSourceAndTargetAreDifferent($targetDb);

        if ($input->getOption('disable-db-transactions')) {
            $targetDb->disableTransactions();
        }

        if ($input->getOption('dry-run')) {
            $output->writeln('Dry run is enabled. No entries will be written on the target DB.');
            $targetDb->enableDryRun();
        }

        $errors = array();

        $migrationsProvider = new Provider();
        $skipPluginData = array_values(array_filter(array_map('trim', explode(',', (string) $input->getOption('skip-plugin-data')))));
        $allMigrations = $migrationsProvider->getAllMigrations($input->getOption('skip-logs'), $input->getOption('skip-archives'), $skipPluginData);
        foreach ($allMigrations as $migration) {
            $errors = array_merge($errors, $migration->validateStructure($targetDb));
            $migration->onLog(array($this, 'logMessage'));
        }

        if (!empty($errors)) {
            foreach ($errors as $error) {
                $output->writeln('<error>' . $error . '</error>');
            }
            throw new \Exception('Please make sure both Matomo instances are on the same version and have the same plugins and plugin versions installed.');
        }

        $request = new Request();
        $request->sourceIdSite = $idSite;
        $request->startDate = $this->getDateOption('start-date');
        $request->endDate = $this->getDateOption('end-date');
        if ($request->startDate !== null && $request->endDate !== null && $request->startDate > $request->endDate) {
            throw new \Exception('The --start-date must not be after the --end-date.');
        }
        $targetIdSite = $input->getOption('target-idsite');
        $request->targetSiteIsOffline = (bool) $input->getOption('target-site-is-offline');
        if ($request->targetSiteIsOffline && ($targetIdSite === null || $targetIdSite === false || $targetIdSite === '')) {
            throw new \InvalidArgumentException('The --target-site-is-offline option requires --target-idsite.');
        }
        if ($targetIdSite !== null && $targetIdSite !== false && $targetIdSite !== '') {
            if (!ctype_digit((string) $targetIdSite) || (int) $targetIdSite < 1) {
                throw new \InvalidArgumentException('The --target-idsite must be a positive integer.');
            }
            $this->useExistingTargetSite($request, $targetDb, (int) $targetIdSite);
        }

        $warnings = $this->getWarnings($request, $targetDb);
        foreach ($allMigrations as $migration) {
            $warnings = array_merge($warnings, $migration->getWarnings($request, $targetDb));
        }
        foreach ($warnings as $warning) {
            $output->writeln('<comment>Warning: ' . $warning . '</comment>');
        }

        $noInteraction = $input->getOption('no-interaction');
        if (!$noInteraction && !$this->confirmMigration($idSite)) {
            return self::SUCCESS;
        }

        $migrations = new Migrations();
        if ($input->getOption('dry-run')) {
            $migrations->enableDryRun();
        }
        $migrations->onLog(array($this, 'logMessage'));
        $migrations->migrate($allMigrations, $request, $targetDb);

        if (($request->archivesNotCopiedReason !== null || $input->getOption('skip-archives')) && !$input->getOption('skip-logs')) {
            [$firstDay, $lastDay] = $this->getDaysOfMigratedVisits($request, $targetDb);
            if ($firstDay !== null) {
                $this->logMessage(sprintf(
                    'Note: The reports were not copied. To process the reports of the migrated visits on the target Matomo, execute there '
                    . '"./console core:invalidate-report-data --sites=%s --dates=%s,%s" and "./console core:archive". '
                    . 'Before rebuilding, ensure the destination has the retained raw data from both instances for the affected periods. '
                    . 'Rebuilding can replace existing historical totals with incomplete data if either instance purged its raw data.',
                    $request->targetIdSite,
                    $firstDay,
                    $lastDay
                ));
                $this->explainRetentionLimit(false);
            } else {
                $this->logMessage('Note: The reports were not copied and there are no source visits in the selected period to rebuild them. '
                    . 'Historical reports whose raw data was deleted cannot be merged into this destination.');
            }
        } elseif ($request->hasDateRange() && !$input->getOption('skip-archives')) {
            $this->explainDateRangeReports($request, $targetDb);
        }
        return self::SUCCESS;
    }

    public function logMessage($message)
    {
        // @phpstan-ignore booleanAnd.rightAlwaysTrue (in production PIWIK_TEST_MODE is undefined and defined() short-circuits)
        if (defined('PIWIK_TEST_MODE') && PIWIK_TEST_MODE) {
            $now = '2019-01-10 02:48:01';
        } else {
            $now = Date::now()->getDatetime();
        }
        $this->getOutput()->writeln($message . ' at ' . $now);
    }

    private function checkRequiredOptions(): void
    {
        foreach ($this->getDefinition()->getOptions() as $option) {
            $name = $option->getName();
            $value = $this->getInput()->getOption($name);
            // These options require a value when supplied, but may be omitted entirely.
            if (in_array($name, ['skip-plugin-data', 'target-idsite', 'start-date', 'end-date'], true) && $value === null) {
                continue;
            }
            if ($option->isValueRequired() && ($value === null || $value === false || $value === '')) {
                throw new \InvalidArgumentException(sprintf('The required option --%s is not set', $name));
            }
        }
    }

    private function checkSiteExists($idSite)
    {
        new Site($idSite);
    }

    /**
     * @return TargetDb
     */
    private function makeTargetDb()
    {
        $input = $this->getInput();
        return new TargetDb(array(
            'host' => $input->getOption('target-db-host'),
            'username' => $input->getOption('target-db-username'),
            'password' => $input->getOption('target-db-password'),
            'dbname' => $input->getOption('target-db-name'),
            'tables_prefix' => $input->getOption('target-db-prefix'),
            'port' => $input->getOption('target-db-port'),
            'enable_ssl' => $input->getOption('target-db-enable-ssl'),
            'ssl_ca' => $input->getOption('target-db-ssl-ca'),
            'ssl_no_verify' => $input->getOption('target-db-ssl-no-verify'),
        ));
    }

    private function confirmMigration($idSite): bool
    {
        return $this->askForConfirmation('<question>Are you sure you want to migrate the data for idSite ' . (int) $idSite . '. (y/N)</question>', false);
    }

    private function useExistingTargetSite(Request $request, TargetDb $targetDb, int $targetIdSite): void
    {
        $targetSite = $targetDb->fetchRow('SELECT * FROM ' . $targetDb->quoteTable('site') . ' WHERE idsite = ?', [$targetIdSite]);
        if (empty($targetSite)) {
            throw new \Exception(sprintf('The site %s given in --target-idsite does not exist in the target Matomo.', $targetIdSite));
        }
        /** @var \Zend_Db_Adapter_Abstract $sourceDb Migration uses the regular DB adapter, outside the tracker. */
        $sourceDb = Db::get();
        $sourceType = Db::fetchOne('SELECT type FROM ' . $sourceDb->quoteIdentifier([\Piwik\Common::prefixTable('site')]) . ' WHERE idsite = ?', [$request->sourceIdSite]);
        if ($targetSite['type'] !== $sourceType) {
            throw new \InvalidArgumentException(sprintf(
                'The target site has type "%s", but the source site has type "%s". Choose an existing destination of the same type.',
                $targetSite['type'],
                $sourceType
            ));
        }

        $request->targetIdSite = $targetIdSite;
        $request->isExistingTargetSite = true;

        $sourceTimezone = Site::getTimezoneFor($request->sourceIdSite);
        if ($targetSite['timezone'] !== $sourceTimezone) {
            $request->archivesNotCopiedReason = sprintf('the target site has the timezone %s, the source site %s', $targetSite['timezone'], $sourceTimezone);
            return;
        }

        $hasVisits = $targetDb->fetchOne('SELECT 1 FROM ' . $targetDb->quoteTable('log_visit') . ' WHERE idsite = ? LIMIT 1', [$targetIdSite]);
        $hasArchives = false;
        $archivePattern = '/\A' . preg_quote($targetDb->prefixTable('archive_numeric_'), '/') . '[0-9]{4}_[0-9]{2}\z/';
        foreach ($targetDb->fetchCol('SHOW TABLES') as $archiveTable) {
            if (preg_match($archivePattern, $archiveTable) && $targetDb->fetchOne('SELECT 1 FROM ' . $targetDb->quoteIdentifier($archiveTable) . ' WHERE idsite = ? LIMIT 1', [$targetIdSite])) {
                $hasArchives = true;
                break;
            }
        }
        if ($hasVisits || $hasArchives) {
            $request->archivesNotCopiedReason = 'the target site has data already, which the copied reports would hide';
        } elseif (!$request->targetSiteIsOffline) {
            $request->archivesNotCopiedReason = 'copying reports into an empty existing site requires stopping destination tracking and archiving and confirming this with --target-site-is-offline';
        }
    }

    /** @return string[] */
    private function getWarnings(Request $request, TargetDb $targetDb): array
    {
        $warnings = [];

        if (!$request->isExistingTargetSite) {
            return $warnings;
        }

        $sourceSite = Db::fetchRow('SELECT * FROM `' . \Piwik\Common::prefixTable('site') . '` WHERE idsite = ?', [$request->sourceIdSite]);
        $targetSite = $targetDb->fetchRow('SELECT * FROM ' . $targetDb->quoteTable('site') . ' WHERE idsite = ?', [$request->targetIdSite]);
        if ($sourceSite['ecommerce'] != $targetSite['ecommerce'] || $sourceSite['currency'] !== $targetSite['currency']) {
            $warnings[] = sprintf(
                'The existing target site keeps its settings, but has other ecommerce settings (ecommerce %s, currency %s) than the source site (ecommerce %s, currency %s).',
                $targetSite['ecommerce'] ? 'on' : 'off',
                $targetSite['currency'],
                $sourceSite['ecommerce'] ? 'on' : 'off',
                $sourceSite['currency']
            );
        }

        // visits that were tracked into both Matomo, eg while switching the tracking code, would be in the target site twice
        [$start, $end] = $request->getUtcDateTimeRange();
        $source = Db::fetchRow(
            'SELECT MIN(visit_last_action_time) AS first, MAX(visit_last_action_time) AS last FROM ' . \Piwik\Common::prefixTable('log_visit')
            . ' WHERE idsite = ? AND visit_last_action_time BETWEEN ? AND ?',
            [$request->sourceIdSite, $start, $end]
        );
        if (!empty($source['first'])) {
            $overlap = $targetDb->fetchRow(
                'SELECT COUNT(*) AS num, MIN(visit_last_action_time) AS first FROM ' . $targetDb->quoteTable('log_visit') . ' WHERE idsite = ? AND visit_last_action_time BETWEEN ? AND ?',
                [$request->targetIdSite, $source['first'], $source['last']]
            );
            if (!empty($overlap['num'])) {
                $warnings[] = sprintf(
                    'The existing target site has %s visits in the period of the visits to migrate (%s to %s UTC), starting %s UTC. Visits tracked into both Matomo will be in the target site twice, use --end-date to only migrate the visits before.',
                    $overlap['num'],
                    $source['first'],
                    $source['last'],
                    $overlap['first']
                );
            }
        }

        return $warnings;
    }

    /** @return array{string|null, string|null} */
    private function getDaysOfMigratedVisits(Request $request, TargetDb $targetDb): array
    {
        $dateCondition = '';
        $bind = [$request->sourceIdSite];
        if ($request->hasDateRange()) {
            $dateCondition = ' AND visit_last_action_time BETWEEN ? AND ?';
            $bind = array_merge($bind, $request->getUtcDateTimeRange());
        }
        $range = Db::fetchRow(
            'SELECT MIN(visit_first_action_time) AS first, MAX(visit_last_action_time) AS last FROM ' . \Piwik\Common::prefixTable('log_visit')
            . ' WHERE idsite = ?' . $dateCondition,
            $bind
        );
        if (empty($range['first'])) {
            return [null, null];
        }
        // A dry run does not create the new target site; its timezone will be the source site's timezone.
        $timezone = $this->getInput()->getOption('dry-run') && !$request->isExistingTargetSite
            ? Site::getTimezoneFor($request->sourceIdSite)
            : $targetDb->fetchOne('SELECT timezone FROM ' . $targetDb->quoteTable('site') . ' WHERE idsite = ?', [$request->targetIdSite]);
        // factory() with a timezone converts the UTC time to this timezone
        return [
            Date::factory($range['first'], $timezone)->toString(),
            Date::factory($range['last'], $timezone)->toString(),
        ];
    }

    private function getDateOption(string $name): ?string
    {
        $value = $this->getInput()->getOption($name);
        if ($value === null || $value === false || $value === '') {
            return null;
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $value, $parts) || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new \Exception(sprintf('The --%s must be a date like 2024-01-31.', $name));
        }
        return (string) $value;
    }

    private function explainDateRangeReports(Request $request, TargetDb $targetDb): void
    {
        if ($this->getInput()->getOption('skip-logs')) {
            $this->logMessage('Note: Without the raw data, reports of periods only partly in the date range cannot be rebuilt on the target. '
                . 'Migrate the raw data too, or widen the date range to include complete report periods.');
            return;
        }

        // These options are validated website calendar dates, not UTC instants. Pass the strings to the
        // period factory: a timezone-adjusted Date can cross a day boundary inside period generation.
        $timezone = Site::getTimezoneFor($request->sourceIdSite);
        $earlierDay = null;
        $beforeStart = null;
        if ($request->startDate !== null) {
            [$firstDay] = $this->getDaysOfMigratedVisits($request, $targetDb);
            if ($firstDay !== null && $firstDay < $request->startDate) {
                $earlierDay = $firstDay;
                $beforeStart = Date::factory($request->startDate, 'UTC')->subDay(1)->toString();
                // Whole visits are copied, including actions before the range. Rebuild only the earlier daily
                // reports, as invalidating copied days could discard reports whose raw data has been deleted.
                $this->logMessage(sprintf(
                    'Note: Migrated visits have actions before --start-date. To process their daily reports on the target Matomo, execute there '
                    . '"./console core:invalidate-report-data --sites=%s --dates=%s,%s --periods=day" and "./console core:archive".',
                    $request->targetIdSite,
                    $firstDay,
                    $beforeStart
                ));
            }
        }

        // Invalidate only excluded periods. A range covering the entire import would also invalidate complete
        // copied months and years, whose raw data may have been purged on the source.
        $periodsByDates = [];
        if ($earlierDay !== null) {
            $periodsByDates[$earlierDay . ',' . $beforeStart] = ['week', 'month', 'year'];
        }
        foreach (['week', 'month', 'year'] as $label) {
            $partialPeriods = [];
            if ($request->startDate !== null && $earlierDay === null) {
                $start = PeriodFactory::build($label, $request->startDate, $timezone)->getDateStart()->toString();
                if ($start < $request->startDate) {
                    $partialPeriods[$start] = $request->startDate;
                }
            }
            if ($request->endDate !== null) {
                $period = PeriodFactory::build($label, $request->endDate, $timezone);
                $start = $period->getDateStart()->toString();
                if ($period->getDateEnd()->toString() > $request->endDate && ($beforeStart === null || $start > $beforeStart)) {
                    $partialPeriods[$start] = $request->endDate;
                }
            }
            foreach ($partialPeriods as $date) {
                $periodsByDates[$date][] = $label;
            }
        }
        foreach ($periodsByDates as $dates => $periods) {
            $this->logMessage(sprintf(
                'Note: To process excluded report periods on the target Matomo, execute there '
                . '"./console core:invalidate-report-data --sites=%s --dates=%s --periods=%s" and "./console core:archive". '
                . 'Complete copied periods are kept; excluded periods can only include retained raw data and copied subperiod reports.',
                $request->targetIdSite,
                $dates,
                implode(',', $periods)
            ));
        }
        if ($earlierDay !== null || $periodsByDates !== []) {
            $this->explainRetentionLimit(true);
        }
    }

    private function explainRetentionLimit(bool $hasCopiedReports): void
    {
        $this->logMessage('Note: Run the invalidation commands with -v to see dates skipped by the destination raw-data retention limit. '
            . 'If required dates are skipped, first verify that the destination retains the raw data needed to rebuild them'
            . ($hasCopiedReports ? ', or complete copied subperiod reports for the excluded aggregate periods' : ' from both instances')
            . '. Only then repeat the same invalidation commands with --ignore-log-deletion-limit, followed by "./console core:archive". '
            . 'The override does not recover deleted raw data.');
    }

    private function checkSourceAndTargetAreDifferent(TargetDb $targetDb): void
    {
        $source = [Db::fetchOne('SELECT DATABASE()'), \Piwik\Common::prefixTable('')];
        $target = [$targetDb->fetchOne('SELECT DATABASE()'), $targetDb->prefixTable('')];
        if (Db::fetchOne('SELECT @@lower_case_table_names')) {
            $source = array_map('strtolower', $source);
            $target = array_map('strtolower', $target);
        }
        if ($source !== $target) {
            return;
        }

        // Host names and ports may be aliases or tunnels to the same server. A connection-scoped named lock
        // identifies the server without modifying either database and is always released, including in dry runs.
        $lock = 'migration_source_' . bin2hex(random_bytes(16));
        if (!Db::getDbLock($lock, 1)) {
            throw new \Exception('Could not verify that the source and target databases are different.');
        }
        try {
            $sameServer = (string) $targetDb->fetchOne('SELECT IS_USED_LOCK(?)', [$lock]) === (string) Db::fetchOne('SELECT CONNECTION_ID()');
        } finally {
            Db::releaseDbLock($lock);
        }
        if ($sameServer) {
            throw new \Exception('The target database and table prefix are the same as the source ones. Please specify a different target database or --target-db-prefix.');
        }
    }
}
