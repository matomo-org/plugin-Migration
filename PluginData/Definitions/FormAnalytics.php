<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\PluginData\Definitions;

use Piwik\Plugins\Migration\PluginData\PluginDefinition;
use Piwik\Plugins\Migration\PluginData\TableDefinition;

/**
 * The forms of the site, configured or found by the tracker, and how the visits interacted with them: the views of a
 * form by a visit, per page, and per field.
 */
final class FormAnalytics
{
    public static function get(): PluginDefinition
    {
        $definition = new PluginDefinition('FormAnalytics');

        // the names of the forms of a site are unique
        $definition->addTable(TableDefinition::forSite('site_form')
            ->autoIncrement('idsiteform')
            ->identifiedBy('name'));
        // the views of a form are identified by IDs the tracker generates
        $definition->addTable(TableDefinition::forVisit('log_form')
            ->autoIncrement('idlogform')
            ->references('idsiteform', 'site_form')
            ->copies('first_idformview', 'last_idformview'));
        $definition->addTable(TableDefinition::childOf('log_form_page', 'log_form', 'idlogform')
            ->autoIncrement('idlogformpage'));
        $definition->addTable(TableDefinition::childOf('log_form_field', 'log_form', 'idlogform')
            ->references('idlogformpage', 'log_form_page')
            ->copies('idformview', 'idpageview'));

        // eg FormAnalytics_fields_3 or FormAnalytics_nb_form_views_3, without ID for all forms
        $definition->renamesArchiveRecords('/^FormAnalytics_[a-z_]*?_(\d+)(?=_|$)/', 'site_form');
        $definition->identifiesReportsBy('idForm', 'site_form');
        // eg the visits of a form shown from its report, selected by the form ID
        $definition->identifiesSegmentsBy('form_name', 'site_form');
        $definition->identifiesSubcategoriesBy('FormAnalytics_Forms', 'site_form');

        return $definition;
    }
}
