<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\Commands;

use Piwik\Date;
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
        $this->addNoValueOption('skip-logs', null, 'Skip migration of logs');
        $this->addNoValueOption('skip-archives', null, 'Skip migration of archives');
        $this->addNoValueOption('dry-run', null, 'Enable debug mode where it does not insert anything.');
        $this->addNoValueOption('disable-db-transactions', null, 'Disable the usage of MySQL database transactions');
    }

    /**
     * @return int
     */

    protected function doExecute(): int
    {
        $input = $this->getInput();
        $output = $this->getOutput();
        $this->checkAllRequiredOptionsAreNotEmpty();
        $idSite = (int) $input->getOption('source-idsite');

        $this->checkSiteExists($idSite);
        $targetDb = $this->makeTargetDb();

        if ($input->getOption('disable-db-transactions')) {
            $targetDb->disableTransactions();
        }

        if ($input->getOption('dry-run')) {
            $output->writeln('Dry run is enabled. No entries will be written on the target DB.');
            $targetDb->enableDryRun();
        }

        $noInteraction = $input->getOption('no-interaction');
        if (!$noInteraction && !$this->confirmMigration($idSite)) {
            return self::SUCCESS;
        }

        $errors = array();

        $migrationsProvider = new Provider();
        $allMigrations = $migrationsProvider->getAllMigrations($input->getOption('skip-logs'), $input->getOption('skip-archives'));
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

        $migrations = new Migrations();
        if ($input->getOption('dry-run')) {
            $migrations->enableDryRun();
        }
        $migrations->onLog(array($this, 'logMessage'));
        $migrations->migrate($allMigrations, $request, $targetDb);

        return self::SUCCESS;
    }

    public function logMessage($message)
    {
        if (defined('PIWIK_TEST_MODE') && PIWIK_TEST_MODE) {
            $now = '2019-01-10 02:48:01';
        } else {
            $now = Date::now()->getDatetime();
        }
        $this->getOutput()->writeln($message . ' at ' . $now);
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
        ));
    }

    private function confirmMigration($idSite)
    {
        $dialog = $this->getHelperSet()->get('dialog');
        return $dialog->askConfirmation(
            $this->getOutput(),
            '<question>Are you sure you want to migrate the data for idSite '.(int) $idSite.'. (y/N)</question>',
            false
        );
    }
}
