<?php

class Category extends Kalnoy\Nestedset\Node {

    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $fillable = array('name', 'parent_id');

    public $timestamps = false;

    public static function resetActionsPerformed()
    {
        static::$actionsPerformed = 0;
    }
}