### 4.3.4
*   Support Laravel 5.8

### 4.3.3
*   Support Laravel 5.7

### 4.3.2
*   Support Laravel 5.6
*   Added `nestedSet` and `dropNestedSet` blueprint macros

### 4.3.0
*   Support Laravel 5.5
*   Added `fixSubtree` and `rebuildSubtree` methods
*   Increased performance of tree rebuilding

### 4.2.7

*   #217: parent_id, lft and rgt are reset when replicating a node

### 4.2.5

*   #208: dirty parent and bounds when making a root
*   #206: fixed where has on descendants
*   refactored ancestors and descendants relations

### 4.2.4

*   Fixed issues related to rebuilding tree when using `SoftDeletes` and scoping

### 4.2.3

*   Added `whereAncestorOrSelf`, `ancestorsAndSelf`, `descendantsOrSelf`,
    `whereDescendantOrSelf` helper methods
*   #186: rebuild tree removes nodes softly when model uses SoftDeletes trait
*   #191: added `whereIsLeaf` and `leaves` method, added `isLeaf` check on node

### 4.1.0

*   #75: Converted to trait. Following methods were renamed:
    -   `appendTo` to `appendToNode`
    -   `prependTo` to `prependToNode`
    -   `insertBefore` to `insertBeforeNode`
    -   `insertAfter` to `insertAfterNode`
    -   `getNext` to `getNextNode`
    -   `getPrev` to `getPrevNode`
*   #82: Fixing tree now handles case when nodes pointing to non-existing parent
*   The number of missing parent is now returned when using `countErrors`
*   #79: implemented scoping feature
*   #81: moving node now makes model dirty before saving it
*   #45: descendants is now a relation that can be eagerly loaded
*   `hasChildren` and `hasParent` are now deprecated. Use `has('children')`
    `has('parent')` instead
*   Default order is no longer applied for `siblings()`, `descendants()`,
    `prevNodes`, `nextNodes`
*   #50: implemented tree rebuilding feature
*   #85: added tree flattening feature

### 3.1.1

*   Fixed #42: model becomes dirty before save when parent is changed and using `appendTo`,
    `prependTo`, `insertBefore`, `insertAfter`.

### 3.1.0

*   Added `fixTree` method for fixing `lft`/`rgt` values based on inheritance
*   Dropped support of Laravel < 5.1
*   Improved compatibility with different databases

### 3.0.0

*   Support Laravel 5.1.9
*   Renamed `append` to `appendNode`, `prepend` to `prependNode`
*   Renamed `next` to `nextNodes`, `prev` to `prevNodes`
*   Renamed `after` to `afterNode`, `before` to `beforeNode`

### 2.4.0

*   Added query methods `whereNotDescendantOf`, `orWhereDescendantOf`, `orWhereNotDescendantOf`
*   `whereAncestorOf`, `whereDescendantOf` and every method that depends on them can now accept node instance
*   Added `Node::getBounds` that returns an array of node bounds that can be used in `whereNodeBetween`

### 2.3.0

*   Added `linkNodes` method to `Collection` class

### 2.2.0

*   Support Laravel 5

### 2.1.0

*   Added `isChildOf`, `isAncestorOf`, `isSiblingOf` methods

### 2.0.0

*   Added `insertAfter`, `insertBefore` methods.
*   `prepend` and `append` methods now save target model.
*   You can now call `refreshNode` to make sure that node has updated structural
    data (lft and rgt values).
*   The root node is not required now. You can use `saveAsRoot` or `makeRoot` method.
    New model is saved as root by default.
*   You can now create as many nodes and in any order as you want within single
    request.
*   Laravel 2 is supported but not required.
*   `ancestorsOf` now doesn't include target node into results.
*   New constraint methods `hasParent` and `hasChildren`.
*   New method `isDescendantOf` that checks if node is a descendant of other node.
*   Default order is not applied by default.
*   New method `descendantsOf` that allows to get descendants by id of the node.
*   Added `countErrors` and `isBroken` methods to check whether the tree is broken.
*   `NestedSet::createRoot` has been removed.
*   `NestedSet::column` doesn't create a foreign key anymore.

### 1.1.0

*   `Collection::toDictionary` is now obsolete. Use `Collection::groupBy`.
*   Laravel 4.2 is required
