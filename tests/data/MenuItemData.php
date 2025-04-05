<?php

class MenuItemData
{
    private array $ids;

    public function __construct(bool $withUuid = false)
    {
        if ($withUuid) {
            for ($i = 1; $i <= 6; $i++) {
                $this->ids[$i] = (string) \Illuminate\Support\Str::uuid();
            }
        } else {
            $this->ids = array_combine(range(1, 10), range(1, 10));
        }
    }

    public function getData(): array
    {
        return [
            array('id' => $this->ids[1], 'menu_id' => 1, '_lft' => 1, '_rgt' => 2, 'parent_id' => null, 'title' => 'menu item 1'),
            array('id' => $this->ids[2], 'menu_id' => 1, '_lft' => 3, '_rgt' => 6, 'parent_id' => null, 'title' => 'menu item 2'),
                array('id' => $this->ids[5], 'menu_id' => 1, '_lft' => 4, '_rgt' => 5, 'parent_id' => $this->ids[2], 'title' => 'menu item 3'),
            array('id' => $this->ids[3], 'menu_id' => 2, '_lft' => 1, '_rgt' => 2, 'parent_id' => null, 'title' => 'menu item 1'),
            array('id' => $this->ids[4], 'menu_id' => 2, '_lft' => 3, '_rgt' => 6, 'parent_id' => null, 'title' => 'menu item 2'),
                array('id' => $this->ids[6], 'menu_id' => 2, '_lft' => 4, '_rgt' => 5, 'parent_id' => $this->ids[4], 'title' => 'menu item 3'),
        ];
    }

    public function getIds(): array {
        return $this->ids;
    }
}



