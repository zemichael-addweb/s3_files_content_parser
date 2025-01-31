<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PdfContent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Illuminate\Support\Str;

class PdfController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    public function getPdfFiles() {
        try {
            $s3Files = Storage::disk('s3')->allFiles('Sections');
                
            $pdfFiles = array_filter($s3Files, function ($file) {
                return pathinfo($file, PATHINFO_EXTENSION) === 'pdf';
            });

            return response()->json([
                'success' => true,
                'files' => $pdfFiles
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving PDF files: ' . $e->getMessage()
            ], 500);
        }
    }

    public function processPdf(Request $request)
    {
        try {
            $results = [];
            
            // Validate the request
            $request->validate([
                's3_file_path' => 'required|string'
            ]);

            $filePath = $request->s3_file_path;  // Changed from s3_file_path to file to match frontend

            // Check if file was already processed
            $existing = PdfContent::where('s3_path', $filePath)->first();
            if ($existing) {
                return response()->json([
                    'success' => true,
                    'results' => [[
                        'file' => basename($filePath),
                        'status' => 'already processed'
                    ]]
                ]);
            }

            try {
                $parser = new Parser();
                $stream = Storage::disk('s3')->readStream($filePath);
                $tempFile = tempnam(sys_get_temp_dir(), 'pdf_');
                file_put_contents($tempFile, $stream);
                
                $pdf = $parser->parseFile($tempFile);
                
                $text = '';
                foreach ($pdf->getPages() as $page) {
                    $text .= $page->getText() . "\n";
                    unset($page);
                }
                
                unlink($tempFile);
                
                $metadata = [
                    'author' => $pdf->getDetails()['Author'] ?? null,
                    'creator' => $pdf->getDetails()['Creator'] ?? null,
                    'created_at' => $pdf->getDetails()['CreationDate'] ?? null,
                    'modified_at' => $pdf->getDetails()['ModDate'] ?? null,
                ];

                PdfContent::create([
                    'file_name' => basename($filePath),
                    'original_name' => basename($filePath),
                    's3_path' => $filePath,
                    'mime_type' => 'application/pdf',
                    'file_size' => Storage::disk('s3')->size($filePath),
                    'content' => $text,
                    'metadata' => $metadata,
                    'page_count' => count($pdf->getPages()),
                    'status' => 'completed',
                    'processed_at' => now()
                ]);

                unset($pdf);
                
                $results[] = [
                    'file' => basename($filePath),
                    'status' => 'success'
                ];

                return response()->json([
                    'success' => true,
                    'total_files' => 1,
                    'results' => $results
                ]);
            } catch (\Exception $e) {
                PdfContent::create([
                    'file_name' => basename($filePath),
                    'original_name' => basename($filePath),
                    's3_path' => $filePath,
                    'mime_type' => 'application/pdf',
                    'file_size' => Storage::disk('s3')->size($filePath),
                    'status' => 'failed',
                    'metadata' => ['error' => $e->getMessage()],
                    'processed_at' => now()
                ]);

                $results[] = [
                    'file' => basename($filePath),
                    'status' => 'error',
                    'error' => $e->getMessage()
                ];

                return response()->json([
                    'success' => false,
                    'total_files' => 1,
                    'results' => $results
                ], 500);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
