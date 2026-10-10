<?php

use Illuminate\Support\Facades\Route;

Route::domain(config('app.cms_domain'))->group(function () {
    Route::redirect('/', '/admin');
});

Route::get('/', function () {
    return view('welcome');
});
