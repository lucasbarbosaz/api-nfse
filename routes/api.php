<?php

use Illuminate\Support\Facades\Route;


Route::prefix('nfse')->group(function () {
    Route::post('listar', [\App\Http\Controllers\NfseController::class, 'listar']);
    Route::post('xml/{chave}', [\App\Http\Controllers\NfseController::class, 'xml']);
    Route::post('download/{chave}', [\App\Http\Controllers\NfseController::class, 'download']);
    Route::post('manifestar/{chave}', [\App\Http\Controllers\NfseController::class, 'manifestar']);
    Route::post('contas/{chave}', [\App\Http\Controllers\NfseController::class, 'contas']);
});
