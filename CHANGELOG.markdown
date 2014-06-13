### 1.2.0

*   Added `insertAfter`, `insertBefore` methods.
*   `prepend` and `append` methods now save target model.
*   You can now call `refreshNode` to make sure that node has updated structural
    data (lft and rgt values).
*   The root node is not required now. You can use `saveAsRoot` or `makeRoot` method.
    New model is saved as root by default.
*   You can now create as many nodes and in any order as possible within single 
    request.
*   Laravel 2 is supported but not required.
*   `ancestorsOf` now doesn't include target node into results.
*   New constraint methods `hasParent` and `hasChildren`.
*   New method `isDescendantOf` that checks if node is a descendant of other node.
*   Default order is not applied by default.
*   New method `descendantsOf` that allows to get descendants by id of the node.
*   Added `countErrors` and `isBroken` methods to check whether the tree is broken.

### 1.1.0

*   `Collection::toDictionary` is now obsolete. Use `Collection::groupBy`.
*   Laravel 4.2 is required