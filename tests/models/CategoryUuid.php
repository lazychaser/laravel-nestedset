<?php

class CategoryUuid extends Category
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $table = 'uuid_categories';

    protected $keyType = 'string';

    public $incrementing = false;
}
