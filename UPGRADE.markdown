### Upgrading to 3.0

Some methods were renamed, see changelog for more details.

### Upgrading to 2.0

Calling `$parent->append($node)` and `$parent->prepend($node)` now automatically
saves `$node`. Those functions returns whether the node was saved.

`ancestorsOf` now return ancestors only, not including target node.

Default order is not applied automatically, so if you need nodes to be in tree-order
you should call `defaultOrder` on the query.

Since root node is not required now, `NestedSet::createRoot` method has been removed.

`NestedSet::columns` now doesn't create a foreign key for a `parent_id` column.