<?php

return [
    ['id' => 1, 'name' => 'store', '_lft' => 1, '_rgt' => 20, 'parent_category_id' => null],
        ['id' => 2, 'name' => 'notebooks', '_lft' => 2, '_rgt' => 7, 'parent_category_id' => 1],
            ['id' => 3, 'name' => 'apple', '_lft' => 3, '_rgt' => 4, 'parent_category_id' => 2],
            ['id' => 4, 'name' => 'lenovo', '_lft' => 5, '_rgt' => 6, 'parent_category_id' => 2],
        ['id' => 5, 'name' => 'mobile', '_lft' => 8, '_rgt' => 19, 'parent_category_id' => 1],
            ['id' => 6, 'name' => 'nokia', '_lft' => 9, '_rgt' => 10, 'parent_category_id' => 5],
            ['id' => 7, 'name' => 'samsung', '_lft' => 11, '_rgt' => 14, 'parent_category_id' => 5],
                ['id' => 8, 'name' => 'galaxy', '_lft' => 12, '_rgt' => 13, 'parent_category_id' => 7],
            ['id' => 9, 'name' => 'sony', '_lft' => 15, '_rgt' => 16, 'parent_category_id' => 5],
            ['id' => 10, 'name' => 'lenovo', '_lft' => 17, '_rgt' => 18, 'parent_category_id' => 5],
    ['id' => 11, 'name' => 'store_2', '_lft' => 21, '_rgt' => 22, 'parent_category_id' => null],
];
