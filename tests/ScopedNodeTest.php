<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Kalnoy\Nestedset\NestedSet;

class ScopedNodeTest extends PHPUnit\Framework\TestCase
{
    public static function setUpBeforeClass()
    {
        $schema = Capsule::schema();

        $schema->dropIfExists('menu_items');

        Capsule::disableQueryLog();

        $schema->create('menu_items', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('menu_id');
            $table->string('title')->nullable();
            NestedSet::columns($table);
        });

        Capsule::enableQueryLog();
    }

    public function setUp()
    {
        $data = include __DIR__.'/data/menu_items.php';

        Capsule::table('menu_items')->insert($data);

        Capsule::flushQueryLog();

        MenuItem::resetActionsPerformed();

        date_default_timezone_set('America/Denver');
    }

    public function tearDown()
    {
        Capsule::table('menu_items')->truncate();
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

        $this->assertEquals(3, $node->getKey());
    }

    public function testSiblings()
    {
        $node = MenuItem::find(1);

        $result = $node->getSiblings();

        $this->assertEquals(1, $result->count());
        $this->assertEquals(2, $result->first()->getKey());

        $result = $node->getNextSiblings();

        $this->assertEquals(2, $result->first()->getKey());

        $node = MenuItem::find(2);

        $result = $node->getPrevSiblings();

        $this->assertEquals(1, $result->first()->getKey());
    }

    public function testDescendants()
    {
        $node = MenuItem::find(2);

        $result = $node->getDescendants();

        $this->assertEquals(1, $result->count());
        $this->assertEquals(5, $result->first()->getKey());

        $node = MenuItem::scoped([ 'menu_id' => 1 ])->with('descendants')->find(2);

        $result = $node->descendants;

        $this->assertEquals(1, $result->count());
        $this->assertEquals(5, $result->first()->getKey());
    }

    public function testAncestors()
    {
        $node = MenuItem::find(5);

        $result = $node->getAncestors();

        $this->assertEquals(1, $result->count());
        $this->assertEquals(2, $result->first()->getKey());

        $node = MenuItem::scoped([ 'menu_id' => 1 ])->with('ancestors')->find(5);

        $result = $node->ancestors;

        $this->assertEquals(1, $result->count());
        $this->assertEquals(2, $result->first()->getKey());
    }

    public function testDepth()
    {
        $node = MenuItem::scoped([ 'menu_id' => 1 ])->withDepth()->where('id', '=', 5)->first();

        $this->assertEquals(1, $node->depth);

        $node = MenuItem::find(2);

        $result = $node->children()->withDepth()->get();

        $this->assertEquals(1, $result->first()->depth);
    }

    public function testSaveAsRoot()
    {
        $node = MenuItem::find(5);

        $node->saveAsRoot();

        $this->assertEquals(5, $node->getLft());
        $this->assertEquals(null, $node->parent_id);

        $this->assertOtherScopeNotAffected();
    }

    public function testInsertion()
    {
        $node = MenuItem::create([ 'menu_id' => 1, 'parent_id' => 5 ]);

        $this->assertEquals(5, $node->parent_id);
        $this->assertEquals(5, $node->getLft());

        $this->assertOtherScopeNotAffected();
    }

    /**
     * @expectedException \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function testInsertionToParentFromOtherScope()
    {
        $node = MenuItem::create([ 'menu_id' => 2, 'parent_id' => 5 ]);
    }

    public function testDeletion()
    {
        $node = MenuItem::find(2)->delete();

        $node = MenuItem::find(1);

        $this->assertEquals(2, $node->getRgt());

        $this->assertOtherScopeNotAffected();
    }

    public function testMoving()
    {
        $node = MenuItem::find(1);
        $this->assertTrue($node->down());

        $this->assertOtherScopeNotAffected();
    }

    protected function assertOtherScopeNotAffected()
    {
        $node = MenuItem::find(3);

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
        $a = MenuItem::find(1);
        $b = MenuItem::find(3);

        $a->appendToNode($b)->save();
    }

    /**
     * @expectedException LogicException
     */
    public function testInsertingBeforeAnotherScopeFails()
    {
        $a = MenuItem::find(1);
        $b = MenuItem::find(3);

        $a->insertAfterNode($b);
    }
}