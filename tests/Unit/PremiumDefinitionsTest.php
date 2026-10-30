<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\tests\Unit;

use PHPUnit\Framework\TestCase;
use Piwik\DataTable\Row;
use Piwik\Plugins\Migration\Migrations\LogActionMigration;
use Piwik\Plugins\Migration\PluginData\BuiltInDefinitions;
use Piwik\Plugins\Migration\PluginData\IdMaps;
use Piwik\Plugins\Migration\PluginData\IdResolver;
use Piwik\Plugins\Migration\PluginData\PluginDataMigrator;
use Piwik\Plugins\Migration\PluginData\PluginDefinitions;
use Piwik\Plugins\Migration\TargetDb;

/**
 * Runs the shipped definitions without installing or activating any premium plugin or connecting to a database.
 *
 * @group Migration
 * @group PremiumDefinitionsTest
 * @group Plugins
 */
class PremiumDefinitionsTest extends TestCase
{
    private PluginDefinitions $definitions;
    private PluginDataMigrator $migrator;
    private IdMaps $maps;
    private IdResolver $resolver;
    private TargetDb $targetDb;

    protected function setUp(): void
    {
        $this->definitions = new PluginDefinitions();
        foreach (BuiltInDefinitions::get() as $definition) {
            $this->definitions->add($definition);
        }
        // IdResolver is final. Use the real resolver with in-memory IDs and stub its database collaborators.
        $this->migrator = $this->getMockBuilder(PluginDataMigrator::class)
            ->setConstructorArgs([$this->definitions])
            ->onlyMethods(['getDefinitionsToMigrate', 'getSharedTable'])
            ->getMock();
        $this->migrator->method('getDefinitionsToMigrate')->willReturn($this->definitions->getAll());
        $this->migrator->method('getSharedTable')->willReturn(null);
        $this->maps = new IdMaps();
        $targetIds = [
            'site' => 101, 'goal' => 102, 'custom_dimensions' => 103, 'custom_metrics' => 104,
            'custom_reports' => 105, 'funnel' => 106, 'site_form' => 107, 'experiments' => 108,
            'experiments_variations' => 109, 'site_hsr' => 110, 'log_crash' => 111,
        ];
        foreach ($targetIds as $table => $targetId) {
            $this->maps->set($table, 7, $targetId);
        }
        $this->targetDb = $this->createMock(TargetDb::class);
        $this->resolver = new IdResolver($this->maps, $this->migrator, $this->createMock(LogActionMigration::class), $this->targetDb);
    }

    /**
     * @dataProvider getTransformCases
     */
    public function test_registeredTransform(string $plugin, string $table, string $column, $source, $expected, array $row = []): void
    {
        $transform = $this->getTransform($plugin, $table, $column);
        $this->assertSame($expected, $transform($source, $row, $this->resolver));
    }

    public static function getTransformCases(): array
    {
        return [
            'AB success metrics' => ['AbTesting', 'experiments', 'success_metrics',
                '[{"metric":"nb_conversions_goal_7","other":1},{"metric":"nb_visits"},null]',
                '[{"metric":"nb_conversions_goal_102","other":1},{"metric":"nb_visits"},null]'],
            'AB malformed JSON' => ['AbTesting', 'experiments', 'success_metrics', '{invalid', '{invalid'],
            'AB unchanged formatting' => ['AbTesting', 'experiments', 'success_metrics', '[ {"metric": "nb_visits"} ]', '[ {"metric": "nb_visits"} ]'],
            'AB goal conversions' => ['AbTesting', 'experiments_strategy', 'metric', 'nb_conversions_goal_7', 'nb_conversions_goal_102'],
            'AB goal revenue' => ['AbTesting', 'experiments_strategy', 'metric', 'nb_revenue_goal_7', 'nb_revenue_goal_102'],
            'AB average conversions' => ['AbTesting', 'experiments_strategy', 'metric', 'avg_nb_conversions_goal_7', 'avg_nb_conversions_goal_102'],
            'AB average revenue' => ['AbTesting', 'experiments_strategy', 'metric', 'avg_nb_revenue_goal_7', 'avg_nb_revenue_goal_102'],
            'AB missing goal keeps ID' => ['AbTesting', 'experiments_strategy', 'metric', 'nb_conversions_goal_99', 'nb_conversions_goal_99'],
            'AB non-goal metric' => ['AbTesting', 'experiments_strategy', 'metric', 'nb_visits', 'nb_visits'],
            'AB null metric' => ['AbTesting', 'experiments_strategy', 'metric', null, null],
            'funnel goal pattern' => ['Funnels', 'funnel_steps', 'pattern', '7', '102', ['pattern_type' => 'goal_equals']],
            'funnel numeric goal pattern' => ['Funnels', 'funnel_steps', 'pattern', 7, '102', ['pattern_type' => 'goal_equals']],
            'funnel URL pattern stays literal' => ['Funnels', 'funnel_steps', 'pattern', '7', '7', ['pattern_type' => 'url_equals']],
            'funnel non-ID pattern' => ['Funnels', 'funnel_steps', 'pattern', '/page/7', '/page/7', ['pattern_type' => 'goal_equals']],
            'funnel null pattern' => ['Funnels', 'funnel_steps', 'pattern', null, null, ['pattern_type' => 'goal_equals']],
            'report dimensions' => ['CustomReports', 'custom_reports', 'dimensions',
                '["CustomDimension.CustomDimension7","Actions.ActionUrl",null]',
                '["CustomDimension.CustomDimension103","Actions.ActionUrl",null]'],
            'report malformed dimensions' => ['CustomReports', 'custom_reports', 'dimensions', '[invalid', '[invalid'],
            'report empty dimensions' => ['CustomReports', 'custom_reports', 'dimensions', '', ''],
            'report metrics and deleted custom metric' => ['CustomReports', 'custom_reports', 'metrics',
                '["goal_7_conversion","nb_uniq_customdimension_customdimension7","custom_metric_7","custom_metric_99","nb_visits",null]',
                '["goal_102_conversion","nb_uniq_customdimension_customdimension103","custom_metric_104","nb_visits",null]'],
            'report malformed metrics' => ['CustomReports', 'custom_reports', 'metrics', 'not-json', 'not-json'],
            'report null metrics' => ['CustomReports', 'custom_reports', 'metrics', null, null],
            'formula only changes migrated site' => ['CustomReports', 'custom_metrics', 'formula',
                '{{site7_goal_7_conversion}} + {{site7_nb_uniq_customdimension_customdimension7}} + {{site99_goal_7_conversion}} + {{nb_visits}}',
                '{{site101_goal_102_conversion}} + {{site101_nb_uniq_customdimension_customdimension103}} + {{site99_goal_7_conversion}} + {{nb_visits}}'],
            'formula null' => ['CustomReports', 'custom_metrics', 'formula', null, null],
            'multiple websites become site specific' => ['CustomReports', 'custom_reports', 'multiple_idsites', '7,99', ''],
            'nullable website list' => ['CustomReports', 'custom_reports', 'multiple_idsites', null, null],
            'report segment includes premium and core IDs' => ['CustomReports', 'custom_reports', 'segment_filter',
                'visitConvertedGoalId==7;dimension7==yes;funnels_name==7;form_name==7;crashId==7',
                'visitConvertedGoalId==102;dimension103==yes;funnels_name==106;form_name==107;crashId==111'],
            'encoded report segment' => ['CustomReports', 'custom_reports', 'segment_filter',
                'funnels_name%3D%3D7%3Bdimension7%3D%3Dyes', 'funnels_name%3D%3D106%3Bdimension103%3D%3Dyes'],
            'empty report segment' => ['CustomReports', 'custom_reports', 'segment_filter', '', ''],
            'report on funnel page' => ['CustomReports', 'custom_reports', 'subcategory', '7', '106', ['category' => 'Funnels_Funnels']],
            'report on heatmap page' => ['CustomReports', 'custom_reports', 'subcategory', '7', '110', ['category' => 'HeatmapSessionRecording_Heatmaps']],
            'report on recording page' => ['CustomReports', 'custom_reports', 'subcategory', '7', '110', ['category' => 'HeatmapSessionRecording_SessionRecordings']],
            'report on form page' => ['CustomReports', 'custom_reports', 'subcategory', '7', '107', ['category' => 'FormAnalytics_Forms']],
            'report on experiment page' => ['CustomReports', 'custom_reports', 'subcategory', '7', '108', ['category' => 'AbTesting_Experiments']],
            'deleted premium page' => ['CustomReports', 'custom_reports', 'subcategory', '99', '', ['category' => 'Funnels_Funnels']],
            'own report page is deferred' => ['CustomReports', 'custom_reports', 'subcategory', '7', '7', ['category' => 'CustomReports_CustomReports']],
            'static reporting page' => ['CustomReports', 'custom_reports', 'subcategory', 'General_Overview', 'General_Overview', ['category' => 'General_Visitors']],
            'advertising goals and segment' => ['AdvertisingConversionExport', 'site_conversion_export', 'parameters',
                '{"goals":[{"idgoal":7,"name":"sale"},{"idgoal":"7"},{"idgoal":-1},null],"segment":"visitConvertedGoalId==7;form_name==7","currency":"EUR"}',
                '{"goals":[{"idgoal":102,"name":"sale"},{"idgoal":"102"},{"idgoal":-1},null],"segment":"visitConvertedGoalId==102;form_name==107","currency":"EUR"}'],
            'advertising malformed JSON' => ['AdvertisingConversionExport', 'site_conversion_export', 'parameters', '{invalid', '{invalid'],
            'advertising unchanged formatting' => ['AdvertisingConversionExport', 'site_conversion_export', 'parameters', '{ "currency": "EUR" }', '{ "currency": "EUR" }'],
        ];
    }

    /**
     * @dataProvider getDestinationSiteIdTypes
     */
    public function test_formulaRejectsForeignWebsiteThatAliasesDestination($targetId): void
    {
        $this->maps->set('site', 7, $targetId);
        $transform = $this->getTransform('CustomReports', 'custom_metrics', 'formula');

        // Source site 101 is foreign to source site 7. Keeping 101 after migration would
        // make its formerly unavailable operand read destination site 101's goal instead.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('custom metric 19: its formula references unrelated source website 101');
        $transform('{{site101_goal_7_conversion}} / {{nb_visits}}', ['idcustommetric' => 19], $this->resolver);
    }

    public static function getDestinationSiteIdTypes(): array
    {
        return ['integer' => [101], 'database string' => ['101']];
    }

    /**
     * @dataProvider getTokenCollisionCases
     */
    public function test_exportTokenIsReplacedOnlyOnCollision(bool $alreadyUsed): void
    {
        $token = 'synthetic-test-token';
        $this->targetDb->expects($this->once())->method('quoteTable')->with('site_conversion_export')->willReturn('`target_site_conversion_export`');
        $this->targetDb->expects($this->once())->method('fetchOne')
            ->with('SELECT 1 FROM `target_site_conversion_export` WHERE access_token = ?', [$token])
            ->willReturn($alreadyUsed ? 1 : false);
        $transform = $this->getTransform('AdvertisingConversionExport', 'site_conversion_export', 'access_token');
        $result = $transform($token, [], $this->resolver);
        if ($alreadyUsed) {
            $this->assertNotSame($token, $result);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $result);
        } else {
            $this->assertSame($token, $result);
        }
    }

    public static function getTokenCollisionCases(): array
    {
        return [[false], [true]];
    }

    /**
     * @dataProvider getArchiveNames
     */
    public function test_archiveNamesUseDestinationIds(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->migrator->getTargetArchiveRecordName($source, $this->maps));
        $this->assertNull($this->migrator->getTargetArchiveRecordName($source, new IdMaps()));
    }

    public static function getArchiveNames(): array
    {
        return [
            ['AbTesting_experiment_7_sample_nb_revenue_goal_1', 'AbTesting_experiment_108_sample_nb_revenue_goal_1'],
            ['AbTesting_estimated_unique_visitors_bucket_data_7', 'AbTesting_estimated_unique_visitors_bucket_data_108'],
            ['Funnels_entries_7', 'Funnels_entries_106'],
            ['Funnels_funnel_nb_conversions_7_2', 'Funnels_funnel_nb_conversions_106_2'],
            ['FormAnalytics_fields_7', 'FormAnalytics_fields_107'],
            ['FormAnalytics_nb_form_views_7', 'FormAnalytics_nb_form_views_107'],
            ['CustomReports_customreport_7_1', 'CustomReports_customreport_105_1'],
        ];
    }

    /**
     * @dataProvider getReportIds
     */
    public function test_reportIdsUseDestinationIds(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->resolver->resolveReport($source));
        $this->assertNull($this->resolver->resolveReport(str_replace('--7', '--99', $source)));
    }

    public static function getReportIds(): array
    {
        return [
            ['AbTesting_getExperiment_idExperiment--7', 'AbTesting_getExperiment_idExperiment--108'],
            ['Funnels_getFunnelFlow_idFunnel--7', 'Funnels_getFunnelFlow_idFunnel--106'],
            ['FormAnalytics_get_idForm--7', 'FormAnalytics_get_idForm--107'],
            ['CustomReports_getCustomReport_idCustomReport--7', 'CustomReports_getCustomReport_idCustomReport--105'],
        ];
    }

    public function test_archiveRowsRemapIdsAndOmitUnmigratedReferences(): void
    {
        $abRows = [
            [Row::COLUMNS => ['label' => '7_12', 'nb_visits' => 5]],
            [Row::COLUMNS => ['label' => '99_12', 'nb_visits' => 6]],
            [Row::COLUMNS => ['label' => '0', 'nb_visits' => 7]],
            -1 => [Row::COLUMNS => ['label' => '7', 'nb_visits' => 18]],
        ];
        $expected = $abRows;
        $expected[0][Row::COLUMNS]['label'] = '109_12';
        unset($expected[1]);
        $this->assertSame(serialize($expected), $this->migrator->getTargetArchiveBlobValue('AbTesting_experiment_7', serialize($abRows), $this->maps));

        $crashRows = [[Row::COLUMNS => ['label' => 'Error', 'idlogcrash' => 7, 'nb_visits' => 3]]];
        $expected = [[Row::COLUMNS => ['label' => 'Error', 'idlogcrash' => 111, 'nb_visits' => 3]]];
        $this->assertSame(serialize($expected), $this->migrator->getTargetArchiveBlobValue('CrashAnalytics_Crashes', serialize($crashRows), $this->maps));

        $reportRows = [[Row::COLUMNS => ['label' => 'page', 'custom_metric_7' => 42, 'custom_metric_99' => 99, 'nb_visits' => 3]]];
        $expected = [[Row::COLUMNS => ['label' => 'page', 'custom_metric_104' => 42, 'nb_visits' => 3]]];
        $this->assertSame(serialize($expected), $this->migrator->getTargetArchiveBlobValue('CustomReports_customreport_7_1', serialize($reportRows), $this->maps));
    }

    private function getTransform(string $plugin, string $table, string $column): callable
    {
        foreach ($this->definitions->get($plugin)->getTables() as $definition) {
            if ($definition->getTable() === $table) {
                return $definition->getTransforms()[$column];
            }
        }
        $this->fail('No registered transform for ' . $plugin . '.' . $table . '.' . $column);
    }
}
