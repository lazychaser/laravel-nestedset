<?php

include __DIR__.'/vendor/autoload.php';

$capsule = new \Illuminate\Database\Capsule\Manager;
$capsule->addConnection(array('driver' => 'sqlite', 'database' => ':memory:'));
$capsule->setEventDispatcher(new \Illuminate\Events\Dispatcher);
$capsule->bootEloquent();
$capsule->setAsGlobal();

include __DIR__.'/tests/models/Category.php';