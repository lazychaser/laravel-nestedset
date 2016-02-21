<?php

namespace Kalnoy\Nestedset;

use Illuminate\Database\Eloquent\Model;

/**
 * @deprecated since 4.1
 */
class Node extends Model
{
    use NodeTrait;

    const LFT = NestedSet::LFT;

    const RGT = NestedSet::RGT;

    const PARENT_ID = NestedSet::PARENT_ID;

    /**
     * @param Node $parent
     *
     * @return $this
     *
     * @deprecated since 4.1
     */
    public function appendTo(self $parent)
    {
        return $this->appendToNode($parent);
    }

    /**
     * @param Node $parent
     *
     * @return $this
     *
     * @deprecated since 4.1
     */
    public function prependTo(self $parent)
    {
        return $this->prependToNode($parent);
    }

    /**
     * @param Node $node
     *
     * @return bool
     *
     * @deprecated since 4.1
     */
    public function insertBefore(self $node)
    {
        return $this->insertBeforeNode($node);
    }

    /**
     * @param Node $node
     *
     * @return bool
     *
     * @deprecated since 4.1
     */
    public function insertAfter(self $node)
    {
        return $this->insertAfterNode($node);
    }

    /**
     * @param array $columns
     *
     * @return self|null
     *
     * @deprecated since 4.1
     */
    public function getNext(array $columns = [ '*' ])
    {
        return $this->getNextNode($columns);
    }

    /**
     * @param array $columns
     *
     * @return self|null
     *
     * @deprecated since 4.1
     */
    public function getPrev(array $columns = [ '*' ])
    {
        return $this->getPrevNode($columns);
    }

    /**
     * @return string
     */
    public function getParentIdName()
    {
        return static::PARENT_ID;
    }

    /**
     * @return string
     */
    public function getLftName()
    {
        return static::LFT;
    }

    /**
     * @return string
     */
    public function getRgtName()
    {
        return static::RGT;
    }

}