<?php

use App\Http\Controllers\ProductSettingController;
use App\Http\Controllers\InventorySettingController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\ZohoAuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Webhooks
Route::post('/webhooks/shopify/product', [SyncController::class, 'handleShopifyWebhook']);

// Zoho Authentication & Org Connect
Route::get('/zoho/status', [ZohoAuthController::class, 'status']);
Route::get('/zoho/organizations', [ZohoAuthController::class, 'getOrganizations']);
Route::get('/zoho/configuration', [ZohoAuthController::class, 'getConfiguration']);
Route::get('/zoho/accounts', [ZohoAuthController::class, 'getAccounts']);
Route::get('/zoho/taxes', [ZohoAuthController::class, 'getTaxes']);
Route::post('/zoho/update-configuration', [ZohoAuthController::class, 'updateConfiguration']);
Route::get('/zoho/warehouses', [ZohoAuthController::class, 'getWarehouses']);

// Product Settings
Route::post('/update-product-settings', [ProductSettingController::class, 'update']);
Route::get('/get-product-settings', [ProductSettingController::class, 'get']);

// Inventory Settings
Route::post('/update-inventory-settings', [InventorySettingController::class, 'update']);
Route::get('/get-inventory-settings', [InventorySettingController::class, 'get']);

// Sync Commands & Audit Info
Route::get('/zoho/sync-now', [SyncController::class, 'syncNow']);
Route::get('/zoho/items', [SyncController::class, 'getZohoItems']);
Route::get('/zoho/logs', [SyncController::class, 'getLogs']);
Route::get('/zoho/metrics', [SyncController::class, 'getMetrics']);
Route::get('/synced-products', [SyncController::class, 'getSyncedProducts']);

// Webhook

Route::post('/webhook/zoho', [WebhookController::class, 'index']);
