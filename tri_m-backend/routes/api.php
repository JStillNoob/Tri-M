<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InventoryImportController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\StockPurchaseController;
use App\Http\Controllers\SupplierStockImportController;
use Illuminate\Support\Facades\Route;

Route::post('login', [AuthController::class, 'login'])->middleware('throttle:pos-login');
Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

Route::middleware(['auth:sanctum', 'pos.active'])->group(function (): void {
    Route::get('user', [AuthController::class, 'show']);
    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/{product}', [ProductController::class, 'show'])->whereNumber('product');
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('sales', [SaleController::class, 'index']);
    Route::get('sales/{sale}', [SaleController::class, 'show'])->whereNumber('sale');
    Route::post('sales', [SaleController::class, 'store'])->middleware('pos.role:cashier');

    Route::middleware('pos.role:owner')->group(function (): void {
        Route::post('products', [ProductController::class, 'store']);
        Route::put('products/{product}', [ProductController::class, 'update'])->whereNumber('product');
        Route::delete('products/{product}', [ProductController::class, 'destroy'])->whereNumber('product');
        Route::post('products/{product}/restore', [ProductController::class, 'restore'])->whereNumber('product');
        Route::post('categories', [CategoryController::class, 'store']);
        Route::put('categories/{category}', [CategoryController::class, 'update'])->whereNumber('category');
        Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->whereNumber('category');
        Route::get('stock-purchases', [StockPurchaseController::class, 'index']);
        Route::get('stock-purchases/product', [StockPurchaseController::class, 'product']);
        Route::post('stock-purchases', [StockPurchaseController::class, 'store']);
        Route::get('suppliers', [StockPurchaseController::class, 'suppliers']);
        Route::post('suppliers', [StockPurchaseController::class, 'storeSupplier']);

        Route::get('imports', [InventoryImportController::class, 'index']);
        Route::post('imports/preview', [InventoryImportController::class, 'preview'])->middleware('throttle:pos-import');
        Route::post('imports/confirm', [InventoryImportController::class, 'confirm']);
        Route::get('stock-movements', [SupplierStockImportController::class, 'movements']);
        Route::prefix('imports')->controller(SupplierStockImportController::class)->group(function (): void {
            Route::get('review-catalog', 'reviewCatalog');
            Route::post('confirm-workbook', 'confirmWorkbook')->middleware('throttle:pos-import');
            Route::post('upload', 'upload')->middleware('throttle:pos-import');
            Route::whereUuid('import')->prefix('{import}')->group(function (): void {
                Route::get('/', 'show');
                Route::get('worksheet', 'worksheet');
                Route::put('mapping', 'map');
                Route::put('markup', 'markup');
                Route::put('rows/{row}', 'review')->whereNumber('row');
                Route::post('approve', 'approve');
                Route::post('cancel', 'cancel');
            });
        });

        Route::prefix('dashboard')->controller(DashboardController::class)->group(function (): void {
            Route::get('overview', 'overview');
            Route::get('stock-financials', 'stockFinancials');
            Route::get('recent-orders', 'recentOrders');
            Route::get('targets', 'target');
            Route::put('targets', 'updateTarget');
            Route::get('summary', 'summary');
            Route::get('sales', 'sales');
            Route::get('payments', 'payments');
            Route::get('top-products', 'topProducts');
            Route::get('low-stock', 'lowStock');
        });
    });
});
