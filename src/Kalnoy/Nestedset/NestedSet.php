<?php namespace Kalnoy\Nestedset;

use \Illuminate\Database\Connection;
use \Illuminate\Database\Schema\Blueprint;

class NestedSet {

    /**
     * Add NestedSet columns to the table. Also create index and foreign key.
     *
     * @param   Blueprint  $table
     *
     * @return  void
     */
    static public function columns(Blueprint $table, $primaryKey = 'id')
    {
        $table->integer(Node::LFT);
        $table->integer(Node::RGT);
        $table->unsignedInteger(Node::PARENT_ID)->nullable();

        $table->index([ Node::LFT, Node::RGT, Node::PARENT_ID ], 'nested_set_index');

        $table
            ->foreign(Node::PARENT_ID, 'nested_set_foreign')
            ->references($primaryKey)
            ->on($table->getTable())
            ->onDelete('cascade');
    }

    /**
     * Drop NestedSet columns.
     *
     * @param   Blueprint  $table
     *
     * @return  void
     */
    static public function dropColumns(Blueprint $table)
    {
        $table->dropForeign('nested_set_foreign');
        $table->dropIndex('nested_set_index');
        $table->dropColumn(Node::LFT, Node::RGT, Node::PARENT_ID);
    }

    /**
     * Create root node.
     *
     * @param   string  $table
     * @param   array   $extra
     * @param   string  $connection
     *
     * @return  boolean
     */
    static function createRoot($table, array $extra = array(), $connection = null)
    {
        $extra = array_merge($extra, array(
            Node::LFT => 1,
            Node::RGT => 2,
            Node::PARENT_ID => NULL,    
        ));

        return \DB::connection($connection)->table($table)->insert($extra);
    }
}