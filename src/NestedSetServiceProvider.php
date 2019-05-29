<?php

namespace Kalnoy\Nestedset;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;

class NestedSetServiceProvider extends ServiceProvider
{
    public function register()
    {
        Blueprint::macro('nestedSet', function (string $idColumn = 'id') {
            NestedSet::columns($this, $idColumn);
        });

        Blueprint::macro('dropNestedSet', function (string $idColumn = 'id') {
            NestedSet::dropColumns($this, $idColumn);
        });
    }
}
