<?php

namespace Kalnoy\Nestedset;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class QueryBuilder extends Builder
{
    use QueriesNestedSets;

    /**
     * @var NodeTrait|Model
     */
    protected $model;
}
