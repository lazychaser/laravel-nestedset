<?php namespace Kalnoy\Nestedset;

use \Illuminate\Database\Eloquent\Collection as BaseCollection;

class Collection extends BaseCollection {

    /**
     * Convert list of nodes to dictionary with specified key.
     *
     * If no key is specified then "parent_id" is used.
     *
     * @param string $key
     *
     * @return  array
     * @deprecated since 1.1
     */
    public function toDictionary($key = null)
    {
        if ($key === null) $key = $this->first()->getParentIdName();

        return $this->groupBy($key)->all();
    }

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

        if ( ! $dictionary->has($rootNodeId))
        {
            return $result;
        }

        $result->items = $dictionary->get($rootNodeId);

        foreach ($this->items as $item)
        {
            $children = $dictionary->get($item->getKey(), []);

            foreach ($children as $child)
            {
                $child->setRelation('parent', $item);
            }

            $item->setRelation('children', new BaseCollection($children));
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