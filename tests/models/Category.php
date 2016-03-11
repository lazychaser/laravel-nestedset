<?php

use \Illuminate\Database\Eloquent\Model;

class Category extends Model {

    use \Illuminate\Database\Eloquent\SoftDeletes, \Kalnoy\Nestedset\NodeTrait;

    protected $fillable = array('name', 'parent_id');

    public $timestamps = false;

    public static function resetActionsPerformed()
    {
        static::$actionsPerformed = 0;
    }
}