<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\tests\Fixtures;

use Piwik\API\Request;
use Piwik\Cache;
use Piwik\Container\StaticContainer;
use Piwik\Date;
use Piwik\Db;
use Piwik\Plugin;
use Piwik\Plugins\SegmentEditor\API as SegmentApi;
use Piwik\Plugins\Migration\PluginData\BuiltInDefinitions;

/**
 * Adds data of the premium plugins that have a built-in definition, through their APIs and the tracker, for the
 * premium plugins that are installed. The test environment only loads the plugins of Matomo, so this fixture loads
 * the installed premium plugins. Environments without premium plugins, like the CI, only get the data of
 * MigrationFixture.
 *
 * The data of the migrated site is added after data of another site, so its IDs differ from the IDs it gets on the
 * target and a missing translation can't go unnoticed.
 */
class PremiumPluginsFixture extends MigrationFixture
{
    public const HSR_DATETIME = '2013-01-23 05:00:00';
    public const FORMS_DATETIME = '2013-01-23 07:00:00';
    public const AB_TESTING_DATETIME = '2013-01-23 09:00:00';
    public const MEDIA_DATETIME = '2013-01-23 11:00:00';
    public const CRASHES_DATETIME = '2013-01-23 13:00:00';
    public const ADS_DATETIME = '2013-01-23 15:00:00';

    public function __construct()
    {
        $this->extraPluginsToLoad = array_values(array_filter(array_merge(['CustomAlerts'], self::getPremiumPluginsWithDefinition()), function ($pluginName) {
            return is_file(Plugin\Manager::getPluginDirectory($pluginName) . '/plugin.json');
        }));
    }

    /**
     * @return string[]
     */
    public static function getPremiumPluginsWithDefinition(): array
    {
        $plugins = [];
        foreach (BuiltInDefinitions::get() as $definition) {
            $plugins[] = $definition->getPluginName();
        }
        return $plugins;
    }

    /**
     * @return string[] the premium plugins with a built-in definition that the test environment has loaded
     */
    public static function getActivatedPremiumPlugins(): array
    {
        return array_values(array_filter(self::getPremiumPluginsWithDefinition(), [self::class, 'isPluginActivated']));
    }

    public function setUp(): void
    {
        parent::setUp();

        $otherIdSite = self::createWebsite($this->dateTime, 0, 'Other site');
        $this->assertSame($this->idSite + 1, $otherIdSite);

        if (self::isPluginActivated('HeatmapSessionRecording')) {
            $this->setUpHeatmapSessionRecording();
        }
        if (self::isPluginActivated('Funnels')) {
            $this->setUpFunnels();
        }
        if (self::isPluginActivated('MultiChannelConversionAttribution')) {
            $this->setUpMultiChannelConversionAttribution();
        }
        if (self::isPluginActivated('FormAnalytics')) {
            $this->setUpFormAnalytics();
        }
        if (self::isPluginActivated('AbTesting')) {
            $this->setUpAbTesting();
        }
        if (self::isPluginActivated('MediaAnalytics')) {
            $this->setUpMediaAnalytics();
        }
        if (self::isPluginActivated('SEOWebVitals')) {
            $this->setUpSEOWebVitals();
        }
        if (self::isPluginActivated('CrashAnalytics')) {
            $this->setUpCrashAnalytics();
        }
        if (self::isPluginActivated('AdvertisingConversionExport')) {
            $this->setUpAdvertisingConversionExport();
        }
        if (self::isPluginActivated('CustomReports')) {
            $this->setUpCustomReports();
        }
        if (self::isPluginActivated('SearchEngineKeywordsPerformance')) {
            $this->setUpSearchEngineKeywordsPerformance();
        }
        // last, as it adds sites
        if (self::isPluginActivated('RollUpReporting')) {
            $this->setUpRollUpReporting();
        }
    }

    public static function isPluginActivated(string $pluginName): bool
    {
        return Plugin\Manager::getInstance()->isPluginActivated($pluginName);
    }

    private function setUpHeatmapSessionRecording(): void
    {
        $api = \Piwik\Plugins\HeatmapSessionRecording\API::getInstance();
        $model = StaticContainer::get(\Piwik\Plugins\HeatmapSessionRecording\Model\SiteHsrModel::class);
        $everyPage = [['attribute' => 'url', 'type' => 'any', 'value' => '', 'inverted' => 0]];
        $api->addHeatmap($this->idSite + 1, 'Other site heatmap', $everyPage, 1000, 100);

        $productsPage = [['attribute' => 'path', 'type' => 'equals_simple', 'value' => '/products', 'inverted' => 0]];
        $idHeatmap = $api->addHeatmap($this->idSite, 'Products heatmap', $productsPage, 1000, 100, '.cookie-banner', false, false, false, false, 'Clicks on the products', true);
        $idRecording = $api->addSessionRecording($this->idSite, 'All sessions', $everyPage, 500, 100, 0, false, true, 'Every session');
        $idDeletedHeatmap = $api->addHeatmap($this->idSite, 'Deleted heatmap', $everyPage, 1000, 100, false, false, false, false, false, '', true);

        // a pageview recorded by the heatmap and the session recording, with the DOM of the page as screenshot
        $this->trackHsrPageview('11.22.33.44', 0, 'hv0001', [$idHeatmap, $idRecording], [
            ['ty' => 8, 'id' => $idHeatmap, 'dom' => '{"rootId":1,"children":[{"tagName":"BODY"}]}'],
            ['ty' => 5, 'ti' => 0, 'te' => '{"rootId":1,"children":[{"tagName":"BODY","id":2}]}'],
            ['ty' => 1, 'ti' => 120, 's' => 'body > div#main', 'x' => 10, 'y' => 20],
            ['ty' => 2, 'ti' => 300, 's' => '#main > .button', 'x' => 30, 'y' => 40],
            ['ty' => 3, 'ti' => 500, 'x' => 0, 'y' => 250],
            ['ty' => 9, 'ti' => 700, 's' => 'form input[name=q]', 'te' => 'migration'],
        ]);
        // another pageview of the visit, only recorded by the session recording
        $this->trackHsrPageview('11.22.33.44', 0.1, 'hv0002', [$idRecording], [
            ['ty' => 5, 'ti' => 0, 'te' => '{"rootId":1,"children":[{"tagName":"BODY","id":3}]}'],
            ['ty' => 2, 'ti' => 200, 's' => '#main > .button', 'x' => 35, 'y' => 45],
        ]);
        // a pageview of another visit recorded by the heatmap that is deleted afterwards
        $this->trackHsrPageview('55.66.77.88', 0.2, 'hv0003', [$idDeletedHeatmap, $idRecording], [
            ['ty' => 5, 'ti' => 0, 'te' => '{"rootId":1,"children":[{"tagName":"BODY","id":2}]}'],
            ['ty' => 2, 'ti' => 100, 's' => '#main > .button', 'x' => 50, 'y' => 60],
        ]);

        // heatmaps repeating themselves are copied when they end, the copies reference them
        foreach ([$idHeatmap, $idDeletedHeatmap] as $idSiteHsr) {
            $api->endHeatmap($this->idSite, $idSiteHsr);
            $model->createRepeatCopy($this->idSite, $idSiteHsr);
        }
        $api->deleteHeatmap($this->idSite, $idDeletedHeatmap);

        // a text of an event the target has already, which is reused
        $blob = Db::fetchRow("SELECT `hash`, `compressed`, `value` FROM log_hsr_blob ORDER BY idhsrblob LIMIT 1");
        Db::get()->insert(self::TARGET_DB_PREFIX . 'log_hsr_blob', $blob);
    }

    private function setUpFunnels(): void
    {
        $api = \Piwik\Plugins\Funnels\API::getInstance();
        $step = function (string $name, string $patternType, string $pattern, int $required = 0) {
            return ['name' => $name, 'pattern_type' => $patternType, 'pattern' => $pattern, 'required' => $required];
        };
        $api->saveNonGoalFunnel($this->idSite + 1, 0, 'Other site funnel', [$step('Home', 'path_equals', '/'), $step('Page', 'path_contains', 'page')]);

        // the funnel of the goal converted by the first visit, which also visited these pages
        $api->setGoalFunnel($this->idSite, 1, true, [$step('Home', 'url_equals', 'http://example.com/'), $step('Sub page', 'path_contains', '/sub/page', 1)]);
        // a funnel whose last step is the conversion of a goal
        $idFunnel = $api->saveNonGoalFunnel($this->idSite, 0, 'Sub page to download', [$step('Sub page', 'path_contains', '/sub/page'), $step('Download', 'goal_equals', '1')]);
        $api->saveNonGoalFunnel($this->idSite, 0, 'Deleted funnel', [$step('Search', 'url_contains', 'search')]);
        $idDeletedFunnel = (int) Db::fetchOne("SELECT idfunnel FROM funnel WHERE name = 'Deleted funnel'");

        // the steps the visits went through are found when processing the reports again, with the funnels, which the
        // reports processed by the parent fixture in this request were processed without
        Cache::getTransientCache()->flushAll();
        Request::processRequest('CoreAdminHome.invalidateArchivedReports', ['idSites' => $this->idSite, 'dates' => '2013-01-23', 'period' => 'day']);
        foreach ([$idFunnel, $idDeletedFunnel] as $id) {
            Request::processRequest('Funnels.getFunnelFlow', ['idSite' => $this->idSite, 'period' => 'day', 'date' => '2013-01-23', 'idFunnel' => $id]);
        }
        Request::processRequest('Funnels.getFunnelFlow', ['idSite' => $this->idSite, 'period' => 'day', 'date' => '2013-01-23', 'idGoal' => 1]);
        $api->deleteNonGoalFunnel($this->idSite, $idDeletedFunnel);

        // the visits of a funnel, as its report links to them, and by name
        SegmentApi::getInstance()->add('Funnel visitors', 'funnels_name==' . $idFunnel, $this->idSite);
        SegmentApi::getInstance()->add('Funnel visitors by name', 'funnels_name==Sub page to download', $this->idSite);
    }

    private function setUpFormAnalytics(): void
    {
        $api = \Piwik\Plugins\FormAnalytics\API::getInstance();
        $contactForm = [['attribute' => 'form_name', 'pattern' => 'equals', 'value' => 'contact']];
        $api->addForm($this->idSite + 1, 'Other site contact form', '', $contactForm);
        // converts the goal "Opens Contact Form" when submitted
        $idForm = $api->addForm($this->idSite, 'Contact form', 'Asks for an email address', $contactForm, false, 'form_submit', false, 3);
        $api->addForm($this->idSite, 'Unused form', '', [['attribute' => 'form_name', 'pattern' => 'equals', 'value' => 'unused']]);

        // a visit filling in the contact form on a page and submitting it on another, and viewing a form that has no
        // configuration, which the tracker creates, on pages of their own like trackHsrPageview()
        $t = self::getTracker($this->idSite, self::FORMS_DATETIME, $defaultInit = true);
        $t->setIp('99.88.77.66');
        $field = function (string $name, int $size, int $timeSpent) {
            return ['fa_fn' => $name, 'fa_fs' => $size, 'fa_fb' => $size ? 0 : 1, 'fa_fts' => $timeSpent, 'fa_fht' => 100, 'fa_fch' => 1, 'fa_ff' => 1, 'fa_fd' => 0, 'fa_fcu' => 0];
        };
        $this->trackForm($t, 0, 'http://example.com/contact', 'fv0001', 'contact', ['fa_fv' => '1', 'fa_st' => '1', 'fa_ts' => '5000', 'fa_ht' => '800',
            'fa_ef' => 'email', 'fa_lf' => 'message', 'fa_fields' => json_encode([$field('email', 15, 3000), $field('message', 0, 0)])]);
        $this->trackForm($t, 0.1, 'http://example.com/contact/send', 'fv0001', 'contact', ['fa_su' => '1', 'fa_ts' => '9000', 'fa_tts' => '9000',
            'fa_fields' => json_encode([$field('email', 15, 3500), $field('message', 42, 4000)])]);
        $this->trackForm($t, 0.2, 'http://example.com/newsletter', 'fv0002', 'newsletter', ['fa_fv' => '1']);

        // processes the reports of the forms, see setUpFunnels()
        Cache::getTransientCache()->flushAll();
        Request::processRequest('CoreAdminHome.invalidateArchivedReports', ['idSites' => $this->idSite, 'dates' => '2013-01-23', 'period' => 'day']);
        Request::processRequest('FormAnalytics.get', ['idSite' => $this->idSite, 'period' => 'day', 'date' => '2013-01-23', 'idForm' => $idForm]);

        // the visits of a form, as its report links to them
        SegmentApi::getInstance()->add('Form visitors', 'form_name==' . $idForm . ';visitCount>=1', $this->idSite);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function trackForm(\MatomoTracker $t, float $hoursLater, string $url, string $idFormView, string $formName, array $parameters): void
    {
        $t->setForceVisitDateTime(Date::factory(self::FORMS_DATETIME)->addHour($hoursLater)->getDatetime());
        $t->setUrl($url);
        self::checkResponse($t->doTrackPageView('Form page'));
        // like the tracker of the plugin sends it
        foreach (array_merge(['ca' => '1', 'fa_vid' => $idFormView, 'fa_name' => $formName, 'fa_id' => $formName . '-form'], $parameters) as $name => $value) {
            $t->setCustomTrackingParameter($name, $value);
        }
        self::checkResponse($t->doTrackPageView('Form page'));
        $t->clearCustomTrackingParameters();
    }

    private function setUpAbTesting(): void
    {
        $api = \Piwik\Plugins\AbTesting\API::getInstance();
        $everyPage = [['attribute' => 'url', 'type' => 'any', 'value' => '', 'inverted' => 0]];
        $api->addExperiment($this->idSite + 1, 'OtherSiteTest', 'Nothing changes', 'A test of another site', [['name' => 'Blue']], $everyPage, [['metric' => 'nb_pageviews']]);
        $variations = [['name' => 'Green'], ['name' => 'Red']];
        $successMetrics = [['metric' => 'nb_conversions_goal_2'], ['metric' => 'nb_orders']];
        $idExperiment = $api->addExperiment($this->idSite, 'ButtonColor', 'A green button sells more', 'The color of the buy button', $variations, $everyPage, $successMetrics);
        $api->startExperiment($idExperiment, $this->idSite);
        // like an experiment that started before the visits, which are tracked into the past
        Db::query("UPDATE experiments SET start_date = '2013-01-01 00:00:00' WHERE idexperiment = ?", [$idExperiment]);
        \Piwik\Tracker\Cache::deleteCacheWebsiteAttributes($this->idSite);

        // a visit for each variation, on a page of their own like trackHsrPageview(), the one of the green button converts a
        // goal no funnel uses
        foreach (['original' => '33.44.55.1', 'Green' => '33.44.55.2', 'Red' => '33.44.55.3'] as $variation => $ip) {
            $t = self::getTracker($this->idSite, self::AB_TESTING_DATETIME, $defaultInit = true);
            $t->setIp($ip);
            $t->setUrl('http://example.com/shop');
            self::checkResponse($t->doTrackPageView('Shop'));
            $t->setForceVisitDateTime(Date::factory(self::AB_TESTING_DATETIME)->addHour(0.01)->getDatetime());
            self::checkResponse($t->doTrackEvent('abtesting', 'ButtonColor', $variation));
            if ($variation === 'Green') {
                $t->setForceVisitDateTime(Date::factory(self::AB_TESTING_DATETIME)->addHour(0.02)->getDatetime());
                self::checkResponse($t->doTrackGoal(2));
            }
        }

        // processes the reports of the experiment, see setUpFunnels()
        Cache::getTransientCache()->flushAll();
        Request::processRequest('CoreAdminHome.invalidateArchivedReports', ['idSites' => $this->idSite, 'dates' => '2013-01-23', 'period' => 'day']);
        Request::processRequest('AbTesting.getMetricsOverview', ['idSite' => $this->idSite, 'period' => 'day', 'date' => '2013-01-23', 'idExperiment' => $idExperiment]);
    }

    private function setUpMediaAnalytics(): void
    {
        // a visit watching a video and listening to an audio, on a page of their own like trackHsrPageview()
        $t = self::getTracker($this->idSite, self::MEDIA_DATETIME, $defaultInit = true);
        $t->setIp('44.55.66.77');
        $t->setUrl('http://example.com/media');
        self::checkResponse($t->doTrackPageView('Media'));
        $media = [
            ['ma_id' => 'vi0001', 'ma_mt' => 'video', 'ma_re' => 'https://example.com/movie.mp4', 'ma_ti' => 'Movie', 'ma_pn' => 'html5', 'ma_st' => '45',
                'ma_le' => '120', 'ma_ps' => '50', 'ma_ttp' => '2', 'ma_w' => '1280', 'ma_h' => '720', 'ma_fs' => '1', 'ma_se' => '15,30,45'],
            ['ma_id' => 'au0001', 'ma_mt' => 'audio', 'ma_re' => 'https://example.com/song.mp3', 'ma_ti' => 'Song', 'ma_pn' => 'html5', 'ma_st' => '200',
                'ma_le' => '200', 'ma_ps' => '100', 'ma_ttp' => '1', 'ma_se' => '15,30,45,60,75,90,105,120,135,150,165,180,195'],
        ];
        foreach ($media as $i => $parameters) {
            $t->setForceVisitDateTime(Date::factory(self::MEDIA_DATETIME)->addHour(0.01 * ($i + 1))->getDatetime());
            foreach ($parameters as $name => $value) {
                $t->setCustomTrackingParameter($name, $value);
            }
            self::checkResponse($t->doPing());
            $t->clearCustomTrackingParameters();
        }
    }

    private function setUpSEOWebVitals(): void
    {
        // the audits fetched when processing the reports of these days, there is no API to add them
        $reports = new \Piwik\Plugins\SEOWebVitals\Dao\Reports();
        $reports->addReport($this->idSite + 1, Date::factory('2013-01-23'), 'https://other.example.com/', 'MOBILE', ['performanceScore' => 0.5]);
        foreach (['2013-01-23', '2013-01-24'] as $day) {
            foreach (['MOBILE', 'DESKTOP'] as $strategy) {
                $reports->addReport($this->idSite, Date::factory($day), 'https://example.com/', $strategy, ['performanceScore' => $strategy === 'MOBILE' ? 0.7 : 0.9, 'day' => $day]);
            }
        }
    }

    private function setUpCrashAnalytics(): void
    {
        $crash = function (string $message, string $resource, string $stack) {
            return ['cra' => $message, 'cra_tp' => strstr($message, ':', true) ?: 'Error', 'cra_ru' => $resource, 'cra_rl' => '10', 'cra_rc' => '5',
                'cra_st' => $stack, 'cra_ct' => 'app'];
        };
        $this->trackCrashes($this->idSite + 1, '11.11.11.11', [$crash('TypeError: other is undefined', 'https://other.example.com/app.js', 'at other (app.js:1:1)')]);
        // the crashes of a visit, on a page of their own like trackHsrPageview(), two with the same stack trace
        $this->trackCrashes($this->idSite, '22.22.22.22', [
            $crash('TypeError: a is undefined', 'http://example.com/app.js', 'at a (app.js:10:5)'),
            $crash('TypeError: b is undefined', 'http://example.com/app.js', 'at b (app.js:20:5)'),
            $crash('ReferenceError: c is not defined', 'http://example.com/vendor.js', 'at a (app.js:10:5)'),
        ]);

        // the crashes of the same source can be merged, the group is shown as the first crash
        $idLogCrashes = array_column(Db::fetchAll("SELECT idlogcrash, message FROM log_crash WHERE idsite = ?", [$this->idSite]), 'idlogcrash', 'message');
        \Piwik\Plugins\CrashAnalytics\API::getInstance()->mergeCrashes($this->idSite, [$idLogCrashes['TypeError: b is undefined'], $idLogCrashes['TypeError: a is undefined']]);
        SegmentApi::getInstance()->add('Crash visitors', 'crashId==' . $idLogCrashes['ReferenceError: c is not defined'], $this->idSite);

        // processes the reports of the crashes, see setUpFunnels()
        Cache::getTransientCache()->flushAll();
        Request::processRequest('CoreAdminHome.invalidateArchivedReports', ['idSites' => $this->idSite, 'dates' => '2013-01-23', 'period' => 'day']);
        Request::processRequest('CrashAnalytics.getCrashesBySource', ['idSite' => $this->idSite, 'period' => 'day', 'date' => '2013-01-23']);
    }

    /**
     * @param array<int, array<string, string>> $crashes
     */
    private function trackCrashes(int $idSite, string $ip, array $crashes): void
    {
        $t = self::getTracker($idSite, self::CRASHES_DATETIME, $defaultInit = true);
        $t->setIp($ip);
        $t->setUrl('http://example.com/app');
        self::checkResponse($t->doTrackPageView('App'));
        foreach ($crashes as $i => $parameters) {
            $t->setForceVisitDateTime(Date::factory(self::CRASHES_DATETIME)->addHour(0.01 * ($i + 1))->getDatetime());
            // like the tracker of the plugin sends it
            foreach (array_merge(['ca' => '1'], $parameters) as $name => $value) {
                $t->setCustomTrackingParameter($name, $value);
            }
            self::checkResponse($t->doTrackPageView('App'));
            $t->clearCustomTrackingParameters();
        }
    }

    private function setUpAdvertisingConversionExport(): void
    {
        $api = \Piwik\Plugins\AdvertisingConversionExport\API::getInstance();
        $parameters = ['goals' => [], 'daysToExport' => 30, 'onlyDirectAttribution' => 1, 'clickIdAttribution' => 'last', 'segment' => ''];
        $idOtherGoal = \Piwik\Plugins\Goals\API::getInstance()->addGoal($this->idSite + 1, 'Other site goal', 'url', 'other', 'contains');
        $api->addConversionExport($this->idSite + 1, 'Other site export', 'GoogleAds', array_merge($parameters, ['goals' => [['idgoal' => $idOtherGoal, 'name' => 'Other', 'revenue' => 'null']]]));
        // the conversions of two goals, of the visits that converted one of them
        $api->addConversionExport($this->idSite, 'Google conversions', 'GoogleAds', array_merge($parameters, [
            'goals' => [['idgoal' => 1, 'name' => 'Downloads', 'revenue' => 'goal'], ['idgoal' => 3, 'name' => 'Contacts', 'revenue' => 'null']],
            'segment' => 'visitConvertedGoalId==1',
        ]), 'The downloads and contacts');

        // a visit from an ad, on a page of its own like trackHsrPageview()
        $t = self::getTracker($this->idSite, self::ADS_DATETIME, $defaultInit = true);
        $t->setIp('66.77.88.99');
        $t->setUrl('http://example.com/landing?gclid=ad-click-1');
        self::checkResponse($t->doTrackPageView('Landing'));
    }

    private function setUpCustomReports(): void
    {
        $api = \Piwik\Plugins\CustomReports\API::getInstance();
        // the dimensions and metrics were listed in this request before the custom dimensions and goals were added
        Cache::getTransientCache()->flushAll();
        $browsers = ['DevicesDetection.BrowserName'];
        // through a request, as the custom dimensions and the metrics of goals are those of the site of the request
        $addReport = function (int $idSite, string $name, string $reportType, array $metricIds, array $parameters = []) {
            return Request::processRequest('CustomReports.addCustomReport', array_merge(compact('idSite', 'name', 'reportType', 'metricIds'), $parameters));
        };
        $api->addCustomMetric('Unused metric', '{{nb_visits}} * 2');
        $addReport($this->idSite + 1, 'Other site report', 'table', ['nb_visits'], ['dimensionIds' => $browsers]);
        $addReport($this->idSite + 1, 'Other site reports', 'table', ['nb_visits'], ['dimensionIds' => $browsers, 'multipleIdSites' => [$this->idSite + 1]]);

        // custom metrics are shared by all sites, one is about a goal of the site
        $pagesPerVisit = 'custom_metric_' . $api->addCustomMetric('Pages per visit', '{{sum_actions}} / {{nb_visits}}', 'number', 2);
        $downloadRate = 'custom_metric_' . $api->addCustomMetric('Download rate', '{{site' . $this->idSite . '_goal_1_conversion}} / {{nb_visits}}', 'percentage', 1);

        // a report on the page of a report created after it
        $idDevices = $addReport($this->idSite, 'Devices', 'evolution', ['nb_visits']);
        $idBrowsers = $addReport($this->idSite, 'Browsers', 'table', ['nb_visits', $pagesPerVisit, $downloadRate], ['dimensionIds' => $browsers, 'description' => 'Pages and downloads']);
        $addReport($this->idSite, 'Browser visits', 'evolution', ['nb_visits'], ['categoryId' => 'CustomReports_CustomReports', 'subcategoryId' => (string) $idBrowsers]);
        // like a report moved to the page of another report, and to its own page
        Db::query('UPDATE custom_reports SET subcategory = ? WHERE idcustomreport = ?', [$idBrowsers, $idDevices]);
        Db::query('UPDATE custom_reports SET subcategory = ? WHERE idcustomreport = ?', [$idBrowsers, $idBrowsers]);
        // reports on the pages of a goal and of a custom dimension, about the goal and the dimension
        $addReport($this->idSite, 'Converting names', 'table', ['nb_visits', 'goal_1_conversion', 'nb_uniq_customdimension_customdimension1'], ['categoryId' => 'Goals_Goals',
            'subcategoryId' => '1', 'dimensionIds' => ['CustomDimension.CustomDimension1'], 'segmentFilter' => 'visitConvertedGoalId==1']);
        $addReport($this->idSite, 'Name visits', 'evolution', ['nb_visits'], ['categoryId' => 'General_Visitors', 'subcategoryId' => 'customdimension1']);
        if (self::isPluginActivated('Funnels')) {
            $idFunnel = Db::fetchOne("SELECT idfunnel FROM funnel WHERE idsite = ? AND name = 'Sub page to download'", [$this->idSite]);
            $addReport($this->idSite, 'Funnel browsers', 'table', ['nb_visits'], ['categoryId' => 'Funnels_Funnels', 'subcategoryId' => (string) $idFunnel, 'dimensionIds' => $browsers]);
        }

        // reports of all websites and of several websites, the site has a report with the name of one of them
        $idAllWebsites = $addReport(0, 'All websites browsers', 'table', ['nb_visits', $pagesPerVisit], ['dimensionIds' => $browsers]);
        $idSeveralWebsites = $addReport($this->idSite, 'Several websites browsers', 'table', ['nb_visits'], ['dimensionIds' => $browsers, 'multipleIdSites' => [$this->idSite, $this->idSite + 1]]);
        $idSameName = $addReport(0, 'Browsers', 'table', ['nb_visits'], ['dimensionIds' => $browsers]);

        // processes the reports, see setUpFunnels()
        Cache::getTransientCache()->flushAll();
        Request::processRequest('CoreAdminHome.invalidateArchivedReports', ['idSites' => $this->idSite, 'dates' => '2013-01-23', 'period' => 'day']);
        $idCustomReports = Db::get()->fetchCol('SELECT idcustomreport FROM custom_reports WHERE idsite IN (?, 0) OR (idsite = -1 AND FIND_IN_SET(?, multiple_idsites))', [$this->idSite, $this->idSite]);
        foreach ($idCustomReports as $idCustomReport) {
            Request::processRequest('CustomReports.getCustomReport', ['idSite' => $this->idSite, 'period' => 'day', 'date' => '2013-01-23', 'idCustomReport' => $idCustomReport]);
        }
    }

    private function setUpSearchEngineKeywordsPerformance(): void
    {
        // the properties the sites import, which can't be configured through the API without accounts of the consoles
        $encryption = new \Piwik\Plugins\SearchEngineKeywordsPerformance\Encryption();
        $settings = [
            [$this->idSite + 1, 'searchconsoleurl', '1##sc-domain:other.example.com'],
            [$this->idSite, 'searchconsoleurl', '1##https://example.com/'],
            [$this->idSite, 'bingsiteurl', $encryption->encryptString('bing-api-key##https://example.com/')],
            [$this->idSite, 'yandexAccountAndHostId', '1##https:example.com:443'],
        ];
        foreach ($settings as [$idSite, $name, $value]) {
            Db::get()->insert('site_setting', ['idsite' => $idSite, 'plugin_name' => 'SearchEngineKeywordsPerformance', 'setting_name' => $name, 'setting_value' => $value]);
        }

        // the keywords and crawl stats imported per property and day, the crawl errors are the current ones
        $imported = [
            'google_stats' => [['sc-domain:other.example.com', '2013-01-23', 'keywordsweb'], ['https://example.com/', '2013-01-22', 'keywordsweb'],
                ['https://example.com/', '2013-01-23', 'keywordsweb'], ['https://example.com/', '2013-01-23', 'keywordsimage']],
            'bing_stats' => [['https://example.com/', '2013-01-23', 'keywords'], ['https://example.com/', '2013-01-23', 'crawlstats'],
                ['https://example.com/', '0000-00-00', 'crawlerrors']],
            'yandex_stats' => [['https:example.com:443', '2013-01-23', 'keywords'], ['https:example.com:443', '2013-01-23', 'crawlstats']],
        ];
        foreach ($imported as $table => $rows) {
            foreach ($rows as [$url, $date, $type]) {
                $data = gzcompress(serialize([['keyword' => 'migration ' . $type, 'clicks' => 3, 'impressions' => 30, 'position' => 2.5]]));
                Db::get()->insert($table, ['url' => $url, 'date' => $date, 'type' => $type, 'data' => $data]);
                if ($table === 'google_stats' && $date === '2013-01-22') {
                    // the target imported a day already
                    Db::get()->insert(self::TARGET_DB_PREFIX . $table, ['url' => $url, 'date' => $date, 'type' => $type, 'data' => $data]);
                }
            }
        }
    }

    private function setUpRollUpReporting(): void
    {
        // a roll-up of the site and another site, and one of all websites
        $api = \Piwik\Plugins\RollUpReporting\API::getInstance();
        $api->addRollUp('Shops roll-up', [$this->idSite, $this->idSite + 1], 'UTC', 'EUR');
        $api->addRollUp('All websites roll-up', ['all'], 'UTC', 'EUR');
    }

    private function setUpMultiChannelConversionAttribution(): void
    {
        $api = \Piwik\Plugins\MultiChannelConversionAttribution\API::getInstance();
        $api->setGoalAttribution($this->idSite, 1, true);
        $api->setGoalAttribution($this->idSite, 2, true);
        $api->setGoalAttribution($this->idSite, 4, true);
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @param int[] $hsrIds
     */
    private function trackHsrPageview(string $ip, float $hoursLater, string $idHsrView, array $hsrIds, array $events): void
    {
        $time = Date::factory(self::HSR_DATETIME)->addHour($hoursLater)->getDatetime();
        $t = self::getTracker($this->idSite, $time, $defaultInit = true);
        $t->setIp($ip);
        // pages of their own, so the data of the other premium plugins doesn't depend on whether this one is installed
        $t->setUrl('http://example.com/products');
        self::checkResponse($t->doTrackPageView('Products'));

        $t->setForceVisitDateTime(Date::factory($time)->addHour(0.01)->getDatetime());
        // like the tracker of the plugin sends it, see HeatmapSessionRecording::generateHeatmapData()
        $t->setCustomTrackingParameter('ca', '1');
        $t->setCustomTrackingParameter('hsr_vid', $idHsrView);
        $t->setCustomTrackingParameter('hsr_ids', array_map('strval', $hsrIds));
        $t->setCustomTrackingParameter('hsr_ev', $events);
        $t->setCustomTrackingParameter('hsr_ti', '5000');
        $t->setCustomTrackingParameter('hsr_vw', '1280');
        $t->setCustomTrackingParameter('hsr_vh', '800');
        $t->setCustomTrackingParameter('hsr_fyp', '300');
        $t->setCustomTrackingParameter('hsr_smp', '700');
        self::checkResponse($t->doTrackPageView('Products'));
        $t->clearCustomTrackingParameters();
    }
}
