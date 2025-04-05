<?php

class CategoryData
{
    private array $ids;

    public function __construct(bool $withUuid = false)
    {
        if ($withUuid) {
            for ($i = 1; $i <= 200; $i++) {
                $this->ids[$i] = (string)\Illuminate\Support\Str::uuid();
            }
        } else {
            $this->ids = array_combine(range(1, 200), range(1, 200));
        }
    }

    public function getData(): array
    {
        return array(
            array('id' => $this->ids[1], 'name' => 'store', '_lft' => 1, '_rgt' => 20, 'parent_id' => null),
                array('id' => $this->ids[2], 'name' => 'notebooks', '_lft' => 2, '_rgt' => 7, 'parent_id' => $this->ids[1]),
                    array('id' => $this->ids[3], 'name' => 'apple', '_lft' => 3, '_rgt' => 4, 'parent_id' => $this->ids[2]),
                    array('id' => $this->ids[4], 'name' => 'lenovo', '_lft' => 5, '_rgt' => 6, 'parent_id' => $this->ids[2]),
                array('id' => $this->ids[5], 'name' => 'mobile', '_lft' => 8, '_rgt' => 19, 'parent_id' => $this->ids[1]),
                    array('id' => $this->ids[6], 'name' => 'nokia', '_lft' => 9, '_rgt' => 10, 'parent_id' => $this->ids[5]),
                    array('id' => $this->ids[7], 'name' => 'samsung', '_lft' => 11, '_rgt' => 14, 'parent_id' => $this->ids[5]),
                        array('id' => $this->ids[8], 'name' => 'galaxy', '_lft' => 12, '_rgt' => 13, 'parent_id' => $this->ids[7]),
                    array('id' => $this->ids[9], 'name' => 'sony', '_lft' => 15, '_rgt' => 16, 'parent_id' => $this->ids[5]),
                    array('id' => $this->ids[10], 'name' => 'lenovo', '_lft' => 17, '_rgt' => 18, 'parent_id' => $this->ids[5]),
            array('id' => $this->ids[11], 'name' => 'store_2', '_lft' => 21, '_rgt' => 22, 'parent_id' => null),
        );
    }

    public function getIds(): array
    {
        return $this->ids;
    }
}



