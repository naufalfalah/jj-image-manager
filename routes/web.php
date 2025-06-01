<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'loginPage'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.post');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::get('/', function () {
    $awsBucket = env('AWS_BUCKET');
    $awsRegion = env('AWS_DEFAULT_REGION');

    return view('index', compact('awsBucket', 'awsRegion'));
})->middleware('auth')->name('dashboard.index');
