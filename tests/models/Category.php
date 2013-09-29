<?php

class Category extends Kalnoy\Nestedset\Node { 
    protected $fillable = array('name', 'parent_id');

    public $timestamps = false;
}