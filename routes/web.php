<?php

use App\Http\Controllers\ManagementController as Manage;
use App\Http\Controllers\StoreController as Store;
use App\Http\Controllers\TransactionController as Tx;
use Illuminate\Support\Facades\Route;

Route::get('/', [Store::class, 'home'])->name('home');
Route::get('/eyewear', [Store::class, 'catalog'])->name('catalog');
Route::get('/eyewear/{product}', [Store::class, 'product'])->name('product');
Route::get('/information/{page}', [Store::class, 'info'])->name('info');
Route::get('/login', [Store::class, 'login'])->name('login');
Route::post('/login', [Store::class, 'authenticate'])->middleware('throttle:6,1');
Route::get('/register', [Store::class, 'register'])->name('register');
Route::post('/register', [Store::class, 'signup'])->middleware('throttle:6,1');
Route::post('/logout', [Store::class, 'logout'])->name('logout');
Route::redirect('/bag', '/cart');
Route::get('/cart', [Store::class, 'cart'])->name('cart');
Route::post('/cart/{product}', [Store::class, 'addCart'])->name('cart.add');
Route::put('/cart', [Store::class, 'updateCart'])->name('cart.update');
Route::put('/cart/{product}', [Store::class, 'editCartItem'])->name('cart.edit');
Route::delete('/cart/{product}', [Store::class, 'removeCartItem'])->name('cart.remove');
Route::middleware('role:Customer')->group(function () {
    Route::get('/checkout', [Store::class, 'checkout'])->name('checkout');
    Route::post('/checkout', [Store::class, 'place']);
    Route::get('/account', [Store::class, 'account'])->name('account');
    Route::put('/account', [Store::class, 'profile']);
    Route::get('/my-orders/{order}', [Store::class, 'myOrder'])->name('my.order');
    Route::post('/my-orders/{order}/cancel', [Store::class, 'cancel'])->name('my.cancel');
});
Route::middleware('role:Admin,Staff,Customer')->group(function () {
    Route::get('/invoices/{billing}', [Store::class, 'invoice'])->name('invoice');
    Route::get('/receipts/{payment}', [Store::class, 'receipt'])->name('receipt');
});
Route::prefix('manage')->middleware('role:Admin,Staff')->group(function () {
    Route::get('/', [Manage::class, 'dashboard'])->name('dashboard');
    Route::get('/records/{resource}', [Manage::class, 'records'])->name('records');
    Route::get('/records/{resource}/create', [Manage::class, 'form'])->name('record.create');
    Route::post('/records/{resource}', [Manage::class, 'save'])->name('record.store');
    Route::get('/records/{resource}/{id}/edit', [Manage::class, 'form'])->whereNumber('id')->name('record.edit');
    Route::put('/records/{resource}/{id}', [Manage::class, 'save'])->whereNumber('id')->name('record.update');
    Route::get('/stock', [Manage::class, 'stock'])->name('stock');
    Route::get('/orders', [Tx::class, 'orders'])->name('orders');
    Route::get('/orders/create', [Tx::class, 'orderForm'])->name('orders.create');
    Route::post('/orders', [Tx::class, 'createOrder'])->name('orders.store');
    Route::get('/orders/{order}', [Tx::class, 'order'])->name('orders.show');
    Route::post('/orders/{order}/status', [Tx::class, 'orderStatus'])->name('orders.status');
    Route::get('/orders/{order}/bill', [Tx::class, 'billForm'])->name('billing.create');
    Route::post('/orders/{order}/bill', [Tx::class, 'createBill'])->name('billing.store');
    Route::get('/billing', [Tx::class, 'billing'])->name('billing');
    Route::get('/billing/{billing}', [Tx::class, 'bill'])->name('billing.show');
    Route::post('/billing/{billing}/payments', [Tx::class, 'pay'])->name('payments.store');
    Route::get('/payments', [Tx::class, 'payments'])->name('payments');
    Route::middleware('role:Admin')->group(function () {
        Route::get('/purchases', [Tx::class, 'purchases'])->name('purchases');
        Route::get('/purchases/create', [Tx::class, 'purchaseForm'])->name('purchases.create');
        Route::post('/purchases', [Tx::class, 'createPurchase'])->name('purchases.store');
        Route::get('/purchases/{purchase}', [Tx::class, 'purchase'])->name('purchases.show');
        Route::post('/purchases/{purchase}/status', [Tx::class, 'purchaseStatus'])->name('purchases.status');
        Route::post('/purchases/{purchase}/receive', [Tx::class, 'receive'])->name('purchases.receive');
        Route::post('/stock/{product}/adjust', [Manage::class, 'adjust'])->name('stock.adjust');
        Route::post('/payments/{payment}/void', [Tx::class, 'void'])->name('payments.void');
        Route::get('/reports', [Manage::class, 'reports'])->name('reports');
        Route::get('/settings', [Manage::class, 'settings'])->name('settings');
        Route::put('/settings', [Manage::class, 'saveSettings']);
    });
});
