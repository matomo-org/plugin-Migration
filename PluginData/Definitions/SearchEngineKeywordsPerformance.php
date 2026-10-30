<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\PluginData\Definitions;

use Piwik\Common;
use Piwik\Db;
use Piwik\Plugins\Migration\PluginData\PluginDefinition;
use Piwik\Plugins\Migration\PluginData\TableDefinition;

/**
 * The keywords and crawl stats imported from Google Search Console, Bing Webmaster Tools and Yandex Webmaster. They are
 * stored per property, a URL or a Yandex host ID, which the settings of a site name and several sites can import.
 */
final class SearchEngineKeywordsPerformance
{
    private const PLUGIN_NAME = 'SearchEngineKeywordsPerformance';

    /**
     * The class encrypting the Bing settings of a site with the encryption_key of the config of the plugin
     */
    private const ENCRYPTION_CLASS = 'Piwik\Plugins\SearchEngineKeywordsPerformance\Encryption';

    public static function get(): PluginDefinition
    {
        $definition = new PluginDefinition(self::PLUGIN_NAME);

        $tables = ['google_stats' => 'searchconsoleurl', 'bing_stats' => 'bingsiteurl', 'yandex_stats' => 'yandexAccountAndHostId'];
        foreach ($tables as $table => $setting) {
            $definition->addTable(TableDefinition::forSiteKeys($table, 'url', 'date', function (int $idSite) use ($setting) {
                return self::getPropertiesOfSite($idSite, $setting);
            }));
        }

        $definition->addNote(
            'The accounts of the search consoles are settings of the whole Matomo: add them on the target Matomo, '
            . 'and configure the search console properties for the site there if it existed already, as it keeps its settings. '
            . 'The Bing settings of the site are encrypted with the encryption_key of the [SearchEngineKeywordsPerformance] section of the config, '
            . 'which the target Matomo needs too, otherwise configure Bing for the site again.'
        );

        return $definition;
    }

    /**
     * The property a setting of the site names, eg "1##https://example.com/" names the property https://example.com/ of
     * the account 1.
     *
     * @return string[]
     */
    public static function getPropertiesOfSite(int $idSite, string $setting): array
    {
        /** @var \Zend_Db_Adapter_Abstract $db Migration runs outside the tracker, using the regular DB adapter. */
        $db = Db::get();
        $value = Db::fetchOne(
            'SELECT setting_value FROM ' . $db->quoteIdentifier([Common::prefixTable('site_setting')]) . ' WHERE idsite = ? AND plugin_name = ? AND setting_name = ?',
            [$idSite, self::PLUGIN_NAME, $setting]
        );
        if (!is_string($value)) {
            return [];
        }
        $value = self::decrypt($value, self::ENCRYPTION_CLASS);
        if ($value === null) {
            throw new \RuntimeException(sprintf(
                'Cannot decrypt the SearchEngineKeywordsPerformance setting "%s" for site %d. Restore the source plugin encryption configuration, '
                . 'or explicitly exclude its data with --skip-plugin-data=SearchEngineKeywordsPerformance.',
                $setting,
                $idSite
            ));
        }
        $parts = explode('##', $value, 2);
        return isset($parts[1]) && $parts[1] !== '' ? [$parts[1]] : [];
    }

    /**
     * @param string $encryptionClass the class of the plugin decrypting the value, which may not be installed
     * @return string|null the value, decrypted if it is encrypted, null if it can't be decrypted, eg without the key
     */
    private static function decrypt(#[\SensitiveParameter] string $value, string $encryptionClass): ?string
    {
        if (strpos($value, 'enc:v1:') !== 0) {
            return $value;
        }
        if (!class_exists($encryptionClass)) {
            return null;
        }
        try {
            $decrypt = [new $encryptionClass(), 'decryptString'];
            $decrypted = is_callable($decrypt) ? $decrypt($value) : null;
        } catch (\Throwable $e) {
            return null;
        }
        return is_string($decrypted) ? $decrypted : null;
    }
}
