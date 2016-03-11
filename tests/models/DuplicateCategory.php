<?php

class DuplicateCategory extends \Illuminate\Database\Eloquent\Model
{
    use \Kalnoy\Nestedset\NodeTrait;

    protected $table = 'categories';

    protected $fillable = [ 'name' ];

    public $timestamps = false;
}