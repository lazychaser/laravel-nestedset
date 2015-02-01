<?php

namespace Kalnoy\Nestedset;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

class NestedSet {

    /**
     * Add default nested set columns to the table. Also create an index.
     *
     * @param \Illuminate\Database\Schema\Blueprint $table
     * @param string $primaryKey
     */
    public static function columns(Blueprint $table, $primaryKey = 'id')
    {
        $table->unsignedInteger(Node::LFT);
        $table->unsignedInteger(Node::RGT);
        $table->unsignedInteger(Node::PARENT_ID)->nullable();

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
        return [ Node::LFT, Node::RGT, Node::PARENT_ID ];
    }

}