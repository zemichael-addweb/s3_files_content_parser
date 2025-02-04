<?php

use App\Http\Controllers\Api\ContentExtractionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PdfController;
use App\Http\Controllers\PdfContentController;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\MediaAnalysisController;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::group(['prefix' => 'file-content'], function () {
    Route::get('/get-files', [ContentExtractionController::class, 'getFiles'])
        ->name('api.file-content.get-files');

    Route::post('/process-pdf', [ContentExtractionController::class, 'processPdf'])
        ->name('api.file-content.process-pdf');

    Route::post('/process-txt', [ContentExtractionController::class, 'processTxt'])
        ->name('api.file-content.process-txt');

    Route::post('/process-doc', [ContentExtractionController::class, 'processDoc'])
        ->name('api.file-content.process-doc');

    Route::post('/process-media', [ContentExtractionController::class, 'processMedia'])
        ->name('api.file-content.process-media');
});

Route::post('/process-media-locally', [ContentExtractionController::class, 'processMediaLocally'])
    ->name('api.media.process-media-locally');

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


