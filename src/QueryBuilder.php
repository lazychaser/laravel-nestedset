<?php

namespace Kalnoy\Nestedset;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder as Query;
use LogicException;
use Illuminate\Database\Query\Expression;

class QueryBuilder extends Builder {

    /**
     * @var Node
     */
    protected $model;

    /**
     * Get node's `lft` and `rgt` values.
     *
     * @since 2.0
     *
     * @param mixed $id
     * @param bool $required
     *
     * @return array
     */
    public function getNodeData($id, $required = false)
    {
        $this->query->where($this->model->getKeyName(), '=', $id);

        $data = $this->query->first([ $this->model->getLftName(), $this->model->getRgtName() ]);

        if ( ! $data and $required) throw new ModelNotFoundException;

        return (array)$data;
    }

    /**
     * Get plain node data.
     *
     * @since 2.0
     *
     * @param mixed $id
     * @param bool $required
     *
     * @return array
     */
    public function getPlainNodeData($id, $required = false)
    {
        return array_values($this->getNodeData($id, $required));
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
     * @since 2.0
     *
     * @param mixed $id
     *
     * @return $this
     */
    public function whereAncestorOf($id)
    {
        $keyName = $this->model->getKeyName();

        if ($id instanceof Node)
        {
            $value = '?';

            $this->query->addBinding($id->getLft());

            $id = $id->getKey();
        }
        else
        {
            $valueQuery = $this->model->newQuery()->getQuery()
                                      ->select("_.".$this->model->getLftName())
                                      ->from($this->model->getTable().' as _')
                                      ->where($keyName, '=', $id)
                                      ->limit(1);

            $this->query->mergeBindings($valueQuery);

            $value = '('.$valueQuery->toSql().')';
        }

        list($lft, $rgt) = $this->wrappedColumns();

        $this->query->whereRaw("{$value} between {$lft} and {$rgt}");

        // Exclude the node
        $this->where($keyName, '<>', $id);

        return $this;
    }

    /**
     * Get ancestors of specified node.
     *
     * @since 2.0
     *
     * @param mixed $id
     * @param array $columns
     *
     * @return \Kalnoy\Nestedset\Collection
     */
    public function ancestorsOf($id, array $columns = array('*'))
    {
        return $this->whereAncestorOf($id)->get($columns);
    }

    /**
     * Add node selection statement between specified range.
     *
     * @since 2.0
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
     * @since 2.0
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
     * @since 2.0
     *
     * @param mixed $id
     * @param string $boolean
     * @param bool $not
     *
     * @return $this
     */
    public function whereDescendantOf($id, $boolean = 'and', $not = false)
    {
        if ($id instanceof Node)
        {
            $data = $id->getBounds();
        }
        else
        {
            $data = $this->model->newServiceQuery()->getPlainNodeData($id, true);
        }

        // Don't include the node
        ++$data[0];

        return $this->whereNodeBetween($data, $boolean, $not);
    }

    /**
     * @param mixed $id
     *
     * @return QueryBuilder
     */
    public function whereNotDescendantOf($id)
    {
        return $this->whereDescendantOf($id, 'and', true);
    }

    /**
     * @param mixed $id
     *
     * @return QueryBuilder
     */
    public function orWhereDescendantOf($id)
    {
        return $this->whereDescendantOf($id, 'or');
    }

    /**
     * @param mixed $id
     *
     * @return QueryBuilder
     */
    public function orWhereNotDescendantOf($id)
    {
        return $this->whereDescendantOf($id, 'or', true);
    }

    /**
     * Get descendants of specified node.
     *
     * @since 2.0
     *
     * @param mixed $id
     * @param array $columns
     *
     * @return \Kalnoy\Nestedset\Collection
     */
    public function descendantsOf($id, array $columns = array('*'))
    {
        try
        {
            return $this->whereDescendantOf($id)->get($columns);
        }

        catch (ModelNotFoundException $e)
        {
            return $this->model->newCollection();
        }
    }

    /**
     * @param $id
     * @param $operator
     * @param $boolean
     *
     * @return $this
     */
    protected function whereIsBeforeOrAfter($id, $operator, $boolean)
    {
        if ($id instanceof Node)
        {
            $value = '?';

            $this->query->addBinding($id->getLft());
        }
        else
        {
            $valueQuery = $this->model->newQuery()->getQuery()
                                      ->select('_n.'.$this->model->getLftName())
                                      ->from($this->model->getTable().' as _n')
                                      ->where('_n.'.$this->model->getKeyName(), '=', $id);

            $this->query->mergeBindings($valueQuery);

            $value = '('.$valueQuery->toSql().')';
        }

        list($lft,) = $this->wrappedColumns();

        $this->query->whereRaw("{$lft} {$operator} {$value}", [ ], $boolean);

        return $this;
    }

    /**
     * Constraint nodes to those that are after specified node.
     *
     * @since 2.0
     *
     * @param Node|mixed $id
     * @param string $boolean
     *
     * @return $this
     */
    public function whereIsAfter($id, $boolean = 'and')
    {
        return $this->whereIsBeforeOrAfter($id, '>', $boolean);
    }

    /**
     * Constraint nodes to those that are before specified node.
     *
     * @since 2.0
     *
     * @param mixed $id
     * @param string $boolean
     *
     * @return $this
     */
    public function whereIsBefore($id, $boolean = 'and')
    {
        return $this->whereIsBeforeOrAfter($id, '<', $boolean);
    }

    /**
     * Include depth level into the result.
     *
     * @param string $as
     *
     * @return $this
     */
    public function withDepth($as = 'depth')
    {
        if ($this->query->columns === null) $this->query->columns = [ '*' ];

        $this->query->selectSub(function (\Illuminate\Database\Query\Builder $q)
        {
            $table = $this->wrappedTable();

            list($lft, $rgt) = $this->wrappedColumns();

            $q
                ->selectRaw('count(1) - 1')
                ->from($this->model->getTable().' as _d')
                ->whereRaw("{$table}.{$lft} between _d.{$lft} and _d.{$rgt}");

        }, $as);

        return $this;
    }

    /**
     * Get wrapped `lft` and `rgt` column names.
     *
     * @since 2.0
     *
     * @return array
     */
    protected function wrappedColumns()
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
     * @since 2.0
     *
     * @return string
     */
    protected function wrappedTable()
    {
        return $this->query->getGrammar()->wrapTable($this->getQuery()->from);
    }

    /**
     * Wrap model's key name.
     *
     * @since 2.0
     *
     * @return string
     */
    protected function wrappedKey()
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
     * Equivalent of `withoutRoot`.
     *
     * @since 2.0
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
     * @since 2.0
     *
     * @return $this
     */
    public function hasChildren()
    {
        list($lft, $rgt) = $this->wrappedColumns();

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

    /**
     * Move a node to the new position.
     *
     * @param int $key
     * @param int $position
     *
     * @return int
     *
     * @throws \LogicException
     */
    public function moveNode($key, $position)
    {
        list($lft, $rgt) = $this->model->newServiceQuery()->getPlainNodeData($key, true);

        if ($lft < $position && $position <= $rgt)
        {
            throw new LogicException('Cannot move node into itself.');
        }

        // Get boundaries of nodes that should be moved to new position
        $from = min($lft, $position);
        $to   = max($rgt, $position - 1);

        // The height of node that is being moved
        $height = $rgt - $lft + 1;

        // The distance that our node will travel to reach it's destination
        $distance = $to - $from + 1 - $height;

        // If no distance to travel, just return
        if ($distance === 0) return 0;

        if ($position > $lft) $height *= -1; else $distance *= -1;

        $params = compact('lft', 'rgt', 'from', 'to', 'height', 'distance');

        $boundary = [ $from, $to ];

        $query = $this->query->where(function (Query $inner) use ($boundary)
        {
            $inner->whereBetween($this->model->getLftName(), $boundary);
            $inner->orWhereBetween($this->model->getRgtName(), $boundary);
        });

        return $query->update($this->patch($params));
    }

    /**
     * Make or remove gap in the tree. Negative height will remove gap.
     *
     * @since 2.0
     *
     * @param int $cut
     * @param int $height
     *
     * @return int
     */
    public function makeGap($cut, $height)
    {
        $params = compact('cut', 'height');

        $this->query->whereNested(function (Query $inner) use ($cut)
        {
            $inner->where($this->model->getLftName(), '>=', $cut);
            $inner->orWhere($this->model->getRgtName(), '>=', $cut);
        });

        return $this->query->update($this->patch($params));
    }

    /**
     * Get patch for columns.
     *
     * @since 2.0
     *
     * @param array $params
     *
     * @return array
     */
    protected function patch(array $params)
    {
        $grammar = $this->query->getGrammar();

        $columns = array();

        foreach ([ $this->model->getLftName(), $this->model->getRgtName() ] as $col)
        {
            $columns[$col] = $this->columnPatch($grammar->wrap($col), $params);
        }

        return $columns;
    }

    /**
     * Get patch for single column.
     *
     * @since 2.0
     *
     * @param string $col
     * @param array  $params
     *
     * @return string
     */
    protected function columnPatch($col, array $params)
    {
        extract($params);

        /** @var int $height */
        if ($height > 0) $height = '+'.$height;

        if (isset($cut))
        {
            return new Expression("case when {$col} >= {$cut} then {$col}{$height} else {$col} end");
        }

        /** @var int $distance */
        /** @var int $lft */
        /** @var int $rgt */
        /** @var int $from */
        /** @var int $to */
        if ($distance > 0) $distance = '+'.$distance;

        return new Expression("case ".
            "when {$col} between {$lft} and {$rgt} then {$col}{$distance} ". // Move the node
            "when {$col} between {$from} and {$to} then {$col}{$height} ". // Move other nodes
            "else {$col} end"
        );
    }

    /**
     * Get statistics of errors of the tree.
     *
     * @since 2.0
     *
     * @return array
     */
    public function countErrors()
    {
        $table = $this->wrappedTable();
        list($lft, $rgt) = $this->wrappedColumns();

        $checks = array();

        // Check if lft and rgt values are ok
        $checks['oddness'] = "from {$table} where {$lft} >= {$rgt} or ({$rgt} - {$lft}) % 2 = 0";

        // Check if lft and rgt values are unique
        $checks['duplicates'] = "from {$table} c1, {$table} c2 where c1.id <> c2.id and ".
            "(c1.{$lft}=c2.{$lft} or c1.{$rgt}=c2.{$rgt} or c1.{$lft}=c2.{$rgt} or c1.{$rgt}=c2.{$lft})";

        // Check if parent_id is set correctly
        $checks['wrong_parent'] =
            "from {$table} c, {$table} p, $table m ".
            "where c.parent_id=p.id and m.id <> p.id and m.id <> c.id and ".
             "(c.{$lft} not between p.{$lft} and p.{$rgt} or c.{$lft} between m.{$lft} and m.{$rgt} and m.{$lft} between p.{$lft} and p.{$rgt})";

        $query = $this->query->newQuery();

        foreach ($checks as $key => $check)
        {
            $query->addSelect(new Expression('(select count(1) '.$check.') as '.$key));
        }

        return (array)$query->first();
    }

}