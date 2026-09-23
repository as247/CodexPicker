<?php

use App\Http\Controllers\Api\AgentConfigController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/config/{config}', [AgentConfigController::class, 'show'])
        ->name('api.v1.config.show');
    Route::get('/config/{config}/models', [AgentConfigController::class, 'models'])
        ->name('api.v1.config.models');
});
