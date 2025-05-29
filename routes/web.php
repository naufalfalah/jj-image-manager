<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $awsBucket = env('AWS_BUCKET');
    $awsRegion = env('AWS_DEFAULT_REGION');

    return view('index', compact('awsBucket', 'awsRegion'));
});
