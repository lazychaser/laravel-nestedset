<?php

class DuplicateCategory extends \Illuminate\Database\Eloquent\Model implements \Kalnoy\Nestedset\Node
{
    use \Kalnoy\Nestedset\NodeTrait;

    protected $table = 'categories';

    protected $fillable = [ 'name' ];

    public $timestamps = false;
}