<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Migration\tests\Unit;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\Migration\PluginData\IdMaps;

/**
 * @group Migration
 * @group IdMapsTest
 * @group Plugins
 */
class IdMapsTest extends TestCase
{
    public function test_childEntriesDoNotHideOtherParentIds(): void
    {
        $parent = new IdMaps();
        $parent->setAll('segment', [10 => 110, 20 => 120]);
        $child = new IdMaps($parent);
        $child->set('segment', 30, 130);

        $this->assertSame(110, $child->get('segment', 10));
        $this->assertSame(120, $child->get('segment', '20'));
        $this->assertSame(130, $child->get('segment', 30));
        $this->assertNull($child->get('segment', 40));
        $this->assertNull($parent->get('segment', 30));
        // Enumeration stays local to the batch; individual references may fall back to its parent.
        $this->assertSame([30 => 130], $child->getAll('segment'));
    }

    public function test_childOverridesAndEmptyMapsKeepParentFallback(): void
    {
        $parent = new IdMaps();
        $parent->setAll('item', [1 => 11, 2 => 12]);
        $child = new IdMaps($parent);
        $child->setAll('item', [1 => 0]);
        $grandchild = new IdMaps($child);
        $grandchild->setAll('item', []);

        $this->assertSame(0, $grandchild->get('item', 1));
        $this->assertSame(12, $grandchild->get('item', 2));
        $this->assertSame(11, $parent->get('item', 1));
        $this->assertNull($grandchild->get('unknown', 1));
        $this->assertSame([], $grandchild->getAll('item'));
    }
}
