<?php

use \Illuminate\Database\Eloquent\Model;

class UuidCategory extends Model
{
    use \Illuminate\Database\Eloquent\SoftDeletes, \Kalnoy\Nestedset\NodeTrait;

    protected $fillable = array('name', 'parent_id');
    protected $table = 'categories';
    public $timestamps = false;

    // public $incrementing = false; << THIS WILL NOT WORK
    public $incrementing = 0;

    protected static function boot()
    {
        static::saving(
            function ($model) {
                if (!$model->{$model->getKeyName()}) {
                    $model->{$model->getKeyName()} = (string)$model->uuid4();
                }
            }
        );
        parent::boot();
    }

    public static function resetActionsPerformed()
    {
        static::$actionsPerformed = 0;
    }

    private function uuid4()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}