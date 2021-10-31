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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Migrate extends ConsoleCommand
{
    /**
     * @var OutputInterface
     */
    private $output;

    protected function configure()
    {
        $this->setName('migration:measurable');
        $this->setDescription('Migrates a measurable/website from one Matomo instance to another Matomo');

        $this->addOption('source-idsite', null,InputOption::VALUE_REQUIRED, 'Source Site ID you want to migrate');
        $this->addOption('target-db-host', null, InputOption::VALUE_REQUIRED, 'Target database host');
        $this->addOption('target-db-username', null, InputOption::VALUE_REQUIRED, 'Target database username');
        $this->addOption('target-db-password', null, InputOption::VALUE_OPTIONAL, 'Target database password');
        $this->addOption('target-db-name', null, InputOption::VALUE_REQUIRED, 'Target database name');
        $this->addOption('target-db-prefix', null, InputOption::VALUE_OPTIONAL, 'Target database table prefix', '');
        $this->addOption('target-db-port', null, InputOption::VALUE_REQUIRED, 'Target database port', '3306');
        $this->addOption('skip-logs', null, InputOption::VALUE_NONE, 'Skip migration of logs');
        $this->addOption('skip-archives', null, InputOption::VALUE_NONE, 'Skip migration of archives');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enable debug mode where it does not insert anything.');
        $this->addOption('disable-db-transactions', null, InputOption::VALUE_NONE, 'Disable the usage of MySQL database transactions');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->checkAllRequiredOptionsAreNotEmpty($input);
        $idSite = (int) $input->getOption('source-idsite');

        $this->checkSiteExists($idSite);
        $targetDb = $this->makeTargetDb($input);

        if ($input->getOption('disable-db-transactions')) {
            $targetDb->disableTransactions();
        }

        if ($input->getOption('dry-run')) {
            $output->writeln('Dry run is enabled. No entries will be written on the target DB.');
            $targetDb->enableDryRun();
        }

        $noInteraction = $input->getOption('no-interaction');
        if (!$noInteraction && !$this->confirmMigration($output, $idSite)) {
            return;
        }

        $this->output = $output;

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
    }

    public function logMessage($message)
    {
        if (defined('PIWIK_TEST_MODE') && PIWIK_TEST_MODE) {
            $now = '2019-01-10 02:48:01';
        } else {
            $now = Date::now()->getDatetime();
        }
        $this->output->writeln($message . ' at ' . $now);
    }

    private function checkSiteExists($idSite)
    {
        new Site($idSite);
    }

    /**
     * @param InputInterface $input
     * @return TargetDb
     */
    private function makeTargetDb(InputInterface $input)
    {
        return new TargetDb(array(
            'host' => $input->getOption('target-db-host'),
            'username' => $input->getOption('target-db-username'),
            'password' => $input->getOption('target-db-password'),
            'dbname' => $input->getOption('target-db-name'),
            'tables_prefix' => $input->getOption('target-db-prefix'),
            'port' => $input->getOption('target-db-port'),
        ));
    }

    private function confirmMigration(OutputInterface $output, $idSite)
    {
        $dialog = $this->getHelperSet()->get('dialog');
        return $dialog->askConfirmation(
            $output,
            '<question>Are you sure you want to migrate the data for idSite '.(int) $idSite.'. (y/N)</question>',
            false
        );
    }
}
