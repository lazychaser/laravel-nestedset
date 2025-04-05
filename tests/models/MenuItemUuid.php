<?php

class MenuItemUuid extends MenuItem
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $table = 'uuid_menu_items';

    protected $keyType = 'string';

    public $incrementing = false;
}
