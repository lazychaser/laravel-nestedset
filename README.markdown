[![Build Status](https://travis-ci.org/lazychaser/laravel-nestedset.svg?branch=master)](https://travis-ci.org/lazychaser/laravel-nestedset)
[![Total Downloads](https://poser.pugx.org/kalnoy/nestedset/downloads.svg)](https://packagist.org/packages/kalnoy/nestedset)
[![Latest Stable Version](https://poser.pugx.org/kalnoy/nestedset/v/stable.svg)](https://packagist.org/packages/kalnoy/nestedset)
[![Latest Unstable Version](https://poser.pugx.org/kalnoy/nestedset/v/unstable.svg)](https://packagist.org/packages/kalnoy/nestedset)
[![License](https://poser.pugx.org/kalnoy/nestedset/license.svg)](https://packagist.org/packages/kalnoy/nestedset)

This is a Laravel 4-5 package for working with trees in relational databases.

*   **Laravel 5.2, 5.3** is supported since v4
*   **Laravel 5.1** is supported in v3
*   **Laravel 4** is supported in v2

Although this project is completely free for use, I appreciate any support!

-   __[Donate via PayPal](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=5TJUM7FYU5VR2)__
-   My Visa: 4276 0700 1073 4244

__Contents:__

- [Theory](#what-are-nested-sets)
- [Documentation](#documentation)
    -   [Inserting nodes](#inserting-nodes)
    -   [Retrieving nodes](#retrieving-nodes)
    -   [Deleting nodes](#deleting-nodes)
    -   [Consistency checking & fixing](#checking-consistency)
    -   [Scoping](#scoping)
- [Requirements](#requirements)
- [Installation](#installation)

What are nested sets?
---------------------

Nested sets or [Nested Set Model](http://en.wikipedia.org/wiki/Nested_set_model) is
a way to effectively store hierarchical data in a relational table. From wikipedia:

> The nested set model is to number the nodes according to a tree traversal,
> which visits each node twice, assigning numbers in the order of visiting, and
> at both visits. This leaves two numbers for each node, which are stored as two
> attributes. Querying becomes inexpensive: hierarchy membership can be tested by
> comparing these numbers. Updating requires renumbering and is therefore expensive.

### Applications

NSM shows good performance when tree is updated rarely. It is tuned to be fast for
getting related nodes. It'is ideally suited for building multi-depth menu or
categories for shop.

Documentation
-------------

Suppose that we have a model `Category`; a `$node` variable is an instance of that model
and the node that we are manipulating. It can be a fresh model or one from database.

### Relationships

Node has following relationships that are fully functional and can be eagerly loaded:

-   Node belongs to `parent`
-   Node has many `children`
-   Node has many `descendants`

### Inserting nodes

Moving and inserting nodes includes several database queries, so __transaction is
automatically started__ when node is saved. It is safe to use global transaction
if you work with several models.

Another important note is that __structural manipulations are deferred__ until you
hit `save` on model (some methods implicitly call `save` and return boolean result
of the operation).

If model is successfully saved it doesn't mean that node was moved. If your application
depends on whether the node has actually changed its position, use `hasMoved` method:

```php
if ($node->save()) {
    $moved = $node->hasMoved();
}
```

#### Creating nodes

When you simply creating a node, it will be appended to the end of the tree:

```php
Category::create($attributes); // Saved as root
```

```php
$node = new Category($attributes);
$node->save(); // Saved as root
```

In this case the node is considered a _root_ which means that it doesn't have a parent.

#### Making a root from existing node

```php
// #1 Implicit save
$node->saveAsRoot();

// #2 Explicit save
$node->makeRoot()->save();
```

The node will be appended to the end of the tree.

#### Appending and prepending to the specified parent

If you want to make node a child of other node, you can make it last or first child.

*In following examples, `$parent` is some existing node.*

There are few ways to append a node:

```php
// #1 Using deferred insert
$node->appendToNode($parent)->save();

// #2 Using parent node
$parent->appendNode($node);

// #3 Using parent's children relationship
$parent->children()->create($attributes);

// #5 Using node's parent relationship
$node->parent()->associate($parent)->save();

// #6 Using the parent attribute
$node->parent_id = $parent->id;
$node->save();

// #7 Using static method
Category::create($attributes, $parent);
```

And only a couple ways to prepend:

```php
// #1
$node->prependToNode($parent)->save();

// #2
$parent->prependNode($node);
```

#### Inserting before or after specified node

You can make `$node` to be a neighbor of the `$neighbor` node using following methods:

*`$neighbor` must exists, target node can be fresh. If target node exists,
it will be moved to the new position and parent will be changed if it's required.*

```php
# Explicit save
$node->afterNode($neighbor)->save();
$node->beforeNode($neighbor)->save();

# Implicit save
$node->insertAfterNode($neighbor);
$node->insertBeforeNode($neighbor);
```

#### Building a tree from array

When using static method `create` on node, it checks whether attributes contains
`children` key. If it does, it creates more nodes recursively.

```php
$node = Category::create([
    'name' => 'Foo',

    'children' => [
        [
            'name' => 'Bar',

            'children' => [
                [ 'name' => 'Baz' ],
            ],
        ],
    ],
]);
```

`$node->children` now contains a list of created child nodes.

#### Rebuilding a tree from array

You can easily rebuild a tree. This is useful for mass-changing the structure of
the tree.

```php
Category::rebuildTree($data, $delete);
```

`$data` is an array of nodes:

```php
$data = [
    [ 'id' => 1, 'name' => 'foo', 'children' => [ ... ] ],
    [ 'name' => 'bar' ],
];
```

There is an id specified for node with the name of `foo` which means that existing
node will be filled and saved. If node is not exists `ModelNotFoundException` is
thrown. Also, this node has `children` specified which is also an array of nodes;
they will be processed in the same manner and saved as children of node `foo`.

Node `bar` has no primary key specified, so it will be created.

`$delete` shows whether to delete nodes that are already exists but not present
in `$data`. By default, nodes aren't deleted.

### Retrieving nodes

*In some cases we will use an `$id` variable which is an id of the target node.*

#### Ancestors

Ancestors make a chain of parents to the node. Helpful for displaying breadcrumbs
to the current category.

```php
// #1 Using accessor
$result = $node->getAncestors();

// #2 Using a query
$result = $node->ancestors()->get();

// #3 Getting ancestors by primary key
$result = Category::ancestorsOf($id);
```

#### Descendants

Descendants are all nodes in a sub tree, i.e. children of node, children of
children, etc.

```php
// #1 Using relationship
$result = $node->descendants;

// #2 Using a query
$result = $node->descendants()->get();

// #3 Getting descendants by primary key
$result = Category::descendantsOf($id);
```

Descendants can be eagerly loaded:

```php
$nodes = Category::with('descendants')->whereIn('id', $idList)->get();
```

#### Siblings

Siblings are nodes that have same parent.

```php
$result = $node->getSiblings();

$result = $node->siblings()->get();
```

To get only next siblings:

```php
// Get a sibling that is immediately after the node
$result = $node->getNextSibling();

// Get all siblings that are after the node
$result = $node->getNextSiblings();

// Get all siblings using a query
$result = $node->nextSiblings()->get();
```

To get previous siblings:

```php
// Get a sibling that is immediately before the node
$result = $node->getPrevSibling();

// Get all siblings that are before the node
$result = $node->getPrevSiblings();

// Get all siblings using a query
$result = $node->prevSiblings()->get();
```

#### Getting related models from other table

Imagine that each category `has many` goods. I.e. `HasMany` relationship is established.
How can you get all goods of `$category` and every its descendant? Easy!

```php
// Get ids of descendants
$categories = $category->descendants()->pluck('id');

// Include the id of category itself
$categories[] = $category->getKey();

// Get goods
$goods = Goods::whereIn('category_id', $categories)->get();
```

#### Including node depth

If you need to know at which level the node is:

```php
$result = Category::withDepth()->find($id);

$depth = $result->depth;
```

Root node will be at level 0. Children of root nodes will have a level of 1, etc.

To get nodes of specified level, you can apply `having` constraint:

```php
$result = Category::withDepth()->having('depth', '=', 1)->get();
```

#### Default order

Each node has it's own unique `_lft` value that determines its position in the tree. If
you want node to be ordered by this value, you can use `defaultOrder` method on
the query builder:

```php
// All nodes will now be ordered by lft value
$result = Category::defaultOrder()->get();
```

You can get nodes in reversed order:

```php
$result = Category::reversed()->get();
```

##### Shifting a node

To shift node up or down inside parent to affect default order:

```php
$bool = $node->down();
$bool = $node->up();

// Shift node by 3 siblings
$bool = $node->down(3);
```

The result of the operation is boolean value of whether the node has changed its
position.

#### Constraints

Various constraints that can be applied to the query builder:

-   __whereIsRoot()__ to get only root nodes;
-   __whereIsAfter($id)__ to get every node (not just siblings) that are after a node
    with specified id;
-   __whereIsBefore($id)__ to get every node that is before a node with specified id.

Descendants constraints:

```php
$result = Category::whereDescendantOf($node)->get();
$result = Category::whereNotDescendantOf($node)->get();
$result = Category::orWhereDescendantOf($node)->get();
$result = Category::orWhereNotDescendantOf($node)->get();
```

Ancestor constraints:

```php
$result = Category::whereAncestorOf($node)->get();
```

`$node` can be either a primary key of the model or model instance.

#### Building a tree

After getting a set of nodes, you can convert it to tree. For example:

```php
$tree = Category::get()->toTree();
```

This will fill `parent` and `children` relationships on every node in the set and
you can render a tree using recursive algorithm:

```php
$nodes = Category::get()->toTree();

$traverse = function ($categories, $prefix = '-') use (&$traverse) {
    foreach ($categories as $category) {
        echo PHP_EOL.$prefix.' '.$category->name;

        $traverse($category->children, $prefix.'-');
    }
};

$traverse($nodes);
```

This will output something like this:

```
- Root
-- Child 1
--- Sub child 1
-- Child 2
- Another root
```

##### Building a tree except himself and his descendants

Avoid `LogicException: Node must not be a descendant.`

```php
$node = Category::find($id);

$tree = Category::where(function($query) use ($category){
                    $query->orWhere('_lft', '<', $$node->_lft)
                          ->orWhere('_rgt', '>', $$node->_rgt);
                })
                ->get()
                ->toTree();
```

##### Building flat tree

Also, you can build a flat tree: a list of nodes where child nodes are immediately
after parent node. This is helpful when you get nodes with custom order
(i.e. alphabetically) and don't want to use recursion to iterate over your nodes.

```php
$nodes = Category::get()->toFlatTree();
```

##### Getting a subtree

Sometimes you don't need whole tree to be loaded and just some subtree of specific node.
It is show in following example:

```php
$root = Category::find($rootId);
$tree = $root->descendants->toTree($root);
```

Now `$tree` contains children of `$root` node.

If you don't need `$root` node itself, do following instead:

```php
$tree = Category::descendantsOf($rootId)->toTree($rootId);
```

### Deleting nodes

To delete a node:

```php
$node->delete();
```

**IMPORTANT!** Any descendant that node has will also be deleted!

**IMPORTANT!** Nodes are required to be deleted as models, **don't** try do delete them using a query like so:

```php
Category::where('id', '=', $id)->delete();
```

This will break the tree!

`SoftDeletes` trait is supported, also on model level.

### Helper methods

To check if node is a descendant of other node:

```php
$bool = $node->isDescendantOf($parent);
```

To check whether the node is a root:

```php
$bool = $node->isRoot();
```

Other checks:

*   `$node->isChildOf($other);`
*   `$node->isAncestorOf($other);`
*   `$node->isSiblingOf($other);`

### Checking consistency

You can check whether a tree is broken (i.e. has some structural errors):

```php
$bool = Category::isBroken();
```

It is possible to get error statistics:

```php
$data = Category::countErrors();
```

It will return an array with following keys:

-   `oddness` -- the number of nodes that have wrong set of `lft` and `rgt` values
-   `duplicates` -- the number of nodes that have same `lft` or `rgt` values
-   `wrong_parent` -- the number of nodes that have invalid `parent_id` value that
    doesn't correspond to `lft` and `rgt` values
-   `missing_parent` -- the number of nodes that have `parent_id` pointing to
    node that doesn't exists

#### Fixing tree

Since v3.1 tree can now be fixed. Using inheritance info from `parent_id` column,
proper `_lft` and `_rgt` values are set for every node.

```php
Node::fixTree();
```

### Scoping

Imagine you have `Menu` model and `MenuItems`. There is a one-to-many relationship
set up between these models. `MenuItem` has `menu_id` attribute for joining models
together. `MenuItem` incorporates nested sets. It is obvious that you would want to
process each tree separately based on `menu_id` attribute. In order to do so, you
need to specify this attribute as scope attribute:

```php
protected function getScopeAttributes()
{
    return [ 'menu_id' ];
}
```

But now in order to execute some custom query, you need to provide attributes
that are used for scoping:

```php
MenuItem::scoped([ 'menu_id' => 5 ])->withDepth()->get(); // OK
MenuItem::descendantsOf($id)->get(); // WRONG: returns nodes from other scope
MenuItem::scoped([ 'menu_id' => 5 ])->fixTree();
```

When requesting nodes using model instance, scopes applied automatically based
on the attributes of that model. See examples:

```php
$node = MenuItem::findOrFail($id);

$node->siblings()->withDepth()->get(); // OK
```

To get scoped query builder using instance:

```php
$node->newScopedQuery();
```

Note, that scoping is not required when retrieving model by primary key
(since the key is unique):

```php
$node = MenuItem::findOrFail($id); // OK
$node = MenuItem::scoped([ 'menu_id' => 5 ])->findOrFail(); // OK, but redundant
```

Requirements
------------

- PHP >= 5.4
- Laravel >= 4.1

It is highly suggested to use database that supports transactions (like MySql's InnoDb)
to secure a tree from possible corruption.

Installation
------------

To install the package, in terminal:

```
composer require kalnoy/nestedset
```

### Setting up from scratch

#### The schema

You can use a method to add needed columns with default names:

```php
Schema::create('table', function (Blueprint $table) {
    ...
    NestedSet::columns($table);
});
```

To drop columns:

```php
Schema::table('table', function (Blueprint $table) {
    NestedSet::dropColumns($table);
});
```

#### The model

Your model should use `Kalnoy\Nestedset\NodeTrait` trait to enable nested sets:

```php
use Kalnoy\Nestedset\NodeTrait;

class Foo extends Model {
    use NodeTrait;
}
```

### Migrating existing data

#### Migrating from other nested set extension

If your previous extension used different set of columns, you just need to override
following methods on your model class:

```php
public function getLftName()
{
    return 'left';
}

public function getRgtName()
{
    return 'right';
}

public function getParentIdName()
{
    return 'parent';
}

// Specify parent id attribute mutator
public function setParentAttribute($value)
{
    $this->setParentIdAttribute($value);
}
```

#### Migrating from basic parentage info

If your tree contains `parent_id` info, you need to add two columns to your schema:

```php
$table->unsignedInteger('_lft');
$table->unsignedInteger('_rgt');
```

After [setting up your model](#the-model) you only need to fix the tree to fill
`_lft` and `_rgt` columns:

```php
MyModel::fixTree();
```

License
=======

Copyright (c) 2016 Alexander Kalnoy

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
