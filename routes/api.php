<?php

use App\Http\Controllers\DomainController;
use App\Http\Controllers\DomainImageController;
use Illuminate\Support\Facades\Route;

Route::get('/domains', [DomainController::class, 'index']);
Route::post('/domains', [DomainController::class, 'store']);

Route::get('/domains/images', [DomainImageController::class, 'index']);
Route::post('/domains/images', [DomainImageController::class, 'store']);
