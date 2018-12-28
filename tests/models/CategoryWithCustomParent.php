<?php

use \Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Kalnoy\Nestedset\NodeTrait;

class CategoryWithCustomParent extends Model
{
    use SoftDeletes;
    use NodeTrait;

    protected $table = 'categories_with_custom_parent';

    protected $fillable = [
        'name',
        'parent_category_id',
    ];

    public $timestamps = false;

    public static function resetActionsPerformed()
    {
        static::$actionsPerformed = 0;
    }

    public function getParentIdName()
    {
        return 'parent_category_id';
    }
}
