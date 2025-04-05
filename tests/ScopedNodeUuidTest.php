<?php

use Kalnoy\Nestedset\NestedSet;

class ScopedNodeUuidTest extends ScopedNodeTestBase
{
    public function __construct($name = null)
    {
        parent::__construct($name);
        $this->menuItemData = new MenuItemData();
    }

    protected function getTable(): string
    {
        return 'uuid_menu_items';
    }

    protected function getModelClass(): string
    {
        return MenuItemUuid::class;
    }

    protected function createTable(\Illuminate\Database\Schema\Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->unsignedInteger('menu_id');
        $table->string('title')->nullable();
        NestedSet::columns($table, true);
    }
}
