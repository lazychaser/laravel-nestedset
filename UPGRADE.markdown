### Upgrading to 1.2

Calling `$parent->append($node)` and `$parent->prepend($node)` now automatically
saves `$node`. Those functions returns whether the node was saved.

`ancestorsOf` now return ancestors only not including target node.

Default order is not applied automatically.