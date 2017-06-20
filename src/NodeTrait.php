<?php

namespace Kalnoy\Nestedset;

use Exception;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        static::saving(function ($model) {
            return $model->callPendingAction();
        });

        static::deleting(function ($model) {
            // We will need fresh data to delete node safely
            $model->refreshNode();
        });

        static::deleted(function ($model) {
            $model->deleteDescendants();
        });

        if (static::usesSoftDelete()) {
            static::restoring(function ($model) {
                static::$deletedAt = $model->{$model->getDeletedAtColumn()};
            });

            static::restored(function ($model) {
                $model->restoreDescendants(static::$deletedAt);
            });
        }
    }

    /**
     * Set an action.
     *
     * @param string $action
     *
     * @return $this
     */
    protected function setNodeAction($action)
    {
        $this->pending = func_get_args();

        return $this;
    }

    /**
     * Call pending action.
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

            return $softDelete = method_exists($instance, 'bootSoftDeletes');
        }

        return $softDelete;
    }

    /**
     * @return bool
     */
    protected function actionRaw()
    {
        return true;
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

        return $this->insertAt($this->getLowerBound() + 1);
    }

    /**
     * Get the lower bound.
     *
     * @return int
     */
    protected function getLowerBound()
    {
        return (int)$this->newNestedSetQuery()->max($this->getRgtName());
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

        if ( ! $this->insertAt($cut)) {
            return false;
        }

        $parent->refreshNode();

        return true;
    }

    /**
     * Apply parent model.
     *
     * @param Model|null $value
     *
     * @return $this
     */
    protected function setParent($value)
    {
        $this->setParentId($value ? $value->getKey() : null)
            ->setRelation('parent', $value);

        return $this;
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

        $attributes = $this->newNestedSetQuery()->getNodeData($this->getKey());

        $this->attributes = array_merge($this->attributes, $attributes);
//        $this->original = array_merge($this->original, $attributes);
    }

    /**
     * Relation to the parent.
     *
     * @return BelongsTo
     */
    public function parent()
    {
        return $this->belongsTo(get_class($this), $this->getParentIdName())
            ->setModel($this);
    }

    /**
     * Relation to children.
     *
     * @return HasMany
     */
    public function children()
    {
        return $this->hasMany(get_class($this), $this->getParentIdName())
            ->setModel($this);
    }

    /**
     * Get query for descendants of the node.
     *
     * @return DescendantsRelation
     */
    public function descendants()
    {
        return new DescendantsRelation($this->newScopedQuery(), $this);
    }

    /**
     * Get query for siblings of the node.
     *
     * @return QueryBuilder
     */
    public function siblings()
    {
        return $this->newScopedQuery()
            ->where($this->getKeyName(), '<>', $this->getKey())
            ->where($this->getParentIdName(), '=', $this->getParentId());
    }

    /**
     * Get the node siblings and the node itself.
     *
     * @return \Kalnoy\Nestedset\QueryBuilder
     */
    public function siblingsAndSelf()
    {
        return $this->newScopedQuery()
            ->where($this->getParentIdName(), '=', $this->getParentId());
    }

    /**
     * Get query for the node siblings and the node itself.
     *
     * @param  array $columns
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getSiblingsAndSelf(array $columns = [ '*' ])
    {
        return $this->siblingsAndSelf()->get($columns);
    }

    /**
     * Get query for siblings after the node.
     *
     * @return QueryBuilder
     */
    public function nextSiblings()
    {
        return $this->nextNodes()
            ->where($this->getParentIdName(), '=', $this->getParentId());
    }

    /**
     * Get query for siblings before the node.
     *
     * @return QueryBuilder
     */
    public function prevSiblings()
    {
        return $this->prevNodes()
            ->where($this->getParentIdName(), '=', $this->getParentId());
    }

    /**
     * Get query for nodes after current node.
     *
     * @return QueryBuilder
     */
    public function nextNodes()
    {
        return $this->newScopedQuery()
            ->where($this->getLftName(), '>', $this->getLft());
    }

    /**
     * Get query for nodes before current node in reversed order.
     *
     * @return QueryBuilder
     */
    public function prevNodes()
    {
        return $this->newScopedQuery()
            ->where($this->getLftName(), '<', $this->getLft());
    }

    /**
     * Get query ancestors of the node.
     *
     * @return  AncestorsRelation
     */
    public function ancestors()
    {
        return new AncestorsRelation($this->newScopedQuery(), $this);
    }

    /**
     * Make this node a root node.
     *
     * @return $this
     */
    public function makeRoot()
    {
        $this->setParent(null)->dirtyBounds();

        return $this->setNodeAction('root');
    }

    /**
     * Save node as root.
     *
     * @return bool
     */
    public function saveAsRoot()
    {
        if ($this->exists && $this->isRoot()) {
            return $this->save();
        }

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
        $this->assertNodeExists($parent)
            ->assertNotDescendant($parent)
            ->assertSameScope($parent);

        $this->setParent($parent)->dirtyBounds();

        return $this->setNodeAction('appendOrPrepend', $parent, $prepend);
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
        $this->assertNodeExists($node)
            ->assertNotDescendant($node)
            ->assertSameScope($node);

        if ( ! $this->isSiblingOf($node)) {
            $this->setParent($node->getRelationValue('parent'));
        }

        $this->dirtyBounds();

        return $this->setNodeAction('beforeOrAfter', $node, $after);
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

        // We'll update the target node since it will be moved
        $node->refreshNode();

        return true;
    }

    /**
     * @param $lft
     * @param $rgt
     * @param $parentId
     *
     * @return $this
     */
    public function rawNode($lft, $rgt, $parentId)
    {
        $this->setLft($lft)->setRgt($rgt)->setParentId($parentId);

        return $this->setNodeAction('raw');
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
        $sibling = $this->prevSiblings()
            ->defaultOrder('desc')
            ->skip($amount - 1)
            ->first();

        if ( ! $sibling) return false;

        return $this->insertBeforeNode($sibling);
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
        $sibling = $this->nextSiblings()
            ->defaultOrder()
            ->skip($amount - 1)
            ->first();

        if ( ! $sibling) return false;

        return $this->insertAfterNode($sibling);
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
        $updated = $this->newNestedSetQuery()
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
        $this->newNestedSetQuery()->makeGap($position, 2);

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

        $this->descendants()->{$method}();

        if ($this->hardDeleting()) {
            $height = $rgt - $lft + 1;

            $this->newNestedSetQuery()->makeGap($rgt + 1, -$height);

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
        $this->descendants()
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
    public function newNestedSetQuery($table = null)
    {
        $builder = $this->usesSoftDelete()
            ? $this->withTrashed()
            : $this->newQuery();

        return $this->applyNestedSetScope($builder, $table);
    }

    /**
     * @param string $table
     *
     * @return QueryBuilder
     */
    public function newScopedQuery($table = null)
    {
        return $this->applyNestedSetScope($this->newQuery(), $table);
    }

    /**
     * @param mixed $query
     * @param string $table
     *
     * @return mixed
     */
    public function applyNestedSetScope($query, $table = null)
    {
        if ( ! $scoped = $this->getScopeAttributes()) {
            return $query;
        }

        if ( ! $table) {
            $table = $this->getTable();
        }

        foreach ($scoped as $attribute) {
            $query->where($table.'.'.$attribute, '=',
                          $this->getAttributeValue($attribute));
        }

        return $query;
    }

    /**
     * @return array
     */
    protected function getScopeAttributes()
    {
        return null;
    }

    /**
     * @param array $attributes
     *
     * @return self
     */
    public static function scoped(array $attributes)
    {
        $instance = new static;

        $instance->setRawAttributes($attributes);

        return $instance->newScopedQuery();
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

        if ($parent) {
            $instance->appendToNode($parent);
        }

        $instance->save();

        // Now create children
        $relation = new EloquentCollection;

        foreach ((array)$children as $child) {
            $relation->add($child = static::create($child, $instance));

            $child->setRelation('parent', $instance);
        }

        $instance->refreshNode();

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
        return ceil($this->getNodeHeight() / 2) - 1;
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
            $this->appendToNode($this->newScopedQuery()->findOrFail($value));
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
        return is_null($this->getParentId());
    }

    /**
     * @return bool
     */
    public function isLeaf()
    {
        return $this->getLft() + 1 == $this->getRgt();
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
        return $this->getAttributeValue($this->getLftName());
    }

    /**
     * Get the value of the model's rgt key.
     *
     * @return  integer
     */
    public function getRgt()
    {
        return $this->getAttributeValue($this->getRgtName());
    }

    /**
     * Get the value of the model's parent id key.
     *
     * @return  integer
     */
    public function getParentId()
    {
        return $this->getAttributeValue($this->getParentIdName());
    }

    /**
     * Returns node that is next to current node without constraining to siblings.
     *
     * This can be either a next sibling or a next sibling of the parent node.
     *
     * @param array $columns
     *
     * @return self
     */
    public function getNextNode(array $columns = [ '*' ])
    {
        return $this->nextNodes()->defaultOrder()->first($columns);
    }

    /**
     * Returns node that is before current node without constraining to siblings.
     *
     * This can be either a prev sibling or parent node.
     *
     * @param array $columns
     *
     * @return self
     */
    public function getPrevNode(array $columns = [ '*' ])
    {
        return $this->prevNodes()->defaultOrder('desc')->first($columns);
    }

    /**
     * @param array $columns
     *
     * @return Collection
     */
    public function getAncestors(array $columns = [ '*' ])
    {
        return $this->ancestors()->get($columns);
    }

    /**
     * @param array $columns
     *
     * @return Collection|self[]
     */
    public function getDescendants(array $columns = [ '*' ])
    {
        return $this->descendants()->get($columns);
    }

    /**
     * @param array $columns
     *
     * @return Collection|self[]
     */
    public function getSiblings(array $columns = [ '*' ])
    {
        return $this->siblings()->get($columns);
    }

    /**
     * @param array $columns
     *
     * @return Collection|self[]
     */
    public function getNextSiblings(array $columns = [ '*' ])
    {
        return $this->nextSiblings()->get($columns);
    }

    /**
     * @param array $columns
     *
     * @return Collection|self[]
     */
    public function getPrevSiblings(array $columns = [ '*' ])
    {
        return $this->prevSiblings()->get($columns);
    }

    /**
     * @param array $columns
     *
     * @return self
     */
    public function getNextSibling(array $columns = [ '*' ])
    {
        return $this->nextSiblings()->defaultOrder()->first($columns);
    }

    /**
     * @param array $columns
     *
     * @return self
     */
    public function getPrevSibling(array $columns = [ '*' ])
    {
        return $this->prevSiblings()->defaultOrder('desc')->first($columns);
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
        return $this->getLft() > $other->getLft() &&
            $this->getLft() < $other->getRgt();
    }

    /**
     * Get whether a node is itself or a descendant of other node.
     *
     * @param self $other
     *
     * @return bool
     */
    public function isSelfOrDescendantOf(self $other)
    {
        return $this->getLft() >= $other->getLft() &&
            $this->getLft() < $other->getRgt();
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
     * Get whether the node is itself or an ancestor of other node, including immediate parent.
     *
     * @param self $other
     *
     * @return bool
     */
    public function isSelfOrAncestorOf(self $other)
    {
        return $other->isSelfOrDescendantOf($this);
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
     * @param $value
     *
     * @return $this
     */
    public function setLft($value)
    {
        $this->attributes[$this->getLftName()] = $value;

        return $this;
    }

    /**
     * @param $value
     *
     * @return $this
     */
    public function setRgt($value)
    {
        $this->attributes[$this->getRgtName()] = $value;

        return $this;
    }

    /**
     * @param $value
     *
     * @return $this
     */
    public function setParentId($value)
    {
        $this->attributes[$this->getParentIdName()] = $value;

        return $this;
    }

    /**
     * @return $this
     */
    protected function dirtyBounds()
    {
        $this->original[$this->getLftName()] = null;
        $this->original[$this->getRgtName()] = null;

        return $this;
    }

    /**
     * @param self $node
     *
     * @return $this
     */
    protected function assertNotDescendant(self $node)
    {
        if ($node == $this || $node->isDescendantOf($this)) {
            throw new LogicException('Node must not be a descendant.');
        }

        return $this;
    }

    /**
     * @param self $node
     *
     * @return $this
     */
    protected function assertNodeExists(self $node)
    {
        if ( ! $node->getLft() || ! $node->getRgt()) {
            throw new LogicException('Node must exists.');
        }

        return $this;
    }

    /**
     * @param self $node
     */
    protected function assertSameScope(self $node)
    {
        if ( ! $scoped = $this->getScopeAttributes()) {
            return;
        }

        foreach ($scoped as $attr) {
            if ($this->getAttribute($attr) != $node->getAttribute($attr)) {
                throw new LogicException('Nodes must be in the same scope');
            }
        }
    }

    /**
     * @param array|null $except
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function replicate(array $except = null)
    {
        $defaults = [
            $this->getParentIdName(),
            $this->getLftName(),
            $this->getRgtName(),
        ];

        $except = $except ? array_unique(array_merge($except, $defaults)) : $defaults;

        return parent::replicate($except);
    }
}
