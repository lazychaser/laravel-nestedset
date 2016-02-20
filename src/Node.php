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