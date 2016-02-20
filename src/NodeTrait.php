<?php

namespace Kalnoy\Nestedset;

use Exception;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use LogicException;

trait NodeTrait
{
    /**
     * Pending operation.
     *
     * @var array
     */
    protected $pending;

    /**
     * Whether the node has moved since last save.
     *
     * @var bool
     */
    protected $moved = false;

    /**
     * @var \Carbon\Carbon
     */
    public static $deletedAt;

    /**
     * Keep track of the number of performed operations.
     *
     * @var int
     */
    public static $actionsPerformed = 0;

    /**
     * Sign on model events.
     */
    public static function bootNodeTrait()
    {
        static::saving(function (self $model) {
            return $model->callPendingAction();
        });

        static::deleting(function (self $model) {
            // We will need fresh data to delete node safely
            $model->refreshNode();
        });

        static::deleted(function (self $model) {
            $model->deleteDescendants();
        });

        if (static::usesSoftDelete()) {
            static::restoring(function (self $model) {
                static::$deletedAt = $model->{$model->getDeletedAtColumn()};
            });

            static::restored(function (self $model) {
                $model->restoreDescendants(static::$deletedAt);
            });
        }
    }

    /**
     * {@inheritdoc}
     *
     * Saves a node in transaction.
     */
    public function save(array $options = array())
    {
        return $this->getConnection()->transaction(function () use ($options) {
            return parent::save($options);
        });
    }

    /**
     * {@inheritdoc}
     *
     * Delete a node in transaction.
     */
    public function delete()
    {
        return $this->getConnection()->transaction(function () {
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
     * Call pending action.
     *
     * @return null|false
     */
    protected function callPendingAction()
    {
        $this->moved = false;

        if ( ! $this->pending && ! $this->exists) {
            $this->makeRoot();
        }

        if ( ! $this->pending) return;

        $method = 'action'.ucfirst(array_shift($this->pending));
        $parameters = $this->pending;

        $this->pending = null;

        $this->moved = call_user_func_array([ $this, $method ], $parameters);
    }

    /**
     * @return bool
     */
    public static function usesSoftDelete()
    {
        static $softDelete;

        if (is_null($softDelete)) {
            $instance = new static;

            return $softDelete = method_exists($instance, 'withTrashed');
        }

        return $softDelete;
    }

    /**
     * Make a root node.
     */
    protected function actionRoot()
    {
        // Simplest case that do not affect other nodes.
        if ( ! $this->exists) {
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
     * Append or prepend a node to the parent.
     *
     * @param self $parent
     * @param bool $prepend
     *
     * @return bool
     */
    protected function actionAppendOrPrepend(self $parent, $prepend = false)
    {
        $parent->refreshNode();

        $cut = $prepend ? $parent->getLft() + 1 : $parent->getRgt();

        if ( ! $this->insertAt($cut)) return false;

        $parent->refreshNode();

        return true;
    }

    /**
     * Apply parent model.
     *
     * @param Model|null $value
     */
    protected function setParent($value)
    {
        $this->attributes[$this->getParentIdName()] = $value ? $value->getKey() : null;

        $this->setRelation('parent', $value);
    }

    /**
     * Insert node before or after another node.
     *
     * @param self $node
     * @param bool $after
     *
     * @return bool
     */
    protected function actionBeforeOrAfter(self $node, $after = false)
    {
        $node->refreshNode();

        return $this->insertAt($after ? $node->getRgt() + 1 : $node->getLft());
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
     * @return self
     */
    static public function root(array $columns = ['*'])
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
     * @param mixed $dir
     *
     * @return QueryBuilder
     */
    public function siblings($dir = null)
    {
        switch ($dir)
        {
            case NestedSet::AFTER:
                $query = $this->nextNodes();

                break;

            case NestedSet::BEFORE:
                $query = $this->prevNodes();

                break;

            default:
                $query = $this->newQuery()
                              ->defaultOrder()
                              ->where($this->getKeyName(), '<>', $this->getKey());

                break;
        }

        $parentId = $this->getParentId();

        if (is_null($parentId)) {
            $query->whereNull($this->getParentId());
        } else {
            $query->where($this->getParentIdName(), '=', $parentId);
        }

        return $query;
    }

    /**
     * Get query for siblings after the node.
     *
     * @return QueryBuilder
     */
    public function nextSiblings()
    {
        return $this->siblings(NestedSet::AFTER);
    }

    /**
     * Get query for siblings before the node.
     *
     * @return QueryBuilder
     */
    public function prevSiblings()
    {
        return $this->siblings(NestedSet::BEFORE);
    }

    /**
     * Get query for nodes after current node.
     *
     * @return QueryBuilder
     */
    public function nextNodes()
    {
        return $this->newQuery()
                    ->whereIsAfter($this->getKey())
                    ->defaultOrder();
    }

    /**
     * Get query for nodes before current node in reversed order.
     *
     * @return QueryBuilder
     */
    public function prevNodes()
    {
        return $this->newQuery()
                    ->whereIsBefore($this->getKey())
                    ->reversed();
    }

    /**
     * Get query for ancestors to the node not including the node itself.
     *
     * @return  QueryBuilder
     */
    public function ancestors()
    {
        return $this->newQuery()
                    ->whereAncestorOf($this->getKey())
                    ->defaultOrder();
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
     * @param self $node
     *
     * @return bool
     */
    public function appendNode(self $node)
    {
        return $node->appendToNode($this)->save();
    }

    /**
     * Prepend and save a node.
     *
     * @param self $node
     *
     * @return bool
     */
    public function prependNode(self $node)
    {
        return $node->prependToNode($this)->save();
    }

    /**
     * Append a node to the new parent.
     *
     * @param self $parent
     *
     * @return $this
     */
    public function appendToNode(self $parent)
    {
        return $this->appendOrPrependTo($parent);
    }

    /**
     * Prepend a node to the new parent.
     *
     * @param self $parent
     *
     * @return $this
     */
    public function prependToNode(self $parent)
    {
        return $this->appendOrPrependTo($parent, true);
    }

    /**
     * @param self $parent
     * @param bool $prepend
     *
     * @return self
     */
    public function appendOrPrependTo(self $parent, $prepend = false)
    {
        if ( ! $parent->exists) {
            throw new LogicException('Cannot use non-existing node as a parent.');
        }

        $this->setParent($parent);

        return $this->setAction('appendOrPrepend', $parent, $prepend);
    }

    /**
     * Insert self after a node.
     *
     * @param self $node
     *
     * @return $this
     */
    public function afterNode(self $node)
    {
        return $this->beforeOrAfterNode($node, true);
    }

    /**
     * Insert self before node.
     *
     * @param self $node
     *
     * @return $this
     */
    public function beforeNode(self $node)
    {
        return $this->beforeOrAfterNode($node);
    }

    /**
     * @param self $node
     * @param bool $after
     *
     * @return self
     */
    public function beforeOrAfterNode(self $node, $after = false)
    {
        if ( ! $node->exists) {
            throw new LogicException('Cannot insert before/after a node that does not exists.');
        }

        if ( ! $this->isSiblingOf($node)) {
            $this->setParent($node->getRelationValue('parent'));
        }

        return $this->setAction('beforeOrAfter', $node, $after);
    }

    /**
     * Insert self after a node and save.
     *
     * @param self $node
     *
     * @return bool
     */
    public function insertAfterNode(self $node)
    {
        return $this->afterNode($node)->save();
    }

    /**
     * Insert self before a node and save.
     *
     * @param self $node
     *
     * @return bool
     */
    public function insertBeforeNode(self $node)
    {
        if ( ! $this->beforeNode($node)->save()) return false;

        // We'll' update the target node since it will be moved
        $node->refreshNode();

        return true;
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
        if ($sibling = $this->prevSiblings()->skip($amount - 1)->first()) {
            return $this->insertBeforeNode($sibling);
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
        if ($sibling = $this->nextSiblings()->skip($amount - 1)->first()) {
            return $this->insertAfterNode($sibling);
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

        $result = $this->exists
            ? $this->moveNode($position)
            : $this->insertNode($position);

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
        $updated = $this->newServiceQuery()
                        ->moveNode($this->getKey(), $position) > 0;

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
        $lft = $this->getLft();
        $rgt = $this->getRgt();

        $method = $this->usesSoftDelete() && $this->forceDeleting
            ? 'forceDelete'
            : 'delete';

        $this->newQuery()->whereNodeBetween([ $lft + 1, $rgt ])->{$method}();

        if ($this->hardDeleting()) {
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
             ->applyScopes()
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
        return $this->usesSoftDelete() ? $this->withTrashed() : $this->newQuery();
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
     *
     * Use `children` key on `$attributes` to create child nodes.
     *
     * @param self $parent
     */
    public static function create(array $attributes = [], self $parent = null)
    {
        $children = array_pull($attributes, 'children');

        $instance = new static($attributes);

        if ($parent) $instance->appendToNode($parent);

        $instance->save();

        // Now create children
        $relation = new EloquentCollection;

        foreach ((array)$children as $child) {
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
        if ($this->getParentId() == $value) return;

        if ($value) {
            $this->appendToNode($this->newQuery()->findOrFail($value));
        } else {
            $this->makeRoot();
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
        return NestedSet::LFT;
    }

    /**
     * Get the rgt key name.
     *
     * @return  string
     */
    public function getRgtName()
    {
        return NestedSet::RGT;
    }

    /**
     * Get the parent id key name.
     *
     * @return  string
     */
    public function getParentIdName()
    {
        return NestedSet::PARENT_ID;
    }

    /**
     * Get the value of the model's lft key.
     *
     * @return  integer
     */
    public function getLft()
    {
        return isset($this->attributes[$this->getLftName()])
            ? $this->attributes[$this->getLftName()]
            : null;
    }

    /**
     * Get the value of the model's rgt key.
     *
     * @return  integer
     */
    public function getRgt()
    {
        return isset($this->attributes[$this->getRgtName()])
            ? $this->attributes[$this->getRgtName()]
            : null;
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
     * @param  array $columns
     *
     * @return self
     */
    public function getNext(array $columns = array( '*' ))
    {
        return $this->nextNodes()->first($columns);
    }

    /**
     * Shorthand for prev()
     *
     * @param  array $columns
     *
     * @return self
     */
    public function getPrev(array $columns = array( '*' ))
    {
        return $this->prevNodes()->first($columns);
    }

    /**
     * Shorthand for ancestors()
     *
     * @param  array $columns
     *
     * @return Collection
     */
    public function getAncestors(array $columns = array( '*' ))
    {
        return $this->newQuery()
                    ->defaultOrder()
                    ->ancestorsOf($this->getKey(), $columns);
    }

    /**
     * Shorthand for descendants()
     *
     * @param  array $columns
     *
     * @return Collection|self[]
     */
    public function getDescendants(array $columns = array( '*' ))
    {
        return $this->newQuery()
                    ->defaultOrder()
                    ->descendantsOf($this->getKey(), $columns);
    }

    /**
     * Shorthand for siblings()
     *
     * @param array $columns
     *
     * @return Collection|self[]
     */
    public function getSiblings(array $columns = array( '*' ))
    {
        return $this->siblings()->defaultOrder()->get($columns);
    }

    /**
     * Shorthand for nextSiblings().
     *
     * @param  array $columns
     *
     * @return Collection|self[]
     */
    public function getNextSiblings(array $columns = array( '*' ))
    {
        return $this->nextSiblings()->get($columns);
    }

    /**
     * Shorthand for prevSiblings().
     *
     * @param  array $columns
     *
     * @return Collection|self[]
     */
    public function getPrevSiblings(array $columns = array( '*' ))
    {
        return $this->prevSiblings()->get($columns);
    }

    /**
     * Get next sibling.
     *
     * @param  array $columns
     *
     * @return self
     */
    public function getNextSibling(array $columns = array( '*' ))
    {
        return $this->nextSiblings()->first($columns);
    }

    /**
     * Get previous sibling.
     *
     * @param  array $columns
     *
     * @return self
     */
    public function getPrevSibling(array $columns = array( '*' ))
    {
        return $this->prevSiblings()->reversed()->first($columns);
    }

    /**
     * Get whether a node is a descendant of other node.
     *
     * @param self $other
     *
     * @return bool
     */
    public function isDescendantOf(self $other)
    {
        return $this->getLft() > $other->getLft() && $this->getLft() < $other->getRgt();
    }

    /**
     * Get whether the node is immediate children of other node.
     *
     * @param self $other
     *
     * @return bool
     */
    public function isChildOf(self $other)
    {
        return $this->getParentId() == $other->getKey();
    }

    /**
     * Get whether the node is a sibling of another node.
     *
     * @param self $other
     *
     * @return bool
     */
    public function isSiblingOf(self $other)
    {
        return $this->getParentId() == $other->getParentId();
    }

    /**
     * Get whether the node is an ancestor of other node, including immediate parent.
     *
     * @param self $other
     *
     * @return bool
     */
    public function isAncestorOf(self $other)
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
        return ! $this->usesSoftDelete() || $this->forceDeleting;
    }

    /**
     * @return array
     */
    public function getBounds()
    {
        return [ $this->getLft(), $this->getRgt() ];
    }

    /**
     * Replaces instanceof calls for this trait.
     *
     * @param mixed
     *
     * @return bool
     */
    public static function hasTrait($node)
    {
        return is_object($node) && in_array(self::class, (array)$node) === true;
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

//    public static function rebuildTree(array $nodes, $createNodes = true, $deleteNodes = false)
//    {
//        $model = new static;
//
//        $existing = $model->newQuery()->get()->keyBy($model->getKeyName());
//        $nodes = new Collection;
//    }

}
