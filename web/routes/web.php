<?php

use App\Http\Controllers\Shopify\AuthController;
use App\Http\Controllers\Shopify\FallbackController;
use App\Http\Controllers\Shopify\ProductController;
use App\Http\Controllers\Shopify\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
| If you are adding routes outside of the /api path, remember to also add a
| proxy rule for them in web/frontend/vite.config.js
|
*/

Route::fallback(FallbackController::class)->middleware('shopify.installed');

Route::get('/api/auth', [AuthController::class, 'index']);

Route::get('/api/auth/callback', [AuthController::class, 'callback']);

Route::get('/api/products/count', [ProductController::class, 'count'])->middleware('shopify.auth');

Route::post('/api/products', [ProductController::class, 'store'])->middleware('shopify.auth');

Route::post('/api/webhooks', WebhookController::class);
