<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\PluginData\Definitions;

use Piwik\Common;
use Piwik\Plugins\Migration\PluginData\IdResolver;
use Piwik\Plugins\Migration\PluginData\PluginDefinition;
use Piwik\Plugins\Migration\PluginData\TableDefinition;

/**
 * The exports of the conversions of goals to advertising platforms configured for the site, and the click IDs of the
 * ads the visits came from.
 */
final class AdvertisingConversionExport
{
    public static function get(): PluginDefinition
    {
        $definition = new PluginDefinition('AdvertisingConversionExport');

        $definition->addTable(TableDefinition::forSite('site_conversion_export')
            ->autoIncrement('idexport')
            ->transforms('access_token', [self::class, 'keepAccessTokenUnlessUsed'])
            ->transforms('parameters', [self::class, 'pointParametersToTargetIds'])
            ->identifiedBy('name', 'type', 'ts_created'));
        $definition->addTable(TableDefinition::forVisit('log_clickid'));

        $definition->addNote(
            'The advertising platforms fetch the conversions to import from the URLs of the conversion exports, which need to be changed to the URLs of the target Matomo. '
            . 'The access tokens in the URLs stay valid only if the target Matomo has the same salt in the [AdvertisingConversionExport] section of its config, otherwise regenerate them there.'
        );

        return $definition;
    }

    /**
     * The access token of an export is unique. It is stored hashed with the salt of the Matomo, so the token of a copy
     * that gets another one is unknown, and needs to be generated again on the target.
     *
     * @param mixed $accessToken
     * @param array<string, mixed> $row
     * @return mixed
     */
    public static function keepAccessTokenUnlessUsed(#[\SensitiveParameter] $accessToken, array $row, IdResolver $resolver)
    {
        $targetDb = $resolver->getTargetDb();
        $isUsed = $targetDb->fetchOne('SELECT 1 FROM ' . $targetDb->quoteTable('site_conversion_export') . ' WHERE access_token = ?', [$accessToken]);
        return $isUsed ? sha1(Common::getRandomString(50)) : $accessToken;
    }

    /**
     * The parameters of an export, eg {"goals":[{"idgoal":1,...}],"segment":"visitConvertedGoalId==1",...}, are about
     * goals and can hold a segment definition.
     *
     * @param mixed $parameters
     * @param array<string, mixed> $row
     * @return mixed
     */
    public static function pointParametersToTargetIds($parameters, array $row, IdResolver $resolver)
    {
        $values = is_string($parameters) && $parameters !== '' ? json_decode($parameters, true) : null;
        if (!is_array($values)) {
            return $parameters;
        }

        $targetValues = $values;
        if (is_array($values['goals'] ?? null)) {
            $targetValues['goals'] = [];
            foreach ($values['goals'] as $i => $goal) {
                if (is_array($goal) && is_scalar($goal['idgoal'] ?? null) && ctype_digit((string) $goal['idgoal'])) {
                    $idGoal = $resolver->resolve(TableDefinition::GOAL, (string) $goal['idgoal']);
                    $goal['idgoal'] = is_int($goal['idgoal']) ? (int) $idGoal : (string) $idGoal;
                }
                $targetValues['goals'][$i] = $goal;
            }
        }
        if (is_string($values['segment'] ?? null) && $values['segment'] !== '') {
            $targetValues['segment'] = $resolver->resolveSegmentDefinition($values['segment']);
        }
        return $targetValues == $values ? $parameters : json_encode($targetValues);
    }
}
