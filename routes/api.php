<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PdfController;
use App\Http\Controllers\PdfContentController;
use Illuminate\Support\Facades\Storage;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::group(['prefix' => 'pdf-content'], function () {
    Route::get('/get-pdf-files', [PdfController::class, 'getPdfFiles'])
        ->name('api.pdf-content.get-pdf-files');

    Route::post('/process-pdf', [PdfController::class, 'processPdf'])
        ->name('api.pdf-content.process-pdf');
});

// test by reading all files 
Route::get('test-s3', function() {
    try {
        $files = Storage::disk('s3')->files();
        return response()->json([
            'success' => true,
            'message' => 'Successfully retrieved files from S3',
            'files' => $files
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error accessing S3: ' . $e->getMessage()
        ], 500);
    }
});
