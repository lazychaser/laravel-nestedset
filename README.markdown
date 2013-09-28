This is Laravel 4 package that simplifies creating, managing and retrieving trees
in database. Using [Nested Set](http://en.wikipedia.org/wiki/Nested_set_model) 
technique high performance descendants retrieval and path-to-node queries can be done.

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

### Insertion, re-insertion and deletion of nodes

Operations such as insertion and deletion of nodes imply several independent queries
before node is actually saved. That is why if something goes wrong, the whole tree
might be broken. To avoid such situations each call to `save()` must be enclosed 
into transaction.

Also, experimentally was noticed that using transaction drastically improves
performance when tree gets update.

## Advanced usage

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
