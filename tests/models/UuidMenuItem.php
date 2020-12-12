<?php


class UuidMenuItem extends \Illuminate\Database\Eloquent\Model
{
    use \Kalnoy\Nestedset\NodeTrait;

    public $timestamps = false;

    protected $fillable = ['menu_id','parent_id'];

    protected $table = 'menu_items';

    // public $incrementing = false; << THIS WILL NOT WORK
    public $incrementing = 0;

    public static function resetActionsPerformed()
    {
        static::$actionsPerformed = 0;
    }

    protected function getScopeAttributes()
    {
        return ['menu_id'];
    }

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
