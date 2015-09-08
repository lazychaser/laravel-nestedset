<?php

namespace Kalnoy\Nestedset;

use Exception;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class Node extends Eloquent {

    /**
     * The name of "lft" column.
     *
     * @var string
     */
    const LFT = '_lft';

    /**
     * The name of "rgt" column.
     *
     * @var string
     */
    const RGT = '_rgt';

    /**
     * The name of "parent id" column.
     *
     * @var string
     */
    const PARENT_ID = 'parent_id';

    /**
     * Insert direction.
     *
     * @var string
     */
    const BEFORE = 'before';

    /**
     * Insert direction.
     *
     * @var string
     */
    const AFTER = 'after';

    /**
     * Whether model uses soft delete.
     *
     * @var bool
     *
     * @since 1.1
     */
    static protected $_softDelete;

    /**
     * Whether the node is being deleted.
     *
     * @since 2.0
     *
     * @var bool
     */
    static protected $deleting;

    /**
     * Pending operation.
     *
     * @var array
     */
    protected $pending = [ 'root' ];

    /**
     * Whether the node has moved since last save.
     *
     * @var bool
     */
    protected $moved = false;

    /**
     * @var \Carbon\Carbon
     */
    protected static $deletedAt;

    /**
     * Keep track of the number of performed operations.
     *
     * @var int
     */
    protected static $actionsPerformed = 0;

    /**
     * {@inheritdoc}
     */
    protected static function boot()
    {
        parent::boot();

        static::$_softDelete = static::getIsSoftDelete();

        static::signOnEvents();
    }

    /**
     * Get whether model uses soft delete.
     *
     * @return bool
     */
    protected static function getIsSoftDelete()
    {
        $instance = new static;

        return method_exists($instance, 'withTrashed');
    }

    /**
     * Sign on model events.
     */
    protected static function signOnEvents()
    {
        static::saving(function (Node $model)
        {
            return $model->callPendingAction();
        });

        static::deleting(function (Node $model)
        {
            // We will need fresh data to delete node safely
            $model->refreshNode();
        });

        static::deleted(function (Node $model)
        {
            $model->deleteDescendants();
        });

        if (static::$_softDelete)
        {
            static::restoring(function (Node $model)
            {
                static::$deletedAt = $model->{$model->getDeletedAtColumn()};
            });

            static::restored(function (Node $model)
            {
                $model->restoreDescendants(static::$deletedAt);
            });
        }
    }

    /**
     * {@inheritdoc}
     *
     * Saves a node in a transaction.
     */
    public function save(array $options = array())
    {
        return $this->getConnection()->transaction(function () use ($options)
        {
            return parent::save($options);
        });
    }

    /**
     * {@inheritdoc}
     *
     * Delete a node in transaction if model is not soft deleting.
     */
    public function delete()
    {
        return $this->getConnection()->transaction(function ()
        {
            return parent::delete();
        });
    }

    /**
     * Set an action.
     *
     * @param string $action
     *
     * @return $this
     */
    protected function setAction($action)
    {
        $this->pending = func_get_args();

        return $this;
    }

    /**
     * Clear pending action.
     */
    protected function clearAction()
    {
        $this->pending = null;
    }

    /**
     * Call pending action.
     *
     * @return null|false
     */
    protected function callPendingAction()
    {
        $this->moved = false;

        if ( ! $this->pending) return;

        $method = 'action'.ucfirst(array_shift($this->pending));
        $parameters = $this->pending;

        $this->pending = null;

        $this->moved = call_user_func_array([ $this, $method ], $parameters);
    }

    /**
     * Make a root node.
     */
    protected function actionRoot()
    {
        // Simplest case that do not affect other nodes.
        if ( ! $this->exists)
        {
            $cut = $this->getLowerBound() + 1;

            $this->setLft($cut);
            $this->setRgt($cut + 1);

            return true;
        }

        if ($this->isRoot()) return false;

        // Reset parent object
        $this->setParent(null);

        return $this->insertAt($this->getLowerBound() + 1);
    }

    /**
     * Get the lower bound.
     *
     * @return int
     */
    protected function getLowerBound()
    {
        return (int)$this->newServiceQuery()->max($this->getRgtName());
    }

    /**
     * Append a node to the parent.
     *
     * @param Node $parent
     *
     * @return bool
     */
    protected function actionAppendTo(Node $parent)
    {
        return $this->actionAppendOrPrepend($parent);
    }

    /**
     * Prepend a node to the parent.
     *
     * @param Node $parent
     *
     * @return bool
     */
    protected function actionPrependTo(Node $parent)
    {
        return $this->actionAppendOrPrepend($parent, true);
    }

    /**
     * Append or prepend a node to the parent.
     *
     * @param Node $parent
     * @param bool $prepend
     *
     * @return bool
     */
    protected function actionAppendOrPrepend(Node $parent, $prepend = false)
    {
        if ( ! $parent->exists)
        {
            throw new LogicException('Cannot use non-existing node as a parent.');
        }

        $this->setParent($parent);

        $parent->refreshNode();

        if ($this->insertAt($prepend ? $parent->getLft() + 1 : $parent->getRgt()))
        {
            $parent->refreshNode();

            return true;
        }

        return false;
    }

    /**
     * Apply parent model.
     *
     * @param Node|null $value
     */
    protected function setParent($value)
    {
        $this->attributes[$this->getParentIdName()] = $value ? $value->getKey() : null;

        $this->setRelation('parent', $value);
    }

    /**
     * Insert node before or after another node.
     *
     * @param Node $node
     * @param bool $after
     *
     * @return bool
     */
    protected function actionBeforeOrAfter(Node $node, $after = false)
    {
        if ( ! $node->exists)
        {
            throw new LogicException('Cannot insert before/after a node that does not exists.');
        }

        if ( ! $this->isSiblingOf($node))
        {
            $this->setParent($node->getAttribute('parent'));
        }

        $node->refreshNode();

        return $this->insertAt($after ? $node->getRgt() + 1 : $node->getLft());
    }

    /**
     * Insert node before other node.
     *
     * @param Node $node
     *
     * @return bool
     */
    protected function actionBefore(Node $node)
    {
        return $this->actionBeforeOrAfter($node);
    }

    /**
     * Insert node after other node.
     *
     * @param Node $node
     *
     * @return bool
     */
    protected function actionAfter(Node $node)
    {
        return $this->actionBeforeOrAfter($node, true);
    }

    /**
     * Refresh node's crucial attributes.
     */
    public function refreshNode()
    {
        if ( ! $this->exists || static::$actionsPerformed === 0) return;

        $attributes = $this->newServiceQuery()->getNodeData($this->getKey());

        $this->attributes = array_merge($this->attributes, $attributes);
        $this->original = array_merge($this->original, $attributes);
    }

    /**
     * Get the root node.
     *
     * @param array $columns
     *
     * @return Node
     */
    static public function root(array $columns = array('*'))
    {
        return static::whereIsRoot()->first($columns);
    }

    /**
     * Relation to the parent.
     *
     * @return BelongsTo
     */
    public function parent()
    {
        return $this->belongsTo(get_class($this), $this->getParentIdName());
    }

    /**
     * Relation to children.
     *
     * @return HasMany
     */
    public function children()
    {
        return $this->hasMany(get_class($this), $this->getParentIdName());
    }

    /**
     * Get query for descendants of the node.
     *
     * @return  QueryBuilder
     */
    public function descendants()
    {
        return $this->newQuery()->whereDescendantOf($this->getKey());
    }

    /**
     * Get query for siblings of the node.
     *
     * @param self::AFTER|self::BEFORE|null $dir
     *
     * @return QueryBuilder
     */
    public function siblings($dir = null)
    {
        switch ($dir)
        {
            case self::AFTER:
                $query = $this->nextNodes();

                break;

            case self::BEFORE:
                $query = $this->prevNodes();

                break;

            default:
                $query = $this->newQuery()
                    ->defaultOrder()
                    ->where($this->getKeyName(), '<>', $this->getKey());

                break;
        }

        $query->where($this->getParentIdName(), '=', $this->getParentId());

        return $query;
    }

    /**
     * Get query for siblings after the node.
     *
     * @return QueryBuilder
     */
    public function nextSiblings()
    {
        return $this->siblings(self::AFTER);
    }

    /**
     * Get query for siblings before the node.
     *
     * @return QueryBuilder
     */
    public function prevSiblings()
    {
        return $this->siblings(self::BEFORE);
    }

    /**
     * Get query for nodes after current node.
     *
     * @return QueryBuilder
     */
    public function nextNodes()
    {
        return $this->newQuery()->whereIsAfter($this->getKey())->defaultOrder();
    }

    /**
     * Get query for nodes before current node in reversed order.
     *
     * @return QueryBuilder
     */
    public function prevNodes()
    {
        return $this->newQuery()->whereIsBefore($this->getKey())->reversed();
    }

    /**
     * Get query for ancestors to the node not including the node itself.
     *
     * @return  QueryBuilder
     */
    public function ancestors()
    {
        return $this->newQuery()->whereAncestorOf($this->getKey())->defaultOrder();
    }

    /**
     * Make this node a root node.
     *
     * @return $this
     */
    public function makeRoot()
    {
        return $this->setAction('root');
    }

    /**
     * Save node as root.
     *
     * @return bool
     */
    public function saveAsRoot()
    {
        return $this->makeRoot()->save();
    }

    /**
     * Append and save a node.
     *
     * @param Node $node
     *
     * @return bool
     */
    public function appendNode(Node $node)
    {
        return $node->appendTo($this)->save();
    }

    /**
     * Prepend and save a node.
     *
     * @param Node $node
     *
     * @return bool
     */
    public function prependNode(Node $node)
    {
        return $node->prependTo($this)->save();
    }

    /**
     * Append a node to the new parent.
     *
     * @param Node $parent
     *
     * @return $this
     */
    public function appendTo(Node $parent)
    {
        return $this->setAction('appendTo', $parent);
    }

    /**
     * Prepend a node to the new parent.
     *
     * @param Node $parent
     *
     * @return $this
     */
    public function prependTo(Node $parent)
    {
        return $this->setAction('prependTo', $parent);
    }

    /**
     * Insert self after a node.
     *
     * @param Node $node
     *
     * @return $this
     */
    public function afterNode(Node $node)
    {
        return $this->setAction('after', $node);
    }

    /**
     * Insert self after a node and save.
     *
     * @param Node $node
     *
     * @return bool
     */
    public function insertAfter(Node $node)
    {
        return $this->afterNode($node)->save();
    }

    /**
     * Insert self before node.
     *
     * @param Node $node
     *
     * @return $this
     */
    public function beforeNode(Node $node)
    {
        return $this->setAction('before', $node);
    }

    /**
     * Insert self before a node and save.
     *
     * @param Node $node
     *
     * @return bool
     */
    public function insertBefore(Node $node)
    {
        if ($this->beforeNode($node)->save())
        {
            // We'll' update the target node since it will be moved
            $node->refreshNode();

            return true;
        }

        return false;
    }

    /**
     * Move node up given amount of positions.
     *
     * @param int $amount
     *
     * @return bool
     */
    public function up($amount = 1)
    {
        if ($sibling = $this->prevSiblings()->skip($amount - 1)->first())
        {
            return $this->insertBefore($sibling);
        }

        return false;
    }

    /**
     * Move node down given amount of positions.
     *
     * @param int $amount
     *
     * @return bool
     */
    public function down($amount = 1)
    {
        if ($sibling = $this->nextSiblings()->skip($amount - 1)->first())
        {
            return $this->insertAfter($sibling);
        }

        return false;
    }

    /**
     * Insert node at specific position.
     *
     * @param  int $position
     *
     * @return bool
     */
    protected function insertAt($position)
    {
        ++static::$actionsPerformed;

        $result = $this->exists ? $this->moveNode($position) : $this->insertNode($position);

        return $result;
    }

    /**
     * Move a node to the new position.
     *
     * @since 2.0
     *
     * @param int $position
     *
     * @return int
     */
    protected function moveNode($position)
    {
        $updated = $this->newServiceQuery()->moveNode($this->getKey(), $position) > 0;

        if ($updated) $this->refreshNode();

        return $updated;
    }

    /**
     * Insert new node at specified position.
     *
     * @since 2.0
     *
     * @param int $position
     *
     * @return bool
     */
    protected function insertNode($position)
    {
        $this->newServiceQuery()->makeGap($position, 2);

        $height = $this->getNodeHeight();

        $this->setLft($position);
        $this->setRgt($position + $height - 1);

        return true;
    }

    /**
     * Update the tree when the node is removed physically.
     */
    protected function deleteDescendants()
    {
        if (static::$deleting) return;

        $lft = $this->getLft();
        $rgt = $this->getRgt();

        // Make sure that inner nodes are just deleted and don't touch the tree
        // This makes sense in Laravel 4.2
        static::$deleting = true;

        $query = $this->newQuery()->whereNodeBetween([ $lft + 1, $rgt ]);

        if (static::$_softDelete and $this->forceDeleting)
        {
            $query->withTrashed()->forceDelete();
        }
        else
        {
            $query->delete();
        }

        static::$deleting = false;

        if ($this->hardDeleting())
        {
            $height = $rgt - $lft + 1;

            $this->newServiceQuery()->makeGap($rgt + 1, -$height);

            // In case if user wants to re-create the node
            $this->makeRoot();

            static::$actionsPerformed++;
        }
    }

    /**
     * Restore the descendants.
     *
     * @param $deletedAt
     */
    protected function restoreDescendants($deletedAt)
    {
        $this->newQuery()
            ->whereNodeBetween([ $this->getLft() + 1, $this->getRgt() ])
            ->where($this->getDeletedAtColumn(), '>=', $deletedAt)
            ->restore();
    }

    /**
     * {@inheritdoc}
     *
     * @since 2.0
     */
    public function newEloquentBuilder($query)
    {
        return new QueryBuilder($query);
    }

    /**
     * Get a new base query that includes deleted nodes.
     *
     * @since 1.1
     *
     * @return QueryBuilder
     */
    public function newServiceQuery()
    {
        return static::$_softDelete ? $this->withTrashed() : $this->newQuery();
    }

    /**
     * {@inheritdoc}
     */
    public function newCollection(array $models = array())
    {
        return new Collection($models);
    }

    /**
     * {@inheritdoc}
     */
    public function newFromBuilder($attributes = array(), $connection = null)
    {
        /** @var Node $instance */
        $instance = parent::newFromBuilder($attributes, $connection);

        $instance->clearAction();

        return $instance;
    }

    /**
     * {@inheritdoc}
     *
     * Use `children` key on `$attributes` to create child nodes.
     *
     * @param Node $parent
     */
    public static function create(array $attributes = array(), Node $parent = null)
    {
        $children = array_pull($attributes, 'children');

        $instance = new static($attributes);

        if ($parent) $instance->appendTo($parent);

        $instance->save();

        // Now create children
        $relation = new EloquentCollection;

        foreach ((array)$children as $child)
        {
            $relation->add($child = static::create($child, $instance));

            $child->setRelation('parent', $instance);
        }

        return $instance->setRelation('children', $relation);
    }

    /**
     * Get node height (rgt - lft + 1).
     *
     * @return int
     */
    public function getNodeHeight()
    {
        if ( ! $this->exists) return 2;

        return $this->getRgt() - $this->getLft() + 1;
    }

    /**
     * Get number of descendant nodes.
     *
     * @return int
     */
    public function getDescendantCount()
    {
        return round($this->getNodeHeight() / 2) - 1;
    }

    /**
     * Set the value of model's parent id key.
     *
     * Behind the scenes node is appended to found parent node.
     *
     * @param int $value
     *
     * @throws Exception If parent node doesn't exists
     */
    public function setParentIdAttribute($value)
    {
        if ($this->getParentId() != $value)
        {
            if ($value)
            {
                $this->appendTo($this->newQuery()->findOrFail($value));
            }
            else
            {
                $this->makeRoot();
            }
        }
    }

    /**
     * Get whether node is root.
     *
     * @return boolean
     */
    public function isRoot()
    {
        return $this->getParentId() === null;
    }

    /**
     * Get the lft key name.
     *
     * @return  string
     */
    public function getLftName()
    {
        return static::LFT;
    }

    /**
     * Get the rgt key name.
     *
     * @return  string
     */
    public function getRgtName()
    {
        return static::RGT;
    }

    /**
     * Get the parent id key name.
     *
     * @return  string
     */
    public function getParentIdName()
    {
        return static::PARENT_ID;
    }

    /**
     * Get the value of the model's lft key.
     *
     * @return  integer
     */
    public function getLft()
    {
        return isset($this->attributes[$this->getLftName()]) ? $this->attributes[$this->getLftName()] : null;
    }

    /**
     * Get the value of the model's rgt key.
     *
     * @return  integer
     */
    public function getRgt()
    {
        return isset($this->attributes[$this->getRgtName()]) ? $this->attributes[$this->getRgtName()] : null;
    }

    /**
     * Get the value of the model's parent id key.
     *
     * @return  integer
     */
    public function getParentId()
    {
        return $this->getAttribute($this->getParentIdName());
    }

    /**
     * Shorthand for next()
     *
     * @param  array  $columns
     *
     * @return Node
     */
    public function getNext(array $columns = array('*'))
    {
        return $this->nextNodes()->first($columns);
    }

    /**
     * Shorthand for prev()
     *
     * @param  array  $columns
     *
     * @return Node
     */
    public function getPrev(array $columns = array('*'))
    {
        return $this->prevNodes()->first($columns);
    }

    /**
     * Shorthand for ancestors()
     *
     * @param  array  $columns
     *
     * @return Collection
     */
    public function getAncestors(array $columns = array('*'))
    {
        return $this->newQuery()->defaultOrder()->ancestorsOf($this->getKey(), $columns);
    }

    /**
     * Shorthand for descendants()
     *
     * @param  array  $columns
     *
     * @return Collection|Node[]
     */
    public function getDescendants(array $columns = array('*'))
    {
        return $this->newQuery()->defaultOrder()->descendantsOf($this->getKey(), $columns);
    }

    /**
     * Shorthand for siblings()
     *
     * @param array $columns
     *
     * @return Collection|Node[]
     */
    public function getSiblings(array $columns = array('*'))
    {
        return $this->siblings()->defaultOrder()->get($columns);
    }

    /**
     * Shorthand for nextSiblings().
     *
     * @param  array  $columns
     *
     * @return Collection|Node[]
     */
    public function getNextSiblings(array $columns = array('*'))
    {
        return $this->nextSiblings()->get($columns);
    }

    /**
     * Shorthand for prevSiblings().
     *
     * @param  array  $columns
     *
     * @return Collection|Node[]
     */
    public function getPrevSiblings(array $columns = array('*'))
    {
        return $this->prevSiblings()->get($columns);
    }

    /**
     * Get next sibling.
     *
     * @param  array  $columns
     *
     * @return Node
     */
    public function getNextSibling(array $columns = array('*'))
    {
        return $this->nextSiblings()->first($columns);
    }

    /**
     * Get previous sibling.
     *
     * @param  array  $columns
     *
     * @return Node
     */
    public function getPrevSibling(array $columns = array('*'))
    {
        return $this->prevSiblings()->reversed()->first($columns);
    }

    /**
     * Get whether a node is a descendant of other node.
     *
     * @param Node $other
     *
     * @return bool
     */
    public function isDescendantOf(Node $other)
    {
        return $this->getLft() > $other->getLft() and $this->getLft() < $other->getRgt();
    }

    /**
     * Get whether the node is immediate children of other node.
     *
     * @param Node $other
     *
     * @return bool
     */
    public function isChildOf(Node $other)
    {
        return $this->getParentId() == $other->getKey();
    }

    /**
     * Get whether the node is a sibling of another node.
     *
     * @param Node $other
     *
     * @return bool
     */
    public function isSiblingOf(Node $other)
    {
        return $this->getParentId() == $other->getParentId();
    }

    /**
     * Get whether the node is an ancestor of other node, including immediate parent.
     *
     * @param Node $other
     *
     * @return bool
     */
    public function isAncestorOf(Node $other)
    {
        return $other->isDescendantOf($this);
    }

    /**
     * Get statistics of errors of the tree.
     *
     * @since 2.0
     *
     * @return array
     */
    public static function countErrors()
    {
        $model = new static;

        return $model->newServiceQuery()->countErrors();
    }

    /**
     * Get the number of total errors of the tree.
     *
     * @since 2.0
     *
     * @return int
     */
    public static function getTotalErrors()
    {
        return array_sum(static::countErrors());
    }

    /**
     * Get whether the tree is broken.
     *
     * @since 2.0
     *
     * @return bool
     */
    public static function isBroken()
    {
        return static::getTotalErrors() > 0;
    }

    /**
     * Get whether the node has moved since last save.
     *
     * @return bool
     */
    public function hasMoved()
    {
        return $this->moved;
    }

    /**
     * @return array
     */
    protected function getArrayableRelations()
    {
        $result = parent::getArrayableRelations();

        // To fix #17 when converting tree to json falling to infinite recursion.
        unset($result['parent']);

        return $result;
    }

    /**
     * Get whether user is intended to delete the model from database entirely.
     *
     * @return bool
     */
    protected function hardDeleting()
    {
        return ! static::$_softDelete or $this->forceDeleting;
    }

    /**
     * @return array
     */
    public function getBounds()
    {
        return [ $this->getLft(), $this->getRgt() ];
    }

    /**
     * @param $value
     */
    public function setLft($value)
    {
        $this->setAttribute($this->getLftName(), $value);
    }

    /**
     * @param $value
     */
    public function setRgt($value)
    {
        $this->setAttribute($this->getRgtName(), $value);
    }

    /**
     * Fixes the tree based on parentage info.
     *
     * Requires at least one root node. This will not update nodes with invalid parent.
     *
     * @return int The number of fixed nodes.
     */
    public static function fixTree()
    {
        $model = new static;

        $columns = [
            $model->getKeyName(),
            $model->getParentIdName(),
            $model->getLftName(),
            $model->getRgtName(),
        ];

        $nodes = $model->newQuery()->defaultOrder()->get($columns)->groupBy($model->getParentIdName());

        self::reorderNodes($nodes, $fixed);

        return $fixed;
    }

    /**
     * @param Collection $models
     * @param int $fixed
     * @param $parentId
     * @param int $cut
     *
     * @return int
     */
    protected static function reorderNodes(Collection $models, &$fixed, $parentId = null, $cut = 1)
    {
        /** @var Node $model */
        foreach ($models->get($parentId, []) as $model)
        {
            $model->setLft($cut);

            $cut = self::reorderNodes($models, $fixed, $model->getKey(), $cut + 1);

            $model->setRgt($cut);

            if ($model->isDirty())
            {
                $model->save();

                $fixed++;
            }

            ++$cut;
        }

        return $cut;
    }

//    public static function rebuildTree(array $nodes, $createNodes = true, $deleteNodes = false)
//    {
//        $model = new static;
//
//        $existing = $model->newQuery()->get()->keyBy($model->getKeyName());
//        $nodes = new Collection;
//    }

}
