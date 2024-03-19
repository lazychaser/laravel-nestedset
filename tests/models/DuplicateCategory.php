<?php

namespace Kalnoy\Nestedset\Tests\Models;

use Kalnoy\Nestedset\NodeTrait;

class DuplicateCategory extends \Illuminate\Database\Eloquent\Model
{
    use NodeTrait;

    protected $table = 'categories';

    protected $fillable = ['name'];

    public $timestamps = false;
}