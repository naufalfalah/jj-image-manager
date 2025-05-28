<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('index');
});

// get domains function
Route::get('/domains', function () {
    // Here you would typically fetch domains from a database or service
    $domains = [
        ['id' => 1, 'name' => 'example.com'],
        ['id' => 2, 'name' => 'test.com'],
    ];
    return view('domains', ['domains' => $domains]);
});

// create add domain function
Route::get('/add-domain', function () {
    return view('add-domain');
});


