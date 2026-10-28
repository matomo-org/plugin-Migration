<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\PluginData;

use Piwik\Piwik;

/**
 * The definitions of the plugin data that can be migrated, one per plugin.
 */
final class PluginDefinitions
{
    /**
     * @var array<string, PluginDefinition>
     */
    private $definitions = [];

    /**
     * Adds the definition of a plugin, replacing any definition added before for the same plugin.
     */
    public function add(PluginDefinition $definition): void
    {
        $this->definitions[$definition->getPluginName()] = $definition;
    }

    public function get(string $pluginName): ?PluginDefinition
    {
        return $this->definitions[$pluginName] ?? null;
    }

    /**
     * @return PluginDefinition[]
     */
    public function getAll(): array
    {
        return array_values($this->definitions);
    }

    /**
     * Collects the definitions shipped with this plugin and the ones of other plugins.
     */
    public static function collect(): self
    {
        $definitions = new self();
        foreach (BuiltInDefinitions::get() as $definition) {
            $definitions->add($definition);
        }

        /**
         * Triggered to collect which plugin data is migrated along with a site by the migration:measurable command.
         * Plugins storing site data in their own tables can describe these tables here, so their data is migrated
         * too. A definition added for a plugin replaces the one this plugin may ship for it.
         *
         * **Example**
         *
         *     public function addPluginDefinitions(PluginDefinitions $definitions)
         *     {
         *         $definition = new PluginDefinition('MyPlugin');
         *         $definition->addTable(TableDefinition::forSite('my_plugin_item')->autoIncrement('iditem')->identifiedBy('name'));
         *         $definition->addTable(TableDefinition::forVisit('log_my_plugin')->autoIncrement('idlogmyplugin')
         *             ->references('iditem', 'my_plugin_item'));
         *         $definition->renamesArchiveRecords('/^MyPlugin_item_(\d+)(?=_|$)/', 'my_plugin_item');
         *         $definition->identifiesReportsBy('idItem', 'my_plugin_item');
         *         $definition->identifiesSegmentsBy('myPluginItem', 'my_plugin_item');
         *         $definition->identifiesSubcategoriesBy('MyPlugin_Items', 'my_plugin_item');
         *         $definitions->add($definition);
         *     }
         *
         * @param PluginDefinitions $definitions
         */
        Piwik::postEvent('Migration.addPluginDefinitions', [$definitions]);

        return $definitions;
    }
}
