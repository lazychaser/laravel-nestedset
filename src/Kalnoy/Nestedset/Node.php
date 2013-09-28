<?php namespace Kalnoy\Nestedset;

use \Illuminate\Database\Eloquent\Model as Eloquent;
use \Illuminate\Database\Query\Builder;
use Exception;

class Node extends Eloquent 
{
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
     * Scope limits query to select just root node.
     *
     * @param   \Illuminate\Database\Eloquent\Builder  $query
     *
     * @return  \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWhereIsRoot($query)
    {
        return $query->where(static::LFT, '=', 1);
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
     * @return  \Illuminate\Database\Eloquent\Builder
     */
    public function descendants()
    {
        $query = $this->newQuery();

        return $query->whereBetween('lft', array($this->getLft() + 1, $this->getRgt()));
    }

    /**
     * Query path to specific node including that node itself.
     *
     * @param   \Illuminate\Database\Eloquent\Builder  $query
     * @param   integer  $id
     *
     * @return  \Illuminate\Database\Eloquent\Builder
     */
    public function scopePathTo($query, $id)
    {
        $grammar = $query->getQuery()->getGrammar();
        $table  = $grammar->wrapTable($this->getTable());
        $lft    = $grammar->wrap(static::LFT);
        $rgt    = $grammar->wrap(static::RGT);
        $id     = (int)$id;

        return $query->whereRaw(
            "(select _.$lft from $table _ where _.id = $id limit 1)".
            " between $lft and $rgt"
        );
    }

    /**
     * Include depth level into result.
     *
     * @param   \Illuminate\Database\Eloquent\Builder  $query
     * @param   string  $key The attribute name that will hold the depth level.
     *
     * @return  \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithDepth($query, $key = 'depth')
    {
        $grammar = $query->getQuery()->getGrammar();
        $table   = $grammar->wrapTable($this->getTable());
        $lft     = $grammar->wrap(static::LFT);
        $rgt     = $grammar->wrap(static::RGT);
        $key     = $grammar->wrap($key);

        $column = $query->getQuery()->raw(
            "((select count(*) from $table _ where $table.$lft between _.$lft and _.$rgt)-1) as $key"
        );

        return $query->addSelect($column);
    }

    /**
     * Exclude root node from result.
     *
     * @param   \Illuminate\Database\Eloquent\Builder  $query
     *
     * @return  \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithoutRoot($query)
    {
        return $query->where(static::LFT, '<>', 1);
    }

    /**
     * Get query for path to the node not including the node itself.
     *
     * @return  \Illuminate\Database\Eloquent\Builder
     * @throws Exception If node does not exists
     */
    public function path()
    {
        if (!$this->exists) {
            throw new Exception("Cannot query for path to non-existing node.");
        }

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
        return $this->insertAt($parent->attributes[static::RGT], $parent);
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
        return $this->insertAt($parent->attributes[static::LFT] + 1, $parent);
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
     */
    protected function beforeOrAfter(Node $node, $dir)
    {
        if (!$node->exists) {
            throw new Exception("Cannot insert node $dir node that does not exists.");  
        }

        if ($node->isRoot()) {
            throw new Exception("Cannot insert node $dir root node.");
        }

        $position = $dir === self::BEFORE ? $node->attributes['_lft'] : $node->attributes['_rgt'] + 1;

        return $this->insertAt($position, $node->parent);
    }

    /**
     * Insert node at specific position and related parent.
     *
     * @param  int $position
     * @param  Node $parent
     *
     * @return Node
     */
    protected function insertAt($position, Node $parent)
    {
        if ($this->exists && $this->getLft() < $position && $position < $this->getRgt()) {
            throw new Exception("Cannot insert node into itself.");
        }

        $height = $this->getNodeHeight();

        // We simply update values here. When user calls save() actual
        // transformations are performed.
        $this->attributes[static::LFT]       = $position;
        $this->attributes[static::RGT]       = $position + $height - 1;
        $this->attributes[static::PARENT_ID] = $parent->getKey();
        $this->relations['parent']           = $parent;

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
        if ($event === 'creating' or $event === 'updating' and $this->isDirty(static::LFT)) {
            if (!$this->updateTree()) {
                return false;
            }
        }

        if ($event === 'deleting' and $this->isRoot()) {
            return false;
        }

        if ($event === 'deleted' and !$this->softDelete) {
            $this->processDeletedNode();
        }

        return parent::fireModelEvent($event, $halt);
    }

    /**
     * Perform needed NestedSet processing to insert or re-insert node.
     *
     * @return boolean whether update succeeded.
     */
    protected function updateTree()
    {
        $lft    = $this->attributes[static::LFT];
        $rgt    = $this->attributes[static::RGT];
        $height = $rgt - $lft + 1;

        if ($this->exists) {
            $oldLft = $this->original[static::LFT];
            $oldRgt = $this->original[static::RGT];

            // Secure node from being shifted.
            $this->hideNodes();
            
            if ($lft > $oldLft) {
                $shiftAmount = $lft - $oldRgt - 1;

                // Node is going down.
                // Other nodes are going up on its place.
                static::shiftNodes(-$height, $oldRgt + 1, $lft - 1, $this);
            } else {
                $shiftAmount = $lft - $oldLft;

                // Node is going up.
                // Other nodes are going down on its place.
                static::shiftNodes($height, $lft, $oldLft - 1, $this);
            }

            // Reveal and put nodes into new position.
            $this->revealNodes($shiftAmount);
        } else {
            static::shiftNodes($height, $lft, null, $this);
        }

        return true;
    }

    /**
     * Update NestedSet when node is removed physically.
     *
     * @return void
     */
    protected function processDeletedNode()
    {
        // We cannot use getNodeHeight because it always return 2 for non-existing
        // nodes.
        $height = $this->attributes[static::RGT] - $this->attributes[static::LFT] + 1;

        $this->shiftNodes(-$height, $this->attributes[static::RGT] + 1, null, $this);
    }

    /**
     * Hide nodes from NestedSet manipulations.
     *
     * @return void
     */
    protected function hideNodes()
    {
        static::hideOrRevealNodes(
            $this->original[static::LFT], 
            $this->original[static::RGT], 
            0, 
            $this
        );
    }

    /**
     * Reveal nodes in new position after NestedSet manipulations.
     *
     * @param  integer $shiftAmount
     *
     * @return void
     */
    protected function revealNodes($shiftAmount)
    {
        static::hideOrRevealNodes(
            $this->original[static::LFT], 
            $this->original[static::RGT], 
            $shiftAmount, 
            $this
        );
        
        // Fill original attributes because database contains updated LFT and RGT
        // values after nodes are revealed.
        $this->original[static::LFT] = $this->attributes[static::LFT];        
        $this->original[static::RGT] = $this->attributes[static::RGT];
    }

    /**
     * Shift nodes within given range.      
     *
     * @param  integer $amount
     * @param  integer $from
     * @param  integer $to
     * @param  Node    $instance
     *
     * @return void
     */
    static protected function shiftNodes($amount, $from, $to, $instance = null)
    {
        if ($amount == 0 || $from === null && $to === null) {
            return;
        }

        if ($instance === null) {
            $instance = new static;
        }

        $query  = $instance->newQueryWithDeleted()->getQuery();
        $method = $amount > 0 ? 'increment' : 'decrement';
        $amount = abs($amount);
        
        static::shiftNodesColumn($query, static::LFT, $method, $amount, $from, $to);
        static::shiftNodesColumn($query, static::RGT, $method, $amount, $from, $to);

        // Update parent of the instance to support inserting multiple nodes into
        // single parent.
        if (isset($instance->relations['parent'])) {
            $parent = $instance->relations['parent'];

            $parent->shiftColumn(static::LFT, $amount, $from, $to);
            $parent->shiftColumn(static::RGT, $amount, $from, $to);
        }
    }

    /**
     * Shift node specific column.
     *
     * @param  Builder $query
     * @param  string  $column
     * @param  string  $method
     * @param  integer  $amount
     * @param  integer  $from
     * @param  integer  $to
     *
     * @return integer
     */
    static protected function shiftNodesColumn(Builder $query, $column, $method, $amount, $from, $to)
    {
        $query->wheres = array();
        $query->setBindings(array());

        if ($from === null) {
            $query->where($column, '<=', $to);
        } elseif ($to === null) {
            $query->where($column, '>=', $from);
        } else {
            $query->whereBetween($column, array($from, $to));
        }

        return $query->$method($column, $amount);
    }

    /**
     * Apply nestedset logic to loaded model to sync it with database.      
     *
     * @param   integer  $amount
     * @param   integer  $from
     * @param   integer  $to
     *
     * @return  void
     */
    protected function shift($amount, $from, $to)
    {
        if ($from === null && $to === null || $amount == 0) {
            return;
        }

        $this->shiftColumn(static::LFT, $amount, $from, $to);
        $this->shiftColumn(static::RGT, $amount, $from, $to);
    }

    /**
     * Shift specific column.
     *
     * @param   static::LFT|static::RGT  $column
     * @param   integer  $amount
     * @param   integer  $from
     * @param   integer  $to
     *
     * @return  void
     */
    protected function shiftColumn($column, $amount, $from, $to)
    {
        $value = $this->attributes[$column];

        if ($from === null && $value <= $to || 
            $to === null && $from <= $value ||
            $from !== null && $to !== null && $from <= $value && $value <= $to) 
        {
            $this->original[$column] = $this->attributes[$column] += $amount;
        }
    }

    /**
     * Sync node with database.
     *
     * @param   integer  $shiftAmount
     * @param   integer  $from
     * @param   integer  $to
     *
     * @return  void
     */
    protected function reveal($shiftAmount, $from, $to)
    {
        $lft = $this->getLft();

        if ($from <= $lft && $lft <= $to) {
            $this->attributes[static::LFT] += $shiftAmount;
            $this->attributes[static::RGT] += $shiftAmount;
        }
    }

    /**
     * Hide nodes from NestedSet manipulations if shiftAmount = 0
     * or reveal them in new position made by shifting nodes by shiftAmount.
     *
     * @param  integer $from
     * @param  integer $to
     * @param  integer $shiftAmount
     * @param  Node    $instance
     *
     * @return integer the number of updated nodes
     */
    static protected function hideOrRevealNodes($from, $to, $shiftAmount = 0, $instance = null)
    {
        if ($instance === null) {
            $instance = new static;
        }

        $query   = $instance->newQueryWithDeleted()->getQuery();
        $grammar = $query->getGrammar();

        // Here we "hide" or "reveal" nodes that are going to be inserted into new position.
        // We negate lft value to distinguish nodes from other nodes that we are going to update.
        // "hiding" nodes is like ripping them out of database to do some manipulations
        // and "revealing" is putting nodes back in database but in new position based on shiftAmount
        $updateValues = array();

        if ($shiftAmount == 0) {
            $updateValues[static::LFT] = $query->raw('-'.$grammar->wrap(static::LFT));
        } else {
            $shiftAmountStr = $shiftAmount > 0 ? '+'.$shiftAmount : $shiftAmount;

            $updateValues[static::LFT] = $query->raw('-'.$grammar->wrap(static::LFT).$shiftAmountStr);
            $updateValues[static::RGT] = $query->raw($grammar->wrap(static::RGT).$shiftAmountStr);
        }

        $updated = $query
            ->whereBetween(static::LFT, $shiftAmount == 0 ? array($from, $to) : array(-$to, -$from))
            ->update($updateValues);

        return $updated;
    }

    /**
     * Override default query builder to enabled ordering by lft by default.
     *
     * @return  \Illuminate\Database\Query\Builder
     */
    protected function newBaseQueryBuilder()
    {
        $query = parent::newBaseQueryBuilder();

        return $query->orderBy(static::LFT);
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
        if (!$this->exists) {
            return 2;
        }

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
     */
    public function setParentId($value)
    {
        if ($this->isRoot()) {
            throw new Exception("Cannot change parent of root node.");
        }

        if ($this->attributes[static::PARENT_ID] != $value) {
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
}