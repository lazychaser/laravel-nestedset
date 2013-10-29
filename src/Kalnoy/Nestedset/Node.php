<?php namespace Kalnoy\Nestedset;

use \Illuminate\Database\Eloquent\Model as Eloquent;
use \Illuminate\Database\Query\Builder;
use \Illuminate\Database\Query\Expression;
use Exception;

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
     * Get the root node.
     *
     * @param   array   $columns
     *
     * @return  Node
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
        return $this->belongsTo(get_class($this), static::PARENT_ID);
    }

    /**
     * Relation to children.
     *
     * @return HasMany
     */
    public function children()
    {
        return $this->hasMany(get_class($this), static::PARENT_ID);
    }

    /**
     * Get query for descendants of the node.
     *
     * @return  \Kalnoy\Nestedset\QueryBuilder
     */
    public function descendants()
    {
        $query = $this->newQuery();

        return $query->whereBetween(static::LFT, array($this->getLft() + 1, $this->getRgt()));
    }

    /**
     * Get query for siblings of the node.
     * 
     * @param self::AFTER|self::BEFORE|null $dir
     *
     * @return \Kalnoy\Nestedset\QueryBuilder
     */
    public function siblings($dir = null)
    {
        switch ($dir)
        {
            case self::AFTER: 
                $query = $this->next();

                break;

            case self::BEFORE:
                $query = $this->prev();

                break;

            default:
                $query = $this->newQuery()
                    ->where($this->getKeyName(), '<>', $this->getKey());

                break;
        }

        $query->where(static::PARENT_ID, '=', $this->getParentId());
        
        return $query;
    }

    /**
     * Get query for siblings after the node.
     *
     * @return \Kalnoy\Nestedset\QueryBuilder
     */
    public function nextSiblings()
    {
        return $this->siblings(self::AFTER);
    }

    /**
     * Get query for siblings before the node.
     *
     * @return \Kalnoy\Nestedset\QueryBuilder
     */
    public function prevSiblings()
    {
        return $this->siblings(self::BEFORE);
    }

    /**
     * Get query for nodes after current node.
     *
     * @return \Kalnoy\Nestedset\QueryBuilder
     */
    public function next()
    {
        return $this->newQuery()
            ->where(static::LFT, '>', $this->attributes[static::LFT])
            ->defaultOrder();
    }

    /**
     * Get query for nodes before current node in reversed order.
     *
     * @return \Kalnoy\Nestedset\QueryBuilder
     */
    public function prev()
    {
        return $this->newQuery()
            ->where(static::LFT, '<', $this->attributes[static::LFT])
            ->reversed();
    }

    /**
     * Get query for ancestors to the node not including the node itself.
     *
     * @return  \Kalnoy\Nestedset\QueryBuilder
     */
    public function ancestors()
    {
        $query = $this->newQuery();
        $grammar = $query->getQuery()->getGrammar();
        $table = $this->getTable();
        $lft   = $grammar->wrap(static::LFT);
        $rgt   = $grammar->wrap(static::RGT);

        $lftValue = $this->getLft();

        return $query
            ->whereRaw("? between $lft and $rgt", array($lftValue))
            ->where(static::LFT, "<>", $lftValue);
    }

    /**
     * Insert node at the end of the list.
     *
     * @param  Node   $node
     *
     * @return Node self
     */
    public function append(Node $node)
    {
        $node->appendTo($this);

        return $this;
    }

    /**
     * Insert node at the top of the list.
     *
     * @param  Node   $node
     *
     * @return Node self
     */ 
    public function prepend(Node $node)
    {
        $node->prependTo($this);

        return $this;
    }

    /**
     * Insert self as last child of specified parent.
     *
     * @param  Node   $parent
     *
     * @return Node self
     */
    public function appendTo(Node $parent)
    {
        return $this
            ->checkTarget($parent)
            ->insertAt($parent->attributes[static::RGT], $parent);
    }

    /**
     * Insert self as first child of specified parent.
     *
     * @param  Node   $parent
     *
     * @return Node self
     */
    public function prependTo(Node $parent)
    {        
        return $this
            ->checkTarget($parent)
            ->insertAt($parent->attributes[static::LFT] + 1, $parent);
    }

    /**
     * Insert self after node.
     *
     * @param  Node   $node
     *
     * @return Node self
     * @throws Exception If node doesn't exists
     */
    public function after(Node $node)
    {
        return $this->beforeOrAfter($node, self::AFTER); 
    }

    /**
     * Insert self before node.
     *
     * @param  Node   $node
     *
     * @return Node
     * @throws Exception If node doesn't exists
     */
    public function before(Node $node)
    {
        return $this->beforeOrAfter($node, self::BEFORE);
    }

    /**
     * Insert self before or after node.
     *
     * @param  Node   $node
     * @param  self::BEFORE|self::AFTER $dir
     *
     * @return Node
     * @throws Exception If $node is not a valid target.
     * @throws Exception If $node is the root.
     */
    protected function beforeOrAfter(Node $node, $dir)
    {
        $this->checkTarget($node);

        if ($node->isRoot()) 
        {
            throw new Exception("Cannot insert node $dir root node.");
        }

        $position = $dir === self::BEFORE 
            ? $node->attributes['_lft'] 
            : $node->attributes['_rgt'] + 1;

        return $this->insertAt($position, $node->getParentId());
    }

    /**
     * Check if target node is saved.
     *
     * @param  Node   $node
     *
     * @return Node
     * @throws Exception If target fails conditions.
     */
    protected function checkTarget(Node $node)
    {
        if (!$node->exists || $node->isDirty(static::LFT)) 
        {
            throw new Exception("Target node is updated but not saved.");
        }

        return $this;
    }

    /**
     * Insert node at specific position and related parent.
     *
     * @param  int $position
     * @param  mixed $parent
     *
     * @return Node
     * @throws Exception If node is inserted in one of it's descendants
     */
    protected function insertAt($position, $parent)
    {
        if ($this->exists && $this->getLft() < $position && $position < $this->getRgt()) 
        {
            throw new Exception("Trying to insert node into one of it's descendants.");
        }

        $height = $this->getNodeHeight();

        // We simply update values here. When user calls save() actual
        // transformations are performed.
        $this->attributes[static::LFT] = $position;
        $this->attributes[static::RGT] = $position + $height - 1;

        if ($parent instanceof Node)
        {
            $this->attributes[static::PARENT_ID] = $parent->getKey();
            $this->relations['parent']           = $parent;
        }
        else 
        {
            $this->attributes[static::PARENT_ID] = $parent;
        }

        return $this;
    }

    /**
     * Catch "creating" and "updating" event to check if tree needs to be updated.
     * Catch "deleting" to see if user tries to delete root node.
     *
     * @param  string  $event
     * @param  boolean $halt
     *
     * @return boolean
     */
    public function fireModelEvent($event, $halt = true)
    {
        // We need to capture 'saving' event to be able to control dirty values.
        // 'updating' event is called after dirty values are retrieved.
        if ($event === 'saving') 
        {
            if ($this->exists) 
            {
                if ($this->isDirty(static::LFT) && !$this->updateTree())
                {
                    return false;
                }
            }
            else
            {
                if (!isset($this->attributes[static::LFT]))
                {
                    throw new Exception("Cannot save node until it is inserted.");
                }

                if (!$this->updateTree()) return false;
            }
        }

        if ($event === 'deleting' && $this->isRoot())
        {
            throw new Exception("Cannot delete root node.");
        }

        if ($event === 'deleted' && !$this->softDelete) $this->deleteNode();

        return parent::fireModelEvent($event, $halt);
    }

    /**
     * Perform needed NestedSet processing to insert or re-insert node.
     *
     * @return boolean whether update succeeded.
     */
    protected function updateTree()
    {
        $cut = $this->attributes[static::LFT];

        if ($this->exists) 
        {
            $lft = $this->original[static::LFT];
            $rgt = $this->original[static::RGT];

            return $this->rearrange($lft, $rgt, $cut) > 0;
        }

        return $this->makeGap($cut, 2) > 0;
    }

    /**
     * Rearrange the tree to put the node into the new position.
     *
     * @param  integer $lft
     * @param  integer $rgt
     * @param  integer $pos
     *
     * @return integer the number of updated nodes.
     */
    public function rearrange($lft, $rgt, $pos)
    {
        $from = min($lft, $pos);
        $to   = max($rgt, $pos - 1);

        $query = $this->newQueryWithDeleted()->getQuery()
            ->whereBetween(static::LFT, array($from, $to))
            ->orWhereBetween(static::RGT, array($from, $to));

        // The height of node that is being moved
        $height = $rgt - $lft + 1;

        // The distance that our node will travel to reach it's destination
        $distance = $to - $from + 1 - $height;

        if ($pos > $lft) $height *= -1; else $distance *= -1;

        $params  = compact('lft', 'rgt', 'from', 'to', 'height', 'distance');
        $grammar = $query->getGrammar();
        $updated = $query->update($this->getColumnsPatch($params, $grammar));

        // Sync the attributes
        $this->original[static::LFT] = $this->attributes[static::LFT] = $lft += $distance;
        $this->original[static::RGT] = $this->attributes[static::RGT] = $rgt += $distance;

        $this->updateParent($height, $from, $to);

        return $updated;
    }

    /**
     * Update the tree when the node is removed physically.
     *
     * @return void
     */
    protected function deleteNode()
    {
        // DBMS with support of foreign keys will remove descendant nodes automatically
        $this->descendants()->delete();

        $lft = $this->getLft();
        $rgt = $this->getRgt();

        // Unset this attributes to indicate that node has'n been inserted.
        unset($this->attributes[static::LFT], $this->attributes[static::RGT]);

        return $this->makeGap($rgt + 1, $lft - $rgt - 1);
    }

    /**
     * Make or remove gap in the tree. Negative height will remove gap.
     *
     * @param  integer $cut
     * @param  integer $height
     *
     * @return integer the number of updated nodes.
     */
    protected function makeGap($cut, $height)
    {
        $params = compact('cut', 'height');
        $query = $this->newQueryWithDeleted()->getQuery();
        $updated = $query
            ->where(static::LFT, '>=', $cut)
            ->orWhere(static::RGT, '>=', $cut)
            ->update($this->getColumnsPatch($params, $query->getGrammar()));

        $this->updateParent($height);

        return $updated;
    }

    /**
     * Update parent to keep it synced.
     * 
     * When node is inserted, either lft or rgt is updated. If node doesn't exists
     * it is always rgt and it is simply increased or decreased by height depending
     * on whether node was deleted or it is being inserted.
     * 
     * When node is exists and it is moved, parent is updated within specific
     * boundaries and column is selected based on where node moved - up (rgt) or
     * down (lft).
     *
     * @param  integer $height
     * @param  integer $from
     * @param  integer $to
     *
     * @return void
     */
    protected function updateParent($height, $from = null, $to = null)
    {
        if (!isset($this->relations['parent'])) return;

        $parent = $this->relations['parent'];

        if ($from === null)
        {
            $parent->attributes[static::RGT] += $height;
        }
        else
        {
            $col = $height < 0 ? static::LFT : static::RGT;
            $value = $parent->attributes[$col];

            if ($to === null) $to = $value;

            if ($from <= $value and $value <= $to)
            {
                $parent->attributes[$col] += $height;
            }
        }

        $parent->updateParent($height, $from, $to);
    }

    /**
     * Get patch for columns.
     *
     * @param  array  $params
     * @param  \Illuminate\Database\Query\Grammars\Grammar $grammar
     *
     * @return array
     */
    protected function getColumnsPatch(array $params, $grammar)
    {
        $columns = array();
        foreach (array(static::LFT, static::RGT) as $col) 
        {
            $columns[$col] = $this->getColumnPatch($grammar->wrap($col), $params);
        }

        return $columns;
    }

    /**
     * Get patch for single column.
     *
     * @param  string $col
     * @param  array  $params
     *
     * @return string
     */
    protected function getColumnPatch($col, array $params)
    {
        extract($params);

        if ($height > 0) $height = '+'.$height;

        if (isset($cut)) 
        {
            return new Expression("case when $col >= $cut then $col $height else $col end");
        }

        if ($distance > 0) $distance = '+'.$distance;

        return new Expression("case ".
            "when $col between $lft and $rgt then $col $distance ".
            "when $col between $from and $to then $col $height ".
            "else $col end"
        );
    }

    /**
     * Get a new base query builder instance.
     *
     * @return  \Kalnoy\Nestedset\QueryBuilder
     */
    protected function newBaseQueryBuilder()
    {
        $conn = $this->getConnection();

        $grammar = $conn->getQueryGrammar();

        return new QueryBuilder($conn, $grammar, $conn->getPostProcessor(), $this);
    }

    /**
     * Create a new NestedSet Collection instance.
     *
     * @param   array   $models
     *
     * @return  \Kalnoy\Nestedset\Collection
     */
    public function newCollection(array $models = array())
    {
        return new Collection($models);
    }

    /**
     * Get node size (rgt-lft).
     *
     * @return int
     */
    public function getNodeHeight()
    {
        if (!$this->exists) return 2;

        return $this->attributes[static::RGT] - $this->attributes[static::LFT] + 1;
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
     * @throws Exception If parent node doesn't exists
     */
    public function setParentIdAttribute($value)
    {
        if (!isset($this->attributes[static::PARENT_ID]) || $this->attributes[static::PARENT_ID] != $value) 
        {
            $this->appendTo(static::findOrFail($value));
        }
    }

    /**
     * Get whether node is root.
     *
     * @return boolean
     */
    public function isRoot()
    {
        return $this->attributes[static::LFT] == 1;
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
        return $this->attributes[static::LFT];
    }

    /**
     * Get the value of the model's rgt key.
     *
     * @return  integer
     */
    public function getRgt()
    {
        return $this->attributes[static::RGT];
    }

    /**
     * Get the value of the model's parent id key.
     *
     * @return  integer
     */
    public function getParentId()
    {
        return $this->attributes[static::PARENT_ID];
    }

    /**
     * Shorthand for next()
     *
     * @param  array  $columns
     *
     * @return \Kalnoy\Nestedset\Node
     */
    public function getNext(array $columns = array('*'))
    {
        return $this->next()->first($columns);
    }

    /**
     * Shorthand for prev()
     *
     * @param  array  $columns
     *
     * @return \Kalnoy\Nestedset\Node
     */
    public function getPrev(array $columns = array('*'))
    {
        return $this->prev()->first($columns);
    }

    /**
     * Shorthand for ancestors()
     *
     * @param  array  $columns
     *
     * @return \Kalnoy\Nestedset\Collection
     */
    public function getAncestors(array $columns = array('*'))
    {
        return $this->ancestors()->get($columns);
    }

    /**
     * Shorthand for descendants()
     *
     * @param  array  $columns
     *
     * @return \Kalnoy\Nestedset\Collection
     */
    public function getDescendants(array $columns = array('*'))
    {
        return $this->descendants()->get($columns);
    }

    /**
     * Shorthand for siblings()
     *
     * @param   array   $column
     *
     * @return  \Kalnoy\Nestedset\Collection
     */
    public function getSiblings(array $column = array('*')) 
    {
        return $this->siblings()->get($columns);
    }

    /**
     * Shorthand for nextSiblings().
     *
     * @param  array  $columns
     *
     * @return \Kalnoy\Nestedset\Collection
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
     * @return \Kalnoy\Nestedset\Collection
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
     * @return \Kalnoy\Nestedset\Node
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
     * @return \Kalnoy\Nestedset\Node
     */
    public function getPrevSibling(array $columns = array('*'))
    {
        return $this->prevSiblings()->reversed()->first($columns);
    }
}