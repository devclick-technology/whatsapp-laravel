<?php

use DevClick\WhatsApp\Http\NoStore;
use DevClick\WhatsApp\Http\WhatsAppController;
use Illuminate\Support\Facades\Route;

Route::prefix(config('whatsapp.route_prefix'))->name(config('whatsapp.route_name_prefix'))
    ->middleware([NoStore::class, ...config('whatsapp.middleware')])->group(function (): void {
        Route::get('/status', [WhatsAppController::class, 'status'])->middleware('throttle:devclick-whatsapp-read')->name('status');
        Route::get('/qr', [WhatsAppController::class, 'qr'])->middleware('throttle:devclick-whatsapp-read')->name('qr');
        Route::post('/connect', [WhatsAppController::class, 'connect'])->middleware('throttle:devclick-whatsapp-connect')->name('connect');
        Route::post('/disconnect', [WhatsAppController::class, 'disconnect'])->middleware('throttle:devclick-whatsapp-connect')->name('disconnect');
        if (config('whatsapp.send_route_enabled')) {
            Route::post('/send-message', [WhatsAppController::class, 'sendText'])->middleware('throttle:devclick-whatsapp-send')->name('send-message');
        }
    });
