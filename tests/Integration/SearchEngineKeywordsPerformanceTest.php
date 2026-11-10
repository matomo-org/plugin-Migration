<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\tests\Integration;

use Piwik\Common;
use Piwik\Db;
use Piwik\Plugins\Migration\PluginData\Definitions\SearchEngineKeywordsPerformance;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group Migration
 * @group SearchEngineKeywordsPerformanceTest
 * @group Plugins
 */
class SearchEngineKeywordsPerformanceTest extends IntegrationTestCase
{
    /**
     * @dataProvider getPropertyCases
     */
    public function test_registeredPropertyCallbacksKeepSiteAndPluginIsolation(string $table, string $setting, string $value, array $expected): void
    {
        $this->insertSetting(1, 'SearchEngineKeywordsPerformance', $setting, $value);
        $this->insertSetting(2, 'SearchEngineKeywordsPerformance', $setting, 'other-account##https://other.example/');
        $this->insertSetting(1, 'OtherPlugin', $setting, 'other-account##https://unrelated.example/');
        foreach (SearchEngineKeywordsPerformance::get()->getTables() as $definition) {
            if ($definition->getTable() === $table) {
                $this->assertSame($expected, $definition->getSiteKeys(1));
                $this->assertSame([], $definition->getSiteKeys(3));
                return;
            }
        }
        $this->fail('No property callback for ' . $table);
    }

    public static function getPropertyCases(): array
    {
        return [
            ['google_stats', 'searchconsoleurl', 'account##sc-domain:example.com', ['sc-domain:example.com']],
            ['bing_stats', 'bingsiteurl', 'synthetic-account##https://example.com/', ['https://example.com/']],
            ['yandex_stats', 'yandexAccountAndHostId', 'account##https:example.com:443', ['https:example.com:443']],
            ['google_stats', 'searchconsoleurl', '', []],
            ['google_stats', 'searchconsoleurl', 'account##', []],
            ['google_stats', 'searchconsoleurl', 'malformed-setting', []],
        ];
    }

    public function test_unreadableEncryptedSettingStopsWithActionableErrorWithoutExposingItsValue(): void
    {
        $value = 'enc:v1:synthetic-unreadable-secret';
        $this->insertSetting(1, 'SearchEngineKeywordsPerformance', 'bingsiteurl', $value);
        try {
            SearchEngineKeywordsPerformance::getPropertiesOfSite(1, 'bingsiteurl');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Cannot decrypt', $exception->getMessage());
            $this->assertStringContainsString('bingsiteurl', $exception->getMessage());
            $this->assertStringContainsString('site 1', $exception->getMessage());
            $this->assertStringContainsString('--skip-plugin-data=SearchEngineKeywordsPerformance', $exception->getMessage());
            $this->assertStringNotContainsString($value, $exception->getMessage());
            $this->assertNull($exception->getPrevious(), 'Do not expose a plugin decryption exception containing credentials.');
            return;
        }
        $this->fail('An unreadable configured property must not silently skip its historical data.');
    }

    /**
     * @dataProvider getDecryptionCases
     */
    public function test_optionalEncryptionAdapter(string $value, string $class, ?string $expected): void
    {
        // Test the optional adapter with local doubles, without defining or installing a licensed plugin class.
        $decrypt = new \ReflectionMethod(SearchEngineKeywordsPerformance::class, 'decrypt');
        $this->assertSame($expected, $decrypt->invoke(null, $value, $class));
    }

    public static function getDecryptionCases(): array
    {
        return [
            ['account##property', 'MissingEncryptionForMigrationTest', 'account##property'],
            ['enc:v1:synthetic', 'MissingEncryptionForMigrationTest', null],
            ['enc:v1:synthetic', SuccessfulSettingEncryption::class, 'synthetic-account##https://example.com/'],
            ['enc:v1:synthetic', FailingSettingEncryption::class, null],
            ['enc:v1:synthetic', InvalidSettingEncryption::class, null],
            ['enc:v1:synthetic', BrokenSettingEncryptionConstructor::class, null],
            ['enc:v1:synthetic', \stdClass::class, null],
        ];
    }

    private function insertSetting(int $idSite, string $plugin, string $setting, string $value): void
    {
        Db::get()->insert(Common::prefixTable('site_setting'), [
            'idsite' => $idSite, 'plugin_name' => $plugin, 'setting_name' => $setting, 'setting_value' => $value,
        ]);
    }
}

class SuccessfulSettingEncryption
{
    public function decryptString($value)
    {
        return 'synthetic-account##https://example.com/';
    }
}

class FailingSettingEncryption
{
    public function decryptString($value)
    {
        throw new \RuntimeException('Synthetic decryption failure');
    }
}

class InvalidSettingEncryption
{
    public function decryptString($value)
    {
        return false;
    }
}

class BrokenSettingEncryptionConstructor
{
    public function __construct()
    {
        throw new \Error('Synthetic missing decryption configuration');
    }
}
