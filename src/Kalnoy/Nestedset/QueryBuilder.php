<?php namespace Kalnoy\Nestedset;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Processors\Processor;
use Illuminate\Database\Query\Expression;

class QueryBuilder extends Builder {
    /**
     * The node that has created this builder.
     *
     * @var \Kalnoy\Nestedset\Node
     */
    protected $node;

    /**
     * Create a new QueryBuilder instance.
     *
     * @param \Illuminate\Database\ConnectionInterface          $connection
     * @param \Illuminate\Database\Query\Grammars\Grammar       $grammar
     * @param \Illuminate\Database\Query\Processors\Processor   $processor
     * @param \Kalnoy\Nestedset\Node                            $node
     */
    public function __construct(ConnectionInterface $connection,
                                Grammar $grammar,
                                Processor $processor,
                                Node $node)
    {
        parent::__construct($connection, $grammar, $processor);

        $this->node = $node;
    }

    /**
     * Scope limits query to select just root node.
     *
     * @return  \Kalnoy\Nestedset\QueryBuilder
     */
    public function whereIsRoot()
    {
        return $this->where($this->node->getLftName(), '=', 1);
    }

    /**
     * Query path to specific node including that node itself.
     *
     * @param   integer  $id
     *
     * @return  \Kalnoy\Nestedset\QueryBuilder
     */
    public function pathTo($id)
    {
        $grammar = $this->getGrammar();
        $table  = $grammar->wrapTable($this->from);
        $lft    = $grammar->wrap($this->node->getLftName());
        $rgt    = $grammar->wrap($this->node->getRgtName());
        $key    = $grammar->wrap($this->node->getKeyName());

        $this->whereRaw(
            "(select _.$lft from $table _ where _.$key = ? limit 1)".
            " between $lft and $rgt"
        );

        $this->bindings[] = $id;

        return $this;
    }

    /**
     * Include depth level into the result.
     *
     * @param   string  $key The attribute name that will hold the depth level.
     *
     * @return  \Kalnoy\Nestedset\QueryBuilder
     */
    public function withDepth($key = 'depth')
    {
        $grammar = $this->getGrammar();
        $table   = $grammar->wrapTable($this->from);
        $lft     = $grammar->wrap($this->node->getLftName());
        $rgt     = $grammar->wrap($this->node->getRgtName());
        $key     = $grammar->wrap($key);

        $column = new Expression(
            "((select count(*) from $table _ where $table.$lft between _.$lft and _.$rgt)-1) as $key"
        );

        if ($this->columns === null) $this->columns = array('*');

        return $this->addSelect($column);
    }

    /**
     * Exclude root node from the result.
     *
     * @return  \Kalnoy\Nestedset\QueryBuilder
     */
    public function withoutRoot()
    {
        return $this->where($this->node->getLftName(), '<>', 1);
    }

    /**
     * Reverse the order of nodes.
     *
     * @return \Kalnoy\Nestedset\QueryBuilder
     */
    public function reversed()
    {
        $this->orders = null;

        return $this->orderBy($this->node->getLftName(), 'desc');
    }

    /**
     * Get the SQL representation of the query.
     *
     * @return string
     */
    public function toSql()
    {
        if ($this->orders === null && $this->limit === null && $this->offset === null)
        {
            $this->defaultOrder();
        }

        return parent::toSql();
    }

    /**
     * Apply default order which is order by node position.
     *
     * @return \Kalnoy\Nestedset\QueryBuilder
     */
    public function defaultOrder()
    {
        $this->orders = null;

        return $this->orderBy($this->node->getLftName());
    }
}