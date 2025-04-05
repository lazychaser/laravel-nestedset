<?php

use Kalnoy\Nestedset\NestedSet;

class NodeTest extends NodeTestBase
{
    public function __construct($name = null)
    {
        parent::__construct($name);
        $this->categoryData = new CategoryData();
    }

    protected function getTable(): string
    {
        return 'categories';
    }

    protected function getModelClass(): string
    {
        return Category::class;
    }

    protected function createTable(\Illuminate\Database\Schema\Blueprint $table): void
    {
        $table->increments('id');
        $table->string('name');
        $table->softDeletes();
        NestedSet::columns($table);
    }
}
