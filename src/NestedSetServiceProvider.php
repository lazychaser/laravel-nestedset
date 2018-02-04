<?php

namespace Kalnoy\Nestedset;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;

class NestedSetServiceProvider extends ServiceProvider
{
    public function register()
    {
        Blueprint::macro('nestedSet', function () {
            NestedSet::columns($this);
        });

        Blueprint::macro('dropNestedSet', function () {
            NestedSet::dropColumns($this);
        });
    }
}