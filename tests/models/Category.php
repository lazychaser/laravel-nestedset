<?php

namespace Kalnoy\Nestedset\Tests\Models;

use \Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Kalnoy\Nestedset\NodeTrait;

class Category extends Model {

    use SoftDeletes;
    use NodeTrait;

    protected $fillable = ['name', 'parent_id'];

    public $timestamps = false;

    public static function resetActionsPerformed()
    {
        static::$actionsPerformed = 0;
    }
}