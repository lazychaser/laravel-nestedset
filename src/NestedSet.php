<?php

namespace Kalnoy\Nestedset;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use \Kalnoy\Nestedset\NodeTrait as Node;

class NestedSet {

    /**
     * Add default nested set columns to the table. Also create an index.
     *
     * @param \Illuminate\Database\Schema\Blueprint $table
     * @param string $primaryKey
     */
    public static function columns(Blueprint $table, $primaryKey = 'id')
    {
        $table->unsignedInteger(Node::$lft);
        $table->unsignedInteger(Node::$rgt);
        $table->unsignedInteger(Node::$parentId)->nullable();

        $table->index(self::getDefaultColumns());
    }

    /**
     * Drop NestedSet columns.
     *
     * @param \Illuminate\Database\Schema\Blueprint $table
     */
    public static function dropColumns(Blueprint $table)
    {
        $columns = self::getDefaultColumns();

        $table->dropIndex($columns);
        $table->dropColumn($columns);
    }

    /**
     * Get a list of default columns.
     * 
     * @return array
     */
    public static function getDefaultColumns()
    {
        return [ Node::$lft, Node::$rgt, Node::$parentId ];
    }

}