<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Kalnoy\Nestedset\NestedSet;
use UuidMenuItem as MenuItem;

class ScopedUuidNodeTest extends PHPUnit\Framework\TestCase
{
    public static function setUpBeforeClass()
    {
        $schema = Capsule::schema();

        $schema->dropIfExists('menu_items');

        Capsule::disableQueryLog();

        $schema->create('menu_items', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->uuid('id');
            $table->unsignedInteger('menu_id');
            $table->string('title')->nullable();
            NestedSet::columns($table);
        });

        Capsule::enableQueryLog();
    }

    public function setUp()
    {
        $data = include __DIR__.'/data/uuid_menu_items.php';

        Capsule::table('menu_items')->insert($data);

        Capsule::flushQueryLog();

        MenuItem::resetActionsPerformed();

        date_default_timezone_set('America/Denver');
    }

    public function tearDown()
    {
        Capsule::table('menu_items')->delete();
    }

    public function assertTreeNotBroken($menuId)
    {
        $this->assertFalse(MenuItem::scoped([ 'menu_id' => $menuId ])->isBroken());
    }

    public function testNotBroken()
    {
        $this->assertTreeNotBroken(1);
        $this->assertTreeNotBroken(2);
    }

    public function testMovingNodeNotAffectingOtherMenu()
    {
        $node = MenuItem::where('menu_id', '=', 1)->first();

        $node->down();

        $node = MenuItem::where('menu_id', '=', 2)->first();

        $this->assertEquals(1, $node->getLft());
    }

    public function testScoped()
    {
        $node = MenuItem::scoped([ 'menu_id' => 2 ])->first();

        $this->assertEquals('cd728a47-d95d-4e00-8045-ce6bd9a85c18', $node->getKey());
    }

    public function testSiblings()
    {
        $node = MenuItem::find('03eb7027-f778-4b41-bec4-aadbee993b3e');

        $result = $node->getSiblings();

        $this->assertEquals(1, $result->count());
        $this->assertEquals('5625b583-b0c4-4f7c-934c-c0afc22d2f97', $result->first()->getKey());

        $result = $node->getNextSiblings();

        $this->assertEquals('5625b583-b0c4-4f7c-934c-c0afc22d2f97', $result->first()->getKey());

        $node = MenuItem::find('5625b583-b0c4-4f7c-934c-c0afc22d2f97');

        $result = $node->getPrevSiblings();

        $this->assertEquals('03eb7027-f778-4b41-bec4-aadbee993b3e', $result->first()->getKey());
    }

    public function testDescendants()
    {
        $node = MenuItem::find('5625b583-b0c4-4f7c-934c-c0afc22d2f97');

        $result = $node->getDescendants();

        $this->assertEquals(1, $result->count());
        $this->assertEquals('7df1597b-97e1-4445-a3df-648f60484e43', $result->first()->getKey());

        $node = MenuItem::scoped([ 'menu_id' => 1 ])->with('descendants')->find('5625b583-b0c4-4f7c-934c-c0afc22d2f97');

        $result = $node->descendants;

        $this->assertEquals(1, $result->count());
        $this->assertEquals('7df1597b-97e1-4445-a3df-648f60484e43', $result->first()->getKey());
    }

    public function testAncestors()
    {
        $node = MenuItem::find('7df1597b-97e1-4445-a3df-648f60484e43');

        $result = $node->getAncestors();

        $this->assertEquals(1, $result->count());
        $this->assertEquals('5625b583-b0c4-4f7c-934c-c0afc22d2f97', $result->first()->getKey());

        $node = MenuItem::scoped([ 'menu_id' => 1 ])->with('ancestors')->find('7df1597b-97e1-4445-a3df-648f60484e43');

        $result = $node->ancestors;

        $this->assertEquals(1, $result->count());
        $this->assertEquals('5625b583-b0c4-4f7c-934c-c0afc22d2f97', $result->first()->getKey());
    }

    public function testDepth()
    {
        $node = MenuItem::scoped([ 'menu_id' => 1 ])->withDepth()->where('id', '=', '7df1597b-97e1-4445-a3df-648f60484e43')->first();

        $this->assertEquals(1, $node->depth);

        $node = MenuItem::find('5625b583-b0c4-4f7c-934c-c0afc22d2f97');

        $result = $node->children()->withDepth()->get();

        $this->assertEquals(1, $result->first()->depth);
    }

    public function testSaveAsRoot()
    {
        $node = MenuItem::find('7df1597b-97e1-4445-a3df-648f60484e43');

        $node->saveAsRoot();

        $this->assertEquals(5, $node->getLft());
        $this->assertEquals(null, $node->parent_id);

        $this->assertOtherScopeNotAffected();
    }

    public function testInsertion()
    {
        $node = MenuItem::create([ 'menu_id' => 1, 'parent_id' => '7df1597b-97e1-4445-a3df-648f60484e43' ]);

        $this->assertEquals('7df1597b-97e1-4445-a3df-648f60484e43', $node->parent_id);
        $this->assertEquals(5, $node->getLft());

        $this->assertOtherScopeNotAffected();
    }

    /**
     * @expectedException \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function testInsertionToParentFromOtherScope()
    {
        $node = MenuItem::create([ 'menu_id' => 2, 'parent_id' => '7df1597b-97e1-4445-a3df-648f60484e43' ]);
    }

    public function testDeletion()
    {
        $node = MenuItem::find('5625b583-b0c4-4f7c-934c-c0afc22d2f97')->delete();

        $node = MenuItem::find('03eb7027-f778-4b41-bec4-aadbee993b3e');

        $this->assertEquals(2, $node->getRgt());

        $this->assertOtherScopeNotAffected();
    }

    public function testMoving()
    {
        $node = MenuItem::find('03eb7027-f778-4b41-bec4-aadbee993b3e');
        $this->assertTrue($node->down());

        $this->assertOtherScopeNotAffected();
    }

    protected function assertOtherScopeNotAffected()
    {
        $node = MenuItem::find('cd728a47-d95d-4e00-8045-ce6bd9a85c18');

        $this->assertEquals(1, $node->getLft());
    }

    // Commented, cause there is no assertion here and otherwise the test is marked as risky in PHPUnit 7.
    // What's the purpose of this method? @todo: remove/update?
    /*public function testRebuildsTree()
    {
        $data = [];
        MenuItem::scoped([ 'menu_id' => 2 ])->rebuildTree($data);
    }*/

    /**
     * @expectedException LogicException
     */
    public function testAppendingToAnotherScopeFails()
    {
        $a = MenuItem::find('03eb7027-f778-4b41-bec4-aadbee993b3e');
        $b = MenuItem::find('cd728a47-d95d-4e00-8045-ce6bd9a85c18');

        $a->appendToNode($b)->save();
    }

    /**
     * @expectedException LogicException
     */
    public function testInsertingBeforeAnotherScopeFails()
    {
        $a = MenuItem::find('03eb7027-f778-4b41-bec4-aadbee993b3e');
        $b = MenuItem::find('cd728a47-d95d-4e00-8045-ce6bd9a85c18');

        $a->insertAfterNode($b);
    }
}