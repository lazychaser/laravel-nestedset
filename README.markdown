This is Laravel 4 package that simplifies creating, managing and retrieving trees
in database. Using [Nested Set](http://en.wikipedia.org/wiki/Nested_set_model) 
technique high performance descendants retrieval and path-to-node queries can be done.

__IMPORTANT!__ To keep realization of Nested Set Model simple, it's made to work
within single HTTP requests. Don't build trees in your code. [But, it's possible](#multiple-node-insertion).

## Installation

The package can be installed as Composer package, just include it into 
`required` section of your `composer.json` file:

    "required": {
        "kalnoy/nestedset": "dev-master"
    }

## Basic usage

### Schema

Storing trees in database requires additional columns for the table, so these
fields need to be included in table schema. We use `NestedSet::columns($table)`
inside table schema creation function, like so:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Kalnoy\Nestedset\NestedSet;

class CreateCategoriesTable extends Migration {

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('categories', function(Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->timestamps();

            NestedSet::columns($table);
        });

        NestedSet::createRoot('categories', array(
            'title' => 'Root',
        ));
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::drop('categories');
    }
}
```

To simplify things root node is required. `NestedSet::createRoot` creates it for us.

### The model

The next step is to create `Eloquent` model. Do it whatever way you like, but
make shure that model is extended from `\Kalnoy\Nestedset\Node`, like here:

```php
<?php

class Category extends \Kalnoy\Nestedset\Node {}
```

### Queries

You can create nodes like so:

```php
$node = new Category(array('title' => 'TV\'s'));
$node->appendTo(Category::root())->save();
```

the same thing can be done differently (to allow changing parent via mass assignment):

```php
$node->parent_id = $parent_id;
$node->save();
```

You can insert the node right next to or before the other node:

```php
$srcNode = Category::find($src_id);
$targetNode = Category::find($target_id);

$srcNode->after($targetNode)->save();
$srcNode->before($targetNode)->save();
```

Path to the node can be obtained in two ways:

```php
// Target node will not be included into result since it is already available
$path = $node->path()->get();
```

or using the scope:

```php
// Target node will be included into result
$path = Category::pathTo($nodeId)->get();
```

Descendant nodes can easily be gotten this way:

```php
$descendants = $node->descendants()->get();
```

Nodes can be provided with depth level if scope `withDepth` is applied:

```php
// Each node instance will recieve 'depth' attribute with depth level starting at
// zero for the root node.
$nodes = Category::withDepth()->get();
```

Query can be filtered out from the root node using scope `withoutRoot`:

```php
$nodes = Category::withoutRoot()->get();
```

Deleting nodes is as simple as before:

```php
$node->delete();
```

### Relations

There are two relations provided by `Node`: _children_ and _parent_.

### Insertion, re-insertion and deletion of nodes

Operations such as insertion and deletion of nodes imply several independent queries
before node is actually saved. That is why if something goes wrong, the whole tree
might be broken. To avoid such situations each call to `save()` must be enclosed 
into transaction.

Also, experimentally was noticed that using transaction drastically improves
performance when tree gets update.

## Advanced usage

### Custom collection

This package also provides custom collection, which has two additional functions:
`toDictionary` and `toTree`. The latter builds a tree from the list of nodes just like
if you would query only root node with all of the children, and children of that
children, etc. This function restores parent-child relations, so the resulting collection
will contain only top-level nodes and each of this node will have `children` relation
filled. The interesting thing is that when some node is rejected by a query constraint,
whole subtree will be rejected during building the tree.

Consider the tree of categories:

```
Catalog
- Mobile
-- Apple
-- Samsung
- Notebooks
-- Netbooks
--- Apple
--- Samsung
-- Ultrabooks
```

Let's see what we have in PHP:

```php
$tree = Category::where('title', '<>', 'Netbooks')->withoutRoot()->get()->toTree();
echo $tree;
```

This is what we are going to get:

```js
[{
    "title": "Mobile",
    "children": [{ "title": "Apple", "children": [] }, { "title": "Samsung", "children": [] }]
},

{
    "title": "Notebooks",
    "children": [{ "title": "Ultrabooks", "children": [] }]
}];
```

Even though the query returned all nodes but _Netbooks_, the resulting tree does not contain any
child from that node. This is very helpful when nodes are soft deleted. Active children of soft 
deleted nodes will inevitably show up in query results, which is not desired in most situations.

### Multiple node insertion

_DO NOT MAKE MULTIPLE INSERTIONS DURING SINGLE HTTP REQUEST_

Since when node is inserted or re-inserted tree is changed in database, nodes
that are already loaded might also have changed and need to be refreshed. This
doesn't happen automatically with exception of one scenario.

Consider this example:

```php
$nodes = Category::whereIn('id', Input::get('selected_ids'))->get();
$target = Category::find(Input::get('target_id'));

foreach ($nodes as $node) {
    $node->appendTo($target)->save();
}
```

This is the example of situation when user picks up several nodes and moves them
into new parent. When we call `appendTo` nothing is really changed but internal
variables. Actual transformations are performed when `save` is called. When that
happens, values of internal variables are definately changed for `$target` and
might change for some nodes in `$nodes` list. But this changes happen in database
and do not reflect into memory for loaded nodes. Calling `appendTo` with outdated 
values brakes the tree.

In this case only values of `$target` are crucial. The system always updates crucial
attributes of parent of node being saved. Since `$target` becomes new parent for
every node, the data of that node will always be up to date and this example will
work just fine.

_THIS IS THE ONLY CASE WHEN MULTIPLE NODES CAN BE INSERTED AND/OR RE-INSERTED 
DURING SINGLE HTTP REQUEST WITHOUT REFRESHING DATA_

#### If you still need this

If you are up to create your tree structure in your code, make shure that target node 
is always updated. Here is the description of what nodes are target when using insertion
functions:

```php
/**
 * @var Category $node The node being inserted
 * @var Category $target The target node
 */
 
$node->appendTo($target);
$node->prependTo($target);
$node->before($target);
$node->after($target);
$target->append($node);
$target->prepend($node);
```

When doing multiple insertions, just call `$target->refresh()` each time before calling 
any of the above functions.

```php
DB::transaction(function () {
    $node = new Category(...);
    $root = Category::root();
    
    // The root here is updated automatically
    $node->appendTo($root)->save();
    
    $nodeSubNode = new Category(...);
    // No need to update $node since it is just saved
    // Also, $node gets update since it is new parent for $nodeSubNode
    $nodeSubNode->appendTo($node)->save();
    
    $nodeSibling = new Category(...);
    // We refresh $root because it is not updated since last operation
    $nodeSibling->appendTo($root->refresh())->save();
});
```

### Deleting nodes

To delete a node, you just call `$node->delete()` as usual. If node is soft deleted, 
nothing happens. But if node is hard deleted, tree updates. But what if this node has
children?

When you create your table's schema and use `NestedSet::columns`, it creates foreign
key for you, since nodes are connected by `parent_id` attribute. When you hard delete
the node, all of descendants are cascaded.

In case when DBMS doesn't support foreign keys, descendants are removed manually.