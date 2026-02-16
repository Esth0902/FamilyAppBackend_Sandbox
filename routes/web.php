<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return ['Laravel' => app()->version()];
});

Route::view('/test-app', 'test_app');

require __DIR__.'/auth.php';
