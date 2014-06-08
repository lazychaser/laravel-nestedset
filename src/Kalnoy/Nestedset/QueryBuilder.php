<?php

namespace Kalnoy\Nestedset;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Processors\Processor;
use Illuminate\Database\Query\Expression;

class QueryBuilder extends Builder {

    /**
     * Get node's `lft` and `rgt` values.
     * 
     * @param mixed $id
     * 
     * @return array
     */
    public function getNodeData($id)
    {
        $this->query->where($this->model->getKeyName(), '=', $id);

        return $this->query->first([ $this->model->getLftName(), $this->model->getRgtName() ]);
    }

    /**
     * Get plain node data.
     * 
     * @param mixed $id
     *
     * @return array
     */
    public function getPlainNodeData($id)
    {
        return array_values($this->getNodeData($id));
    }

    /**
     * Scope limits query to select just root node.
     *
     * @return $this
     */
    public function whereIsRoot()
    {
        $this->query->whereNull($this->model->getParentIdName());

        return $this;
    }

    /**
     * Limit results to ancestors of specified node.
     *
     * @param mixed $id
     *
     * @return $this
     */
    public function whereAncestorOf($id)
    {
        $table = $this->wrapTable();
        $keyName = $this->model->getKeyName();

        list($lft, $rgt) = $this->wrapColumns();

        $key = $this->query->getGrammar()->wrap($keyName);

        $this->query->whereRaw(
            "(select _.{$lft} from {$table} _ where _.{$key} = ? limit 1)".
            " between {$lft} and {$rgt}"
        );

        // Exclude the node
        $this->where($keyName, '<>', $id);

        $this->query->addBinding($id, 'where');

        return $this;
    }

    /**
     * Get ancestors of specified node.
     * 
     * @param mixed $id
     * @param array $columns
     * 
     * @return \Kalnoy\Nestedset\Collection
     */
    public function ancestorsOf($id, array $columns = array('*'))
    {
        return $this->whereAncestorsOf($id)->get($columns);
    }

    /**
     * Add node selection statement between specified range.
     * 
     * @param array $values
     * @param string $boolean
     * @param bool $not
     * 
     * @return $this
     */
    public function whereNodeBetween($values, $boolean = 'and', $not = false)
    {
        $this->query->whereBetween($this->model->getLftName(), $values, $boolean, $not);

        return $this;
    }

    /**
     * Add node selection statement between specified range joined with `or` operator.
     * 
     * @param array $values
     * 
     * @return $this
     */
    public function orWhereNodeBetween($values)
    {
        return $this->whereNodeBetween($values, 'or');
    }

    /**
     * Add constraint statement to descendants of specified node.
     *
     * @param mixed $id
     * @param string $boolean
     * @param bool $not
     * 
     * @return $this
     */
    public function whereDescendantOf($id, $boolean = 'and', $not = false)
    {
        $data = $this->model->newQuery()->getPlainNodeData($id);

        // Don't include the node
        ++$data[0];

        return $this->whereNodeBetween($data, $boolean, $not);
    }

    /**
     * Get descendants of specified node.
     * 
     * @param mixed $id
     * @param array $columns
     * 
     * @return \Kalnoy\Nestedset\Collection
     */
    public function descendantsOf($id, array $columns = array('*'))
    {
        return $this->whereDescendantOf($id)->get($columns);
    }

    /**
     * Constraint nodes to those that are after specified node.
     * 
     * @param mixed $id
     * @param string $boolean
     * 
     * @return $this
     */
    public function whereIsAfter($id, $boolean = 'and')
    {
        $table = $this->wrapTable();
        list($lft, $rgt) = $this->wrapColumns();
        $key = $this->wrapKey();

        $this->query->whereRaw("{$lft} > (select _n.{$lft} from {$table} _n where _n.{$key} = ?)", [ $id ], $boolean);

        return $this;
    }

    /**
     * Constraint nodes to those that are before specified node.
     * 
     * @param mixed $id
     * @param string $boolean
     * 
     * @return $this
     */
    public function whereIsBefore($id, $boolean = 'and')
    {
        $table = $this->wrapTable();
        list($lft, $rgt) = $this->wrapColumns();
        $key = $this->wrapKey();

        $this->query->whereRaw("{$lft} < (select _b.{$lft} from {$table} _b where _b.{$key} = ?)", [ $id ], $boolean);

        return $this;
    }

    /**
     * Include depth level into the result.
     *
     * @param string $key
     *
     * @return $this
     */
    public function withDepth($key = 'depth')
    {
        $table = $this->wrapTable();
        
        list($lft, $rgt) = $this->wrapColumns();

        $key = $this->query->getGrammar()->wrap($key);

        $column = $this->query->raw(
            "((select count(*) from {$table} _d ".
            "where {$table}.{$lft} between _d.{$lft} and _d.{$rgt})-1) as {$key}"
        );

        if ($this->query->columns === null) $this->query->columns = array('*');

        $this->query->addSelect($column);

        return $this;
    }

    /**
     * Get wrapped `lft` and `rgt` column names.
     * 
     * @return array
     */
    protected function wrapColumns()
    {
        $grammar = $this->query->getGrammar();

        return
        [
            $grammar->wrap($this->model->getLftName()),
            $grammar->wrap($this->model->getRgtName()),
        ];
    }

    /**
     * Get a wrapped table name.
     * 
     * @return string
     */
    protected function wrapTable()
    {
        return $this->query->getGrammar()->wrap($this->getQuery()->from);
    }

    /**
     * Wrap model's key name.
     * 
     * @return string
     */
    protected function wrapKey()
    {
        return $this->query->getGrammar()->wrap($this->model->getKeyName());
    }

    /**
     * Exclude root node from the result.
     *
     * @return $this
     */
    public function withoutRoot()
    {
        $this->query->whereNotNull($this->model->getParentIdName());

        return $this;
    }

    /**
     * Equivalent of `withouRoot`.
     * 
     * @return $this
     */
    public function hasParent()
    {
        $this->query->whereNotNull($this->model->getParentIdName());

        return $this;
    }

    /**
     * Get only nodes that have children.
     * 
     * @return $this
     */
    public function hasChildren()
    {
        list($lft, $rgt) = $this->wrapColumns();

        $this->query->whereRaw("{$rgt} > {$lft} + 1");

        return $this;
    }

    /**
     * Order by node position.
     * 
     * @param string $dir
     *
     * @return $this
     */
    public function defaultOrder($dir = 'asc')
    {
        $this->query->orders = null;

        $this->query->orderBy($this->model->getLftName(), $dir);

        return $this;
    }

    /**
     * Order by reversed node position.
     *
     * @return $this
     */
    public function reversed()
    {
        return $this->defaultOrder('desc');
    }
}