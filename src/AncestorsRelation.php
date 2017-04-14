<?php

namespace Kalnoy\Nestedset;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use RuntimeException;

class AncestorsRelation extends Relation
{
    /**
     * @var QueryBuilder
     */
    protected $query;

    /**
     * @var NodeTrait|Model
     */
    protected $parent;

    /**
     * AncestorsRelation constructor.
     *
     * @param QueryBuilder $builder
     * @param Model $model
     */
    public function __construct(QueryBuilder $builder, Model $model)
    {
        if ( ! NestedSet::isNode($model)) {
            throw new InvalidArgumentException('Model must be node.');
        }

        parent::__construct($builder, $model);
    }

    /**
     * @param EloquentBuilder $query
     * @param EloquentBuilder $parentQuery
     *
     * @return null
     */
    public function getRelationExistenceCountQuery(EloquentBuilder $query, EloquentBuilder $parentQuery)
    {
        throw new RuntimeException('Cannot count ancestors, use depth functionality instead');
    }

    /**
     * @param EloquentBuilder $query
     * @param EloquentBuilder $parent
     * @param array $columns
     *
     * @return mixed
     */
    public function getRelationExistenceQuery(EloquentBuilder $query, EloquentBuilder $parent,
                                              $columns = [ '*' ]
    ) {
        $query->select($columns);

        $table = $query->getModel()->getTable();

        $query->from($table.' as '.$hash = $this->getRelationSubSelectHash());

        $grammar = $query->getQuery()->getGrammar();

        $table = $grammar->wrapTable($table);
        $hash = $grammar->wrapTable($hash);
        $parentIdName = $grammar->wrap($this->parent->getParentIdName());

        return $query->whereRaw("{$hash}.{$parentIdName} = {$table}.{$parentIdName}");
    }

    /**
     * @param EloquentBuilder $query
     * @param EloquentBuilder $parent
     * @param array $columns
     *
     * @return mixed
     */
    public function getRelationQuery(
        EloquentBuilder $query, EloquentBuilder $parent,
        $columns = [ '*' ]
    ) {
        return $this->getRelationExistenceQuery($query, $parent, $columns);
    }

    /**
     * Get a relationship join table hash.
     *
     * @return string
     */
    public function getRelationSubSelectHash()
    {
        return 'self_'.md5(microtime(true));
    }

    /**
     * Set the base constraints on the relation query.
     *
     * @return void
     */
    public function addConstraints()
    {
        if ( ! static::$constraints) return;

        $this->query->whereAncestorOf($this->parent)->defaultOrder();
    }

    /**
     * Set the constraints for an eager load of the relation.
     *
     * @param  array $models
     *
     * @return void
     */
    public function addEagerConstraints(array $models)
    {
        $model = $this->query->getModel();
        $table = $model->getTable();
        $key = $model->getKeyName();

        $grammar = $this->query->getQuery()->getGrammar();

        $table = $grammar->wrapTable($table);
        $hash = $grammar->wrap($this->getRelationSubSelectHash());
        $key = $grammar->wrap($key);
        $lft = $grammar->wrap($this->parent->getLftName());
        $rgt = $grammar->wrap($this->parent->getRgtName());

        $sql = "$key IN (SELECT DISTINCT($key) FROM {$table} INNER JOIN "
            . "(SELECT {$lft}, {$rgt} FROM {$table} WHERE {$key} IN (" . implode(',', $this->getKeys($models))
            . ")) AS $hash ON {$table}.{$lft} <= {$hash}.{$lft} AND {$table}.{$rgt} >= {$hash}.{$rgt})";

        $this->query->whereNested(function (Builder $inner) use ($sql) {
            $inner->whereRaw($sql);
        });
        $this->query->orderBy('lft', 'ASC');
    }

    /**
     * Initialize the relation on a set of models.
     *
     * @param  array $models
     * @param  string $relation
     *
     * @return array
     */
    public function initRelation(array $models, $relation)
    {
        return $models;
    }

    /**
     * Match the eagerly loaded results to their parents.
     *
     * @param  array $models
     * @param  EloquentCollection $results
     * @param  string $relation
     *
     * @return array
     */
    public function match(array $models, EloquentCollection $results, $relation)
    {
        foreach ($models as $model) {
            $ancestors = $this->getAncestorsForModel($model, $results);

            $model->setRelation($relation, $ancestors);
        }

        return $models;
    }

    /**
     * Get the results of the relationship.
     *
     * @return mixed
     */
    public function getResults()
    {
        return $this->query->get();
    }

    /**
     * @param Model $model
     * @param EloquentCollection $results
     *
     * @return Collection
     */
    protected function getAncestorsForModel(Model $model, EloquentCollection $results)
    {
        $result = $this->related->newCollection();

        foreach ($results as $ancestor) {
            if ($ancestor->isAncestorOf($model)) {
                $result->push($ancestor);
            }
        }

        return $result;
    }
}
