<?php namespace Kalnoy\Nestedset;

use \Illuminate\Database\Eloquent\Collection as BaseCollection;

class Collection extends BaseCollection {

    /**
     * Build tree from node list. Each item will have set children relation.
     *
     * To succesfully build tree "id", "_lft" and "parent_id" keys must present.
     *
     * If {@link rootNodeId} is provided, the tree will contain only descendants
     * of the node with such primary key value.
     *
     * @param integer $rootNodeId
     *
     * @return  Collection
     */
    public function toTree($rootNodeId = null)
    {
        $result = new static();

        if (empty($this->items)) return $result;

        $key = $this->first()->getParentIdName();
        $dictionary = $this->groupBy($key);

        $rootNodeId = $this->getRootNodeId($rootNodeId);

        if (!isset($dictionary[$rootNodeId]))
        {
            return $result;
        }

        $result->items = $dictionary[$rootNodeId];

        foreach ($this->items as $item)
        {
            $key = $item->getKey();

            $children = new BaseCollection(isset($dictionary[$key]) ? $dictionary[$key] : array());

            $item->setRelation('children', $children);
        }

        return $result;
    }

    /**
     * @param null|int $rootNodeId
     *
     * @return int
     */
    public function getRootNodeId($rootNodeId = null)
    {
        // If root node is not specified we take parent id of node with
        // least lft value as root node id.
        if ($rootNodeId === null)
        {
            $leastValue = null;

            foreach ($this->items as $item)
            {
                if ($leastValue === null || $item->getLft() < $leastValue)
                {
                    $leastValue = $item->getLft();
                    $rootNodeId = $item->getParentId();
                }
            }
        }

        return $rootNodeId;
    }
}