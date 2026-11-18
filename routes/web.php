<?php

use App\Http\Controllers\EstimateGenerationController;
use App\Http\Controllers\PurchaseCreditsController;
use App\Http\Controllers\ShowGenerationController;
use App\Http\Controllers\SpendReportController;
use App\Http\Controllers\StoreGenerationController;
use App\Http\Controllers\StudioController;
use Illuminate\Broadcasting\BroadcastController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::post('/broadcasting/auth', [BroadcastController::class, 'authenticate'])->middleware('auth')->name('broadcasting.auth');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', StudioController::class)->name('dashboard');
    Route::get('/studio', StudioController::class)->name('studio');
    Route::post('/generations/estimate', EstimateGenerationController::class)->middleware('throttle:60,1')->name('generations.estimate');
    Route::post('/generations', StoreGenerationController::class)->middleware('throttle:20,1')->name('generations.store');
    Route::get('/generations/{generation}', ShowGenerationController::class)->name('generations.show');
    Route::post('/credits/purchase', PurchaseCreditsController::class)->middleware('throttle:10,1')->name('credits.purchase');
    Route::get('/admin/reports/spend', SpendReportController::class)->middleware(['can:admin', 'throttle:30,1'])->name('admin.spend');
});

require __DIR__.'/settings.php';
