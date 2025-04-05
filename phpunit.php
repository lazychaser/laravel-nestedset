<?php

include __DIR__.'/vendor/autoload.php';

$capsule = new \Illuminate\Database\Capsule\Manager;
$capsule->addConnection([ 'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'prfx_' ]);
$capsule->setEventDispatcher(new \Illuminate\Events\Dispatcher);
$capsule->bootEloquent();
$capsule->setAsGlobal();

include __DIR__.'/tests/models/Category.php';
include __DIR__.'/tests/models/MenuItem.php';
include __DIR__.'/tests/ScopedNodeTestBase.php';
include __DIR__.'/tests/NodeTestBase.php';
include __DIR__.'/tests/models/CategoryUuid.php';
include __DIR__.'/tests/models/MenuItemUuid.php';
include __DIR__.'/tests/data/CategoryData.php';
include __DIR__.'/tests/data/MenuItemData.php';
