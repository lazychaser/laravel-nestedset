<?php

use Illuminate\Database\Capsule\Manager as Capsule;

abstract class ScopedNodeTestBase extends PHPUnit\Framework\TestCase
{
    abstract protected function getTable(): string;

    abstract protected function getModelClass(): string;

    abstract protected function createTable(\Illuminate\Database\Schema\Blueprint $table): void;

    protected array $ids = [];
    protected MenuItemData $menuItemData;

    protected static function getTableName(): string
    {
        $testClass = get_called_class();
        return (new $testClass('dummy'))->getTable();
    }

    public static function setUpBeforeClass(): void
    {
        $schema = Capsule::schema();
        $table = static::getTableName();

        $schema->dropIfExists($table);

        Capsule::disableQueryLog();

        $schema->create($table, function (\Illuminate\Database\Schema\Blueprint $table) {
            $testClass = get_called_class();
            (new $testClass('dummy'))->createTable($table);
        });

        Capsule::enableQueryLog();
    }

    public function setUp(): void
    {
        $this->ids = $this->menuItemData->getIds();
        Capsule::table($this->getTable())->insert($this->menuItemData->getData());

        Capsule::flushQueryLog();

        $modelClass = $this->getModelClass();
        $modelClass::resetActionsPerformed();

        date_default_timezone_set('America/Denver');
    }

    public function tearDown(): void
    {
        Capsule::table($this->getTable())->truncate();
    }

    public function assertTreeNotBroken($menuId)
    {
        $this->assertFalse($this->getModelClass()::scoped(['menu_id' => $menuId])->isBroken());
    }

    public function testNotBroken()
    {
        $this->assertTreeNotBroken(1);
        $this->assertTreeNotBroken(2);
    }

    public function testMovingNodeNotAffectingOtherMenu()
    {
        $node = $this->getModelClass()::where('menu_id', '=', 1)->first();

        $node->down();

        $node = $this->getModelClass()::where('menu_id', '=', 2)->first();

        $this->assertEquals(1, $node->getLft());
    }

    public function testScoped()
    {
        $node = $this->getModelClass()::scoped(['menu_id' => 2])->first();

        $this->assertEquals($this->ids[3], $node->getKey());
    }

    public function testSiblings()
    {
        $node = $this->getModelClass()::find($this->ids[1]);

        $result = $node->getSiblings();

        $this->assertEquals(1, $result->count());
        $this->assertEquals($this->ids[2], $result->first()->getKey());

        $result = $node->getNextSiblings();

        $this->assertEquals($this->ids[2], $result->first()->getKey());

        $node = $this->getModelClass()::find($this->ids[2]);

        $result = $node->getPrevSiblings();

        $this->assertEquals($this->ids[1], $result->first()->getKey());
    }

    public function testDescendants()
    {
        $node = $this->getModelClass()::find($this->ids[2]);

        $result = $node->getDescendants();

        $this->assertEquals(1, $result->count());
        $this->assertEquals($this->ids[5], $result->first()->getKey());

        $node = $this->getModelClass()::scoped(['menu_id' => 1])->with('descendants')->find($this->ids[2]);

        $result = $node->descendants;

        $this->assertEquals(1, $result->count());
        $this->assertEquals($this->ids[5], $result->first()->getKey());
    }

    public function testAncestors()
    {
        $node = $this->getModelClass()::find($this->ids[5]);

        $result = $node->getAncestors();

        $this->assertEquals(1, $result->count());
        $this->assertEquals($this->ids[2], $result->first()->getKey());

        $node = $this->getModelClass()::scoped(['menu_id' => 1])->with('ancestors')->find($this->ids[5]);

        $result = $node->ancestors;

        $this->assertEquals(1, $result->count());
        $this->assertEquals($this->ids[2], $result->first()->getKey());
    }

    public function testDepth()
    {
        $node = $this->getModelClass()::scoped(['menu_id' => 1])->withDepth()->where('id', '=', $this->ids[5])->first();

        $this->assertEquals(1, $node->depth);

        $node = $this->getModelClass()::find($this->ids[2]);

        $result = $node->children()->withDepth()->get();

        $this->assertEquals(1, $result->first()->depth);
    }

    public function testSaveAsRoot()
    {
        $node = $this->getModelClass()::find($this->ids[5]);

        $node->saveAsRoot();

        $this->assertEquals(5, $node->getLft());
        $this->assertEquals(null, $node->parent_id);

        $this->assertOtherScopeNotAffected();
    }

    public function testInsertion()
    {
        $node = $this->getModelClass()::create(['menu_id' => 1, 'parent_id' => $this->ids[5]]);

        $this->assertEquals($this->ids[5], $node->parent_id);
        $this->assertEquals(5, $node->getLft());

        $this->assertOtherScopeNotAffected();
    }

    public function testInsertionToParentFromOtherScope()
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $node = $this->getModelClass()::create(['menu_id' => 2, 'parent_id' => $this->ids[5]]);
    }

    public function testDeletion()
    {
        $node = $this->getModelClass()::find($this->ids[2])->delete();

        $node = $this->getModelClass()::find($this->ids[1]);

        $this->assertEquals(2, $node->getRgt());

        $this->assertOtherScopeNotAffected();
    }

    public function testMoving()
    {
        $node = $this->getModelClass()::find($this->ids[1]);
        $this->assertTrue($node->down());

        $this->assertOtherScopeNotAffected();
    }

    protected function assertOtherScopeNotAffected()
    {
        $node = $this->getModelClass()::find($this->ids[3]);

        $this->assertEquals(1, $node->getLft());
    }

    // Commented, cause there is no assertion here and otherwise the test is marked as risky in PHPUnit 7.
    // What's the purpose of this method? @todo: remove/update?
    /*public function testRebuildsTree()
    {
        $data = [];
        $this->getModelClass()::scoped([ 'menu_id' => 2 ])->rebuildTree($data);
    }*/

    public function testAppendingToAnotherScopeFails()
    {
        $this->expectException(LogicException::class);

        $a = $this->getModelClass()::find($this->ids[1]);
        $b = $this->getModelClass()::find($this->ids[3]);

        $a->appendToNode($b)->save();
    }

    public function testInsertingBeforeAnotherScopeFails()
    {
        $this->expectException(LogicException::class);

        $a = $this->getModelClass()::find($this->ids[1]);
        $b = $this->getModelClass()::find($this->ids[3]);

        $a->insertAfterNode($b);
    }

    public function testEagerLoadingAncestorsWithScope()
    {
        $filteredNodes = $this->getModelClass()::where('title', 'menu item 3')->with(['ancestors'])->get();

        $this->assertEquals($this->ids[2], $filteredNodes->find($this->ids[5])->ancestors[0]->id);
        $this->assertEquals($this->ids[4], $filteredNodes->find($this->ids[6])->ancestors[0]->id);
    }

    public function testEagerLoadingDescendantsWithScope()
    {
        $filteredNodes = $this->getModelClass()::where('title', 'menu item 2')->with(['descendants'])->get();

        $this->assertEquals($this->ids[5], $filteredNodes->find($this->ids[2])->descendants[0]->id);
        $this->assertEquals($this->ids[6], $filteredNodes->find($this->ids[4])->descendants[0]->id);
    }
}
