<?php

use App\Http\Controllers\DomainController;
use App\Http\Controllers\DomainImageController;
use Illuminate\Support\Facades\Route;

Route::get('/domains', [DomainController::class, 'index']);
Route::post('/domains', [DomainController::class, 'store']);
Route::put('/domains/{id}', [DomainController::class, 'update']);
Route::delete('/domains/{id}', [DomainController::class, 'destroy']);

Route::get('/domains/images', [DomainImageController::class, 'index']);
Route::post('/domains/images', [DomainImageController::class, 'store']);
Route::post('/domains/images/{id}', [DomainImageController::class, 'update']);
Route::delete('/domains/images/{id}', [DomainImageController::class, 'destroy']);
Route::post('/domains/images/{id}/copy', [DomainImageController::class, 'copy']);
Route::post('/domains/images/{id}/move', [DomainImageController::class, 'move']);
