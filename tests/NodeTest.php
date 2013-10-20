<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Kalnoy\Nestedset\NestedSet;

class NodeTest extends PHPUnit_Framework_TestCase {
    public static function setUpBeforeClass()
    {
        $schema = Capsule::schema();

        $schema->dropIfExists('categories');
        $schema->create('categories', function ($table) {
            $table->increments('id');
            $table->string('name');
            NestedSet::columns($table);
        });

        Capsule::enableQueryLog();
    }

    public function setUp()
    {
        $data = include __DIR__.'/data/categories.php';

        Capsule::table('categories')->insert($data);
    }

    public function tearDown()
    {
        Capsule::table('categories')->truncate();
    }

    // public static function tearDownAfterClass()
    // {
    //     $log = Capsule::getQueryLog();
    //     foreach ($log as $item) {
    //         echo $item['query']." with ".implode(', ', $item['bindings'])."\n";
    //     }
    // }

    public function assertTreeNotBroken($table = 'categories')
    {
        $checks = array();

        // Check if lft and rgt values are ok
        $checks[] = "from $table where _lft >= _rgt or (_rgt - _lft) % 2 = 0";

        // Check if lft and rgt values are unique
        $checks[] = "from $table c1, $table c2 where c1.id <> c2.id and ".
            "(c1._lft=c2._lft or c1._rgt=c2._rgt or c1._lft=c2._rgt or c1._rgt=c2._lft)";
        
        // Check if parent_id is set correctly
        $checks[] = "from $table c, $table p, $table m where c.parent_id=p.id and m.id <> p.id and m.id <> c.id and ".
             "(c._lft not between p._lft and p._rgt or c._lft between m._lft and m._rgt and m._lft between p._lft and p._rgt)";

        foreach ($checks as $i => $check) {
            $checks[$i] = 'select 1 as error '.$check;
        }

        $sql = 'select max(error) as errors from ('.implode(' union ', $checks).') _';

        $actual = Capsule::connection()->selectOne($sql);

        $this->assertEquals(array('errors' => null), $actual, "The tree structure of $table is broken!");
    }

    public function assertNodeRecievesValidValues($node)
    {
        $lft = $node->getLft();
        $rgt = $node->getRgt();
        $nodeInDb = $this->findCategory($node->name);

        $this->assertTrue($lft == $nodeInDb->getLft() && $rgt == $nodeInDb->getRgt(), 'Node is not synced with database after save.');
    }

    public function findCategory($name)
    {
        return Category::whereName($name)->first();
    }

    public function testTreeNotBroken()
    {
        $this->assertTreeNotBroken();
    }

    public function nodeValues($node)
    {
        return array($node->_lft, $node->_rgt, $node->parent_id);
    }

    public function testRecievesValidValuesWhenAppendedTo()
    {
        $root = Category::root();
        $node = new Category;
        $node->appendTo($root);

        $this->assertEquals(array($root->_rgt, $root->_rgt + 1, $root->id), $this->nodeValues($node));
    }

    public function testRecievesValidValuesWhenPrependedTo()
    {
        $root = Category::root();
        $node = new Category;
        $node->prependTo($root);

        $this->assertEquals(array($root->_lft + 1, $root->_lft + 2, $root->id), $this->nodeValues($node));
    }

    public function testRecievesValidValuesWhenInsertedAfter()
    {
        $target = $this->findCategory('apple');
        $node = new Category;
        $node->after($target);

        $this->assertEquals(array($target->_rgt + 1, $target->_rgt + 2, $target->parent->id), $this->nodeValues($node));
    }

    public function testRecievesValidValuesWhenInsertedBefore()
    {
        $target = $this->findCategory('apple');
        $node = new Category;
        $node->before($target);

        $this->assertEquals(array($target->_lft, $target->_lft + 1, $target->parent->id), $this->nodeValues($node));
    }

    public function testNewCategoryInserts()
    {
        $node = new Category(array('name' => 'LG'));
        $target = $this->findCategory('mobile');

        $this->assertTrue($node->appendTo($target)->save());

        $this->assertTreeNotBroken();
        $this->assertNodeRecievesValidValues($node);
    }

    public function testCategoryMovesDown()
    {
        $node = $this->findCategory('apple');
        $target = $this->findCategory('mobile');

        $this->assertTrue($node->appendTo($target)->save());

        $this->assertTreeNotBroken();
        $this->assertNodeRecievesValidValues($node);
    }

    public function testCategoryMovesUp()
    {
        $node = $this->findCategory('samsung');
        $target = $this->findCategory('notebooks');

        $this->assertTrue($node->append($target)->save());

        $this->assertTreeNotBroken();
        $this->assertNodeRecievesValidValues($node);
    }

    /**
     * @expectedException Exception
     */
    public function testFailsToInsertIntoItself()
    {
        $node = $this->findCategory('notebooks');
        $target = $node->children()->first();

        $node->after($target)->save();
    }

    /**
     * @expectedException Exception
     */
    public function testRootDoesNotGetsDeleted()
    {
        $result = Category::root()->delete();
    }

    public function testWithoutRootWorks()
    {
        $result = Category::withoutRoot()->pluck('name');

        $this->assertNotEquals('store', $result);
    }

    public function testAncestorsReturnsAncestorsWithoutNodeItself()
    {
        $node = $this->findCategory('apple');
        $path = $node->ancestors()->lists('name');

        $this->assertEquals(array('store', 'notebooks'), $path);
    }

    public function testAncestorsOfReturnsAncestorsWithNode()
    {
        $path = Category::ancestorsOf(3)->lists('name');

        $this->assertEquals(array('store', 'notebooks', 'apple'), $path);
    }

    public function testDescendantsQueried()
    {
        $node = $this->findCategory('mobile');
        $descendants = $node->descendants()->lists('name');

        $this->assertEquals(array('nokia', 'samsung', 'galaxy', 'sony'), $descendants);
    }

    /**
     * @expectedException Exception
     */
    public function testFailsToInsertAfterRoot()
    {
        $node = $this->findCategory('apple');
        $node->after(Category::root())->save();
    }

    public function testWithDepthWorks()
    {
        $nodes = Category::withDepth()->limit(4)->lists('depth');

        $this->assertEquals(array(0, 1, 2, 2), $nodes);
    }

    public function testWithDepthWithCustomKeyWorks()
    {
        $node = Category::whereIsRoot()->withDepth('level')->first();

        $this->assertTrue(isset($node['level']));
    }

    public function testWithDepthWorksAlongWithDefaultKeys()
    {
        $node = Category::withDepth()->first();

        $this->assertTrue(isset($node->name));
    }

    public function testParentIdAttributeAccessorAppendsNode()
    {
        $node = new Category(array('name' => 'lg', 'parent_id' => 5));
        $node->save();

        $this->assertEquals(5, $node->parent_id);
    }

    /**
     * @expectedException Exception
     */
    public function testFailsToSaveNodeUntilNotInserted()
    {
        $node = new Category;
        $node->save();
    }

    public function testNodeIsDeletedWithDescendants()
    {
        $node = $this->findCategory('notebooks');
        $this->assertTrue($node->delete());

        $this->assertTreeNotBroken();

        $nodes = Category::whereIn('id', array(2, 3, 4))->count();
        $this->assertEquals(0, $nodes);
    }

    /**
     * @expectedException Exception
     */
    public function testSavingDeletedNodeWithoutInsertingFails()
    {
        $node = $this->findCategory('apple');
        $this->assertTrue($node->delete());
        $node->save();
    }

    public function testParentGetsUpdateWhenNodeIsAppended()
    {
        $node = new Category(array('name' => 'Name'));
        $target = $this->findCategory('apple');
        $expectedValue = $target->_rgt + 2;

        $node->appendTo($target)->save();
        $this->assertEquals($expectedValue, $target->_rgt);
    }

    /**
     * @expectedException Exception
     */
    public function testFailsToSaveNodeUntilParentIsSaved()
    {
        $node = new Category(array('title' => 'Node'));
        $parent = new Category(array('title' => 'Parent'));

        $node->appendTo($parent)->save();
    }

    public function testParentGetsUpdateWhenNodeIsDeleted()
    {
        $node = $this->findCategory('mobile');
        $parent = $node->parent;
        $targetRgt = $parent->_rgt - $node->getNodeHeight();

        $node->delete();

        $this->assertEquals($targetRgt, $parent->_rgt);
    }

    public function testGetsSiblings()
    {
        $node = $this->findCategory('samsung');
        $siblings = $node->siblings()->lists('id');

        $this->assertEquals(array(6, 9), $siblings);
    }

    public function testGetsNextSiblings()
    {
        $node = $this->findCategory('samsung');
        $siblings = $node->nextSiblings()->lists('id');

        $this->assertEquals(array(9), $siblings);
    }

    public function testGetsPrevSiblings()
    {
        $node = $this->findCategory('samsung');
        $siblings = $node->prevSiblings()->lists('id');

        $this->assertEquals(array(6), $siblings);
    }

    public function testFetchesReversed()
    {
        $node = $this->findCategory('sony');
        $siblings = $node->prevSiblings()->reversed()->pluck('id');

        $this->assertEquals(7, $siblings);
    }

    public function testToTreeBuildsWithDefaultOrder()
    {
        $tree = Category::whereBetween('_lft', array(8, 17))->get()->toTree();

        $this->assertEquals(1, count($tree));

        $root = $tree->first();
        $this->assertEquals('mobile', $root->name);
        $this->assertEquals(3, count($root->children));
    }

    public function testToTreeBuildsWithCustomOrder()
    {
        $tree = Category::whereBetween('_lft', array(8, 17))
            ->orderBy('title')
            ->get()
            ->toTree();

        $this->assertEquals(1, count($tree));

        $root = $tree->first();
        $this->assertEquals('mobile', $root->name);
        $this->assertEquals(3, count($root->children));
    }

    public function testToTreeBuildsWithDefaultOrderAndMultipleRootNodes()
    {
        $tree = Category::withoutRoot()->get()->toTree();

        $this->assertEquals(2, count($tree));
    }

    public function testToTreeBuildsWithRootItemIdProvided()
    {
        $tree = Category::whereBetween('_lft', array(8, 17))->get()->toTree(5);

        $this->assertEquals(3, count($tree));

        $root = $tree[1];
        $this->assertEquals('samsung', $root->name);
        $this->assertEquals(1, count($root->children));
    }

    public function testRetrievesNextNode()
    {
        $node = $this->findCategory('apple');
        $next = $node->next()->first();

        $this->assertEquals('lenovo', $next->name);
    }

    public function testRetrievesPrevNode()
    {
        $node = $this->findCategory('apple');
        $next = $node->prev()->first();

        $this->assertEquals('notebooks', $next->name);
    }
}