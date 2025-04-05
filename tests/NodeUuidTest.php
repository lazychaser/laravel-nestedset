<?php

use Kalnoy\Nestedset\NestedSet;

class NodeUuidTest extends NodeTestBase
{
    public function __construct($name = null)
    {
        parent::__construct($name);
        $this->categoryData = new CategoryData();
    }

    protected function getTable(): string
    {
        return 'uuid_categories';
    }

    protected function getModelClass(): string
    {
        return CategoryUuid::class;
    }

    protected function createTable(\Illuminate\Database\Schema\Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->string('name');
        $table->softDeletes();
        NestedSet::columns($table, true);
    }
}
