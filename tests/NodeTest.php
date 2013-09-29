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

        $this->assertEquals(array('errors' => null), $actual, 'The tree structure of $table is broken!');
    }

    public function findCategory($name)
    {
        return Category::whereName($name)->first();
    }

    public function testTreeNotBroken()
    {
        $this->assertTreeNotBroken();
    }

    public function testNewCategoryAppendsTo()
    {
        $node = new Category(array('name' => 'LG'));
        $target = $this->findCategory('mobile');

        $expectedLft = $target->_rgt;

        $node->appendTo($target)->save();

        $this->assertTreeNotBroken();
        $this->assertEquals($target->id, $node->parent_id);
        $this->assertEquals($expectedLft, $node->_lft);
    }

    public function testNewCategoryPrependsTo()
    {
        $node = new Category(array('name' => 'LG'));
        $target = $this->findCategory('mobile');

        $expectedLft = $target->_lft + 1;

        $node->prependTo($target)->save();

        $this->assertTreeNotBroken();
        $this->assertEquals($target->id, $node->parent_id);
        $this->assertEquals($expectedLft, $node->_lft);
    }

    public function testNewCategoryInsertsAfter()
    {
        $node = new Category(array('name' => 'acer'));
        $target = $this->findCategory('lenovo');

        $expectedLft = $target->_rgt + 1;

        $node->after($target)->save();

        $this->assertTreeNotBroken();
        $this->assertEquals($target->parent->id, $node->parent_id);
        $this->assertEquals($target->_rgt + 1, $node->_lft);
    }

    public function testNewCategoryInsertsBefore()
    {
        $node = new Category(array('name' => 'acer'));
        $target = $this->findCategory('lenovo');

        $expectedLft = $target->_lft;

        $node->before($target)->save();

        $this->assertTreeNotBroken();
        $this->assertEquals($target->parent->id, $node->parent_id);
        $this->assertEquals($expectedLft, $node->_lft);
    }

    public function testCategoryAppendsTo()
    {
        $node = $this->findCategory('apple');
        $target = $this->findCategory('mobile');

        $node->appendTo($target);

        $this->assertTreeNotBroken();
    }

    public function testCategoryPrependsTo()
    {
        $node = $this->findCategory('apple');
        $target = $this->findCategory('mobile');

        $node->prepend($target);

        $this->assertTreeNotBroken();
    }

    public function testCategoryInsertsAfter()
    {
        $node = $this->findCategory('apple');
        $target = $this->findCategory('nokia');

        $node->after($target);

        $this->assertTreeNotBroken();
    }

    public function testCategoryInsertsBefore()
    {
        $node = $this->findCategory('apple');
        $target = $this->findCategory('nokia');

        $node->before($target);

        $this->assertTreeNotBroken();
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

    public function testRootDoesNotGetsDeleted()
    {
        $result = Category::root()->delete();

        $this->assertFalse($result);
    }

    public function testWithoutRootWorks()
    {
        $result = Category::withoutRoot()->count();

        $this->assertEquals(6, $result);
    }

    public function testPathReturnsAncestorsWithoutNodeItself()
    {
        $node = $this->findCategory('apple');
        $path = $node->path()->lists('name');

        $this->assertEquals(array('store', 'notebooks'), $path);
    }

    public function testPathToReturnsAncestorsWithNode()
    {
        $path = Category::pathTo(3)->lists('name');

        $this->assertEquals(array('store', 'notebooks', 'apple'), $path);
    }

    public function testDescendantsQueried()
    {
        $descendants = Category::root()->descendants()->count();

        $this->assertEquals(6, $descendants);
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

    public function testParentIdAttributeAccessorAppendsNode()
    {
        $node = new Category(array('name' => 'lg', 'parent_id' => 5));
        $node->save();

        $this->assertEquals(5, $node->parent_id);
    }

    public function testNodeIsNotSavedUntilNotInserted()
    {
        $node = new Category;
        $this->assertFalse($node->save());
    }

    public function testNodeIsDeletedWithDescendants()
    {
        $node = $this->findCategory('notebooks');
        $this->assertTrue($node->delete());

        $this->assertTreeNotBroken();

        $nodes = Category::whereIn('id', array(2, 3, 4))->count();
        $this->assertEquals(0, $nodes);
    }

    public function testSavingDeletedNodeWithoutInsertingFails()
    {
        $node = $this->findCategory('apple');
        $this->assertTrue($node->delete());
        $this->assertFalse($node->save());
    }
}