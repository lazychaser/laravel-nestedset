<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Kalnoy\Nestedset\NestedSet;

class NodeTest extends PHPUnit_Framework_TestCase {
    public static function setUpBeforeClass()
    {
        $schema = Capsule::schema();

        $schema->dropIfExists('categories');

        $schema->create('categories', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->softDeletes();
            NestedSet::columns($table);
        });

        Capsule::enableQueryLog();
    }

    public function setUp()
    {
        $data = include __DIR__.'/data/categories.php';

        Capsule::table('categories')->insert($data);

        Category::resetActionsPerformed();
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

        $actual = (array)Capsule::connection()->selectOne($sql);

        $this->assertEquals(array('errors' => null), $actual, "The tree structure of $table is broken!");
    }

    public function dumpTree($items = null)
    {
        if ( ! $items) $items = Category::withTrashed()->defaultOrder()->get();

        foreach ($items as $item)
        {
            echo PHP_EOL.($item->trashed() ? '-' : '+').' '.$item->name." ".$item->getLft()." ".$item->getRgt();
        }
    }

    public function assertNodeReceivesValidValues($node)
    {
        $lft = $node->getLft();
        $rgt = $node->getRgt();
        $nodeInDb = $this->findCategory($node->name);

        $this->assertEquals(
            [ $nodeInDb->getLft(), $nodeInDb->getRgt() ],
            [ $lft, $rgt ],
            'Node is not synced with database after save.'
        );
    }

    /**
     * @param $name
     *
     * @return \Kalnoy\Nestedset\Node
     */
    public function findCategory($name, $withTrashed = false)
    {
        $q = Category::whereName($name);

        if ($withTrashed) $q->withTrashed();

        return $q->first();
    }

    public function testTreeNotBroken()
    {
        $this->assertTreeNotBroken();
        $this->assertFalse(Category::isBroken());
    }

    public function nodeValues($node)
    {
        return array($node->_lft, $node->_rgt, $node->parent_id);
    }

    public function testGetsNodeData()
    {
        $data = Category::getNodeData(3);

        $this->assertEquals([ '_lft' => 3, '_rgt' => 4 ], $data);
    }

    public function testGetsPlainNodeData()
    {
        $data = Category::getPlainNodeData(3);

        $this->assertEquals([ 3, 4 ], $data);
    }

    public function testReceivesValidValuesWhenAppendedTo()
    {
        $node = new Category([ 'name' => 'test' ]);
        $root = Category::root();

        $accepted = array($root->_rgt, $root->_rgt + 1, $root->id);

        $root->appendNode($node);

        $this->assertTrue($node->hasMoved());
        $this->assertEquals($accepted, $this->nodeValues($node));
        $this->assertTreeNotBroken();
        $this->assertFalse($node->isDirty());
        $this->assertTrue($node->isDescendantOf($root));
    }

    public function testReceivesValidValuesWhenPrependedTo()
    {
        $root = Category::root();
        $node = new Category([ 'name' => 'test' ]);
        $root->prependNode($node);

        $this->assertTrue($node->hasMoved());
        $this->assertEquals(array($root->_lft + 1, $root->_lft + 2, $root->id), $this->nodeValues($node));
        $this->assertTreeNotBroken();
        $this->assertTrue($node->isDescendantOf($root));
        $this->assertTrue($root->isAncestorOf($node));
        $this->assertTrue($node->isChildOf($root));
    }

    public function testReceivesValidValuesWhenInsertedAfter()
    {
        $target = $this->findCategory('apple');
        $node = new Category([ 'name' => 'test' ]);
        $node->afterNode($target)->save();

        $this->assertTrue($node->hasMoved());
        $this->assertEquals(array($target->_rgt + 1, $target->_rgt + 2, $target->parent->id), $this->nodeValues($node));
        $this->assertTreeNotBroken();
        $this->assertFalse($node->isDirty());
        $this->assertTrue($node->isSiblingOf($target));
    }

    public function testReceivesValidValuesWhenInsertedBefore()
    {
        $target = $this->findCategory('apple');
        $node = new Category([ 'name' => 'test' ]);
        $node->beforeNode($target)->save();

        $this->assertTrue($node->hasMoved());
        $this->assertEquals(array($target->_lft, $target->_lft + 1, $target->parent->id), $this->nodeValues($node));
        $this->assertTreeNotBroken();
    }

    public function testCategoryMovesDown()
    {
        $node = $this->findCategory('apple');
        $target = $this->findCategory('mobile');

        $target->appendNode($node);

        $this->assertTrue($node->hasMoved());
        $this->assertNodeReceivesValidValues($node);
        $this->assertTreeNotBroken();
    }

    public function testCategoryMovesUp()
    {
        $node = $this->findCategory('samsung');
        $target = $this->findCategory('notebooks');

        $target->appendNode($node);

        $this->assertTrue($node->hasMoved());
        $this->assertTreeNotBroken();
        $this->assertNodeReceivesValidValues($node);
    }

    /**
     * @expectedException Exception
     */
    public function testFailsToInsertIntoChild()
    {
        $node = $this->findCategory('notebooks');
        $target = $node->children()->first();

        $node->afterNode($target)->save();
    }

    /**
     * @expectedException Exception
     */
    public function testFailsToAppendIntoItself()
    {
        $node = $this->findCategory('notebooks');

        $node->appendTo($node)->save();
    }

    /**
     * @expectedException Exception
     */
    public function testFailsToPrependIntoItself()
    {
        $node = $this->findCategory('notebooks');

        $node->prependTo($node)->save();
    }

    public function testWithoutRootWorks()
    {
        $result = Category::withoutRoot()->pluck('name');

        $this->assertNotEquals('store', $result);
    }

    public function testAncestorsReturnsAncestorsWithoutNodeItself()
    {
        $node = $this->findCategory('apple');
        $path = all($node->ancestors()->lists('name'));

        $this->assertEquals(array('store', 'notebooks'), $path);
    }

    public function testGetsAncestorsByStatic()
    {
        $path = all(Category::ancestorsOf(3)->lists('name'));

        $this->assertEquals(array('store', 'notebooks'), $path);
    }

    public function testGetsAncestorsDirect()
    {
        $path = all(Category::find(8)->getAncestors()->lists('id'));

        $this->assertEquals(array(1, 5, 7), $path);
    }

    public function testDescendants()
    {
        $node = $this->findCategory('mobile');
        $descendants = all($node->descendants()->lists('name'));
        $expected = array('nokia', 'samsung', 'galaxy', 'sony', 'lenovo');

        $this->assertEquals($expected, $descendants);

        $descendants = all($node->getDescendants()->lists('name'));

        $this->assertEquals(count($descendants), $node->getDescendantCount());
        $this->assertEquals($expected, $descendants);
    }

    public function testWithDepthWorks()
    {
        $nodes = all(Category::withDepth()->limit(4)->lists('depth'));

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
        $this->assertEquals(5, $node->getParentId());

        $node->parent_id = null;
        $node->save();

        $this->assertEquals(null, $node->parent_id);
        $this->assertTrue($node->isRoot());
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
        $node = $this->findCategory('mobile');
        $node->forceDelete();

        $this->assertTreeNotBroken();

        $nodes = Category::whereIn('id', array(5, 6, 7, 8, 9))->count();
        $this->assertEquals(0, $nodes);

        $root = Category::root();
        $this->assertEquals(8, $root->getRgt());
    }

    public function testNodeIsSoftDeleted()
    {
        $root = Category::root();

        $samsung = $this->findCategory('samsung');
        $samsung->delete();

        $this->assertTreeNotBroken();

        $this->assertNull($this->findCategory('galaxy'));

        sleep(1);

        $node = $this->findCategory('mobile');
        $node->delete();

        $nodes = Category::whereIn('id', array(5, 6, 7, 8, 9))->count();
        $this->assertEquals(0, $nodes);

        $originalRgt = $root->getRgt();
        $root->refreshNode();

        $this->assertEquals($originalRgt, $root->getRgt());

        $node = $this->findCategory('mobile', true);

        $node->restore();

        $this->assertNull($this->findCategory('samsung'));
    }

    public function testSoftDeletedNodeisDeletedWhenParentIsDeleted()
    {
        $this->findCategory('samsung')->delete();

        $this->findCategory('mobile')->forceDelete();

        $this->assertTreeNotBroken();

        $this->assertNull($this->findCategory('samsung', true));
        $this->assertNull($this->findCategory('sony'));
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

    public function testSiblings()
    {
        $node = $this->findCategory('samsung');
        $siblings = all($node->siblings()->lists('id'));
        $next = all($node->nextSiblings()->lists('id'));
        $prev = all($node->prevSiblings()->lists('id'));

        $this->assertEquals(array(6, 9, 10), $siblings);
        $this->assertEquals(array(9, 10), $next);
        $this->assertEquals(array(6), $prev);

        $siblings = all($node->getSiblings()->lists('id'));
        $next = all($node->getNextSiblings()->lists('id'));
        $prev = all($node->getPrevSiblings()->lists('id'));

        $this->assertEquals(array(6, 9, 10), $siblings);
        $this->assertEquals(array(9, 10), $next);
        $this->assertEquals(array(6), $prev);

        $next = $node->getNextSibling();
        $prev = $node->getPrevSibling();

        $this->assertEquals(9, $next->id);
        $this->assertEquals(6, $prev->id);
    }

    public function testFetchesReversed()
    {
        $node = $this->findCategory('sony');
        $siblings = $node->prevSiblings()->reversed()->value('id');

        $this->assertEquals(7, $siblings);
    }

    public function testToTreeBuildsWithDefaultOrder()
    {
        $tree = Category::whereBetween('_lft', array(8, 17))->defaultOrder()->get()->toTree();

        $this->assertEquals(1, count($tree));

        $root = $tree->first();
        $this->assertEquals('mobile', $root->name);
        $this->assertEquals(4, count($root->children));
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
        $this->assertEquals(4, count($root->children));
        $this->assertEquals($root, $root->children->first()->parent);
    }

    public function testToTreeWithSpecifiedRoot()
    {
        $node = $this->findCategory('mobile');
        $nodes = Category::whereBetween('_lft', array(8, 17))->get();

        $tree1 = \Kalnoy\Nestedset\Collection::make($nodes)->toTree(5);
        $tree2 = \Kalnoy\Nestedset\Collection::make($nodes)->toTree($node);

        $this->assertEquals(4, $tree1->count());
        $this->assertEquals(4, $tree2->count());
    }

    public function testToTreeBuildsWithDefaultOrderAndMultipleRootNodes()
    {
        $tree = Category::withoutRoot()->get()->toTree();

        $this->assertEquals(2, count($tree));
    }

    public function testToTreeBuildsWithRootItemIdProvided()
    {
        $tree = Category::whereBetween('_lft', array(8, 17))->get()->toTree(5);

        $this->assertEquals(4, count($tree));

        $root = $tree[1];
        $this->assertEquals('samsung', $root->name);
        $this->assertEquals(1, count($root->children));
    }

    public function testRetrievesNextNode()
    {
        $node = $this->findCategory('apple');
        $next = $node->nextNodes()->first();

        $this->assertEquals('lenovo', $next->name);
    }

    public function testRetrievesPrevNode()
    {
        $node = $this->findCategory('apple');
        $next = $node->prevNodes()->first();

        $this->assertEquals('notebooks', $next->name);
    }

    public function testMultipleAppendageWorks()
    {
        $parent = $this->findCategory('mobile');

        $child = new Category([ 'name' => 'test' ]);

        $parent->appendNode($child);

        $child->appendNode(new Category([ 'name' => 'sub' ]));

        $parent->appendNode(new Category([ 'name' => 'test2' ]));

        $this->assertTreeNotBroken();
    }

    public function testDefaultCategoryIsSavedAsRoot()
    {
        $node = new Category([ 'name' => 'test' ]);
        $node->save();

        $this->assertEquals(21, $node->_lft);
        $this->assertTreeNotBroken();

        $this->assertTrue($node->isRoot());
    }

    public function testExistingCategorySavedAsRoot()
    {
        $node = $this->findCategory('apple');
        $node->saveAsRoot();

        $this->assertTreeNotBroken();
        $this->assertTrue($node->isRoot());
    }

    public function testNodeMovesDownSeveralPositions()
    {
        $node = $this->findCategory('nokia');

        $this->assertTrue($node->down(2));

        $this->assertEquals($node->_lft, 15);
    }

    public function testNodeMovesUpSeveralPositions()
    {
        $node = $this->findCategory('sony');

        $this->assertTrue($node->up(2));

        $this->assertEquals($node->_lft, 9);
    }

    public function testCountsTreeErrors()
    {
        $errors = Category::countErrors();

        $this->assertEquals([ 'oddness' => 0, 'duplicates' => 0, 'wrong_parent' => 0 ], $errors);
    }

    public function testCreatesNode()
    {
        $node = Category::create([ 'name' => 'test' ]);

        $this->assertEquals(21, $node->getLft());
    }

    public function testCreatesViaRelationship()
    {
        $node = $this->findCategory('apple');

        $child = $node->children()->create([ 'name' => 'test' ]);

        $this->assertTreeNotBroken();
    }

    public function testCreatesTree()
    {
        $node = Category::create(
        [
            'name' => 'test',
            'children' =>
            [
                [ 'name' => 'test2' ],
                [ 'name' => 'test3' ],
            ],
        ]);

        $this->assertTreeNotBroken();
        $this->assertTrue(isset($node->children));
        $this->assertCount(2, $node->children);
    }

    public function testDescendantsOfNonExistingNode()
    {
        $node = new Category;

        $this->assertTrue($node->getDescendants()->isEmpty());
    }

    /**
     * @expectedException \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function testWhereDescendantsOf()
    {
        Category::whereDescendantOf(124)->get();
    }

    public function testAncestorsByNode()
    {
        $category = $this->findCategory('apple');
        $ancestors = all(Category::whereAncestorOf($category)->lists('id'));

        $this->assertEquals([ 1, 2 ], $ancestors);
    }

    public function testDescendantsByNode()
    {
        $category = $this->findCategory('notebooks');
        $res = all(Category::whereDescendantOf($category)->lists('id'));

        $this->assertEquals([ 3, 4 ], $res);
    }

    public function testMultipleDeletionsDoNotBrakeTree()
    {
        $category = $this->findCategory('mobile');

        foreach ($category->children()->take(2)->get() as $child)
        {
            $child->forceDelete();
        }

        $this->assertTreeNotBroken();
    }

    public function testTreeIsFixed()
    {
        Category::where('id', '=', 5)->update([ '_lft' => 14 ]);
        Category::where('id', '=', 8)->update([ 'parent_id' => 2 ]);

        $fixed = Category::fixTree();

        $this->assertTrue($fixed > 0);
        $this->assertTreeNotBroken();
    }
}

function all($items)
{
    return is_array($items) ? $items : $items->all();
}