<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\PluginData;

/**
 * Thrown by a column transform when the row references a row that wasn't migrated, so the row is skipped.
 */
class UnresolvableReferenceException extends \Exception
{
}
