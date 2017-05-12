<?php

namespace Kalnoy\Nestedset;

use Illuminate\Database\Eloquent\Model;

class AncestorsRelation extends BaseRelation
{
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
     * @param Model $model
     * @param $related
     *
     * @return bool
     */
    protected function matches(Model $model, $related)
    {
        return $related->isAncestorOf($model);
    }

    /**
     * @param QueryBuilder $query
     * @param Model $model
     *
     * @return void
     */
    protected function addEagerConstraint($query, $model)
    {
        $query->orWhereAncestorOf($model);
    }

    /**
     * @param $hash
     * @param $table
     * @param $lft
     * @param $rgt
     *
     * @return string
     */
    protected function relationExistenceCondition($hash, $table, $lft, $rgt)
    {
        $key = $this->getBaseQuery()->getGrammar()->wrap($this->parent->getKeyName());

        return "{$table}.{$rgt} between {$hash}.{$lft} and {$hash}.{$rgt} and $table.$key <> $hash.$key";
    }
}
