<?php

namespace App\Http\Controllers;

use App\Models\PdfContent;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Illuminate\Support\Str;

class PdfContentController extends Controller
{
    public function processPdfContent()
    {
        // Get all PDFs from S3 that haven't been processed yet
        $files = Storage::disk('s3')->files();
        $pdfs = array_filter($files, function($file) {
            return Str::endsWith($file, '.pdf');
        });

        foreach ($pdfs as $pdfPath) {
            // Check if file has already been processed
            if (PdfContent::where('s3_path', $pdfPath)->exists()) {
                continue;
            }

            try {
                // Get file from S3
                $fileContent = Storage::disk('s3')->get($pdfPath);
                
                // Parse PDF content
                $parser = new Parser();
                $pdf = $parser->parseContent($fileContent);
                
                // Extract text from all pages
                $text = '';
                $pages = $pdf->getPages();
                foreach ($pages as $page) {
                    $text .= $page->getText() . "\n";
                }

                // Get file metadata
                $metadata = [
                    'author' => $pdf->getDetails()['Author'] ?? null,
                    'creator' => $pdf->getDetails()['Creator'] ?? null,
                    'created_at' => $pdf->getDetails()['CreationDate'] ?? null,
                    'modified_at' => $pdf->getDetails()['ModDate'] ?? null,
                ];

                // Create new PdfContent record
                PdfContent::create([
                    'file_name' => basename($pdfPath),
                    'original_name' => basename($pdfPath),
                    's3_path' => $pdfPath,
                    'mime_type' => 'application/pdf',
                    'file_size' => Storage::disk('s3')->size($pdfPath),
                    'content' => $text,
                    'metadata' => $metadata,
                    'page_count' => count($pages),
                    'status' => 'completed',
                    'processed_at' => now()
                ]);

            } catch (\Exception $e) {
                // Log error and continue with next file
                \Log::error("Error processing PDF {$pdfPath}: " . $e->getMessage());
                
                // Create record with error status
                PdfContent::create([
                    'file_name' => basename($pdfPath),
                    'original_name' => basename($pdfPath),
                    's3_path' => $pdfPath,
                    'mime_type' => 'application/pdf',
                    'file_size' => Storage::disk('s3')->size($pdfPath),
                    'status' => 'failed',
                    'metadata' => ['error' => $e->getMessage()],
                    'processed_at' => now()
                ]);
            }
        }

        return response()->json(['message' => 'PDF processing completed']);
    }
}
