<?php

class MenuItem extends \Kalnoy\Nestedset\Node
{
    public $timestamps = false;
    
    protected $fillable = [ 'menu_id' ];
    
    public static function resetActionsPerformed()
    {
        static::$actionsPerformed = 0;
    }
    
    protected function getScopeAttributes()
    {
        return [ 'menu_id' ];
    }

}