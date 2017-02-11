<?php

namespace Kalnoy\Nestedset;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

class DescendantsRelation extends Relation
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
     * DescendantsRelation constructor.
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

        $query->from($table.' as '.$hash = $this->getRelationCountHash());

        $grammar = $query->getQuery()->getGrammar();

        $table = $grammar->wrapTable($table);
        $hash = $grammar->wrapTable($hash);
        $lft = $grammar->wrap($this->parent->getLftName());
        $rgt = $grammar->wrap($this->parent->getRgtName());

        return $query->whereRaw("{$hash}.{$lft} between {$table}.{$lft} + 1 and {$table}.{$rgt}");
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
    public function getRelationCountHash()
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

        $this->query->whereDescendantOf($this->parent);
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
        $this->query->whereNested(function (Builder $inner) use ($models) {
            // We will use this query in order to apply constraints to the
            // base query builder
            $outer = $this->parent->newQuery();

            foreach ($models as $model) {
                $outer->setQuery($inner)->orWhereDescendantOf($model);
            }
        });
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
            $descendants = $this->getDescendantsForModel($model, $results);

            $model->setRelation($relation, $descendants);
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
    protected function getDescendantsForModel(Model $model, EloquentCollection $results)
    {
        $result = $this->related->newCollection();

        foreach ($results as $descendant) {
            if ($descendant->isDescendantOf($model)) {
                $result->push($descendant);
            }
        }

        return $result;
    }
}