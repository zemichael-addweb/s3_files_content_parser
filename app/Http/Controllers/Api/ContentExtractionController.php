<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FileContent;
use App\Models\PdfContent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use FFMpeg\FFProbe;

class ContentExtractionController extends Controller
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

    public function getFiles() {
        try {
            $s3Files = Storage::disk('s3')->allFiles('Sections');
                
            $allowedExtensions = ['pdf', 'txt', 'doc', 'docx', 'mp3', 'mp4', 'wav', 'avi', 'mov'];
            $filteredFiles = array_filter($s3Files, function ($file) use ($allowedExtensions) {
                return in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $allowedExtensions);
            });

            return response()->json([
                'success' => true,
                'files' => $filteredFiles
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving files: ' . $e->getMessage()
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
            $existing = FileContent::where('s3_path', $filePath)->first();
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
                $tempFile = $this->streamS3FileToTemporaryFolder($filePath);
                
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

                FileContent::create([
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
                FileContent::create([
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

    public function processTxt(Request $request)
    {
        try {
            $request->validate([
                's3_file_path' => 'required|string'
            ]);

            $filePath = $request->s3_file_path;

            // Check if file was already processed
            $existing = FileContent::where('s3_path', $filePath)->first();
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
                // Read the content directly from S3
                $content = Storage::disk('s3')->get($filePath);

                FileContent::create([
                    'file_name' => basename($filePath),
                    'original_name' => basename($filePath),
                    's3_path' => $filePath,
                    'mime_type' => 'text/plain',
                    'file_size' => Storage::disk('s3')->size($filePath),
                    'content' => $content,
                    'metadata' => [
                        'created_at' => Storage::disk('s3')->lastModified($filePath),
                    ],
                    'status' => 'completed',
                    'processed_at' => now()
                ]);

                return response()->json([
                    'success' => true,
                    'total_files' => 1,
                    'results' => [[
                        'file' => basename($filePath),
                        'status' => 'success'
                    ]]
                ]);

            } catch (\Exception $e) {
                FileContent::create([
                    'file_name' => basename($filePath),
                    'original_name' => basename($filePath),
                    's3_path' => $filePath,
                    'mime_type' => 'text/plain',
                    'file_size' => Storage::disk('s3')->size($filePath),
                    'status' => 'failed',
                    'metadata' => ['error' => $e->getMessage()],
                    'processed_at' => now()
                ]);

                return response()->json([
                    'success' => false,
                    'total_files' => 1,
                    'results' => [[
                        'file' => basename($filePath),
                        'status' => 'error',
                        'error' => $e->getMessage()
                    ]]
                ], 500);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function processDoc(Request $request)
    {
        try {
            $request->validate([
                's3_file_path' => 'required|string'
            ]);

            $filePath = $request->s3_file_path;

            // Check if file was already processed
            $existing = FileContent::where('s3_path', $filePath)->first();
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
                // Create temp file
                $tempFile = $this->streamS3FileToTemporaryFolder($filePath);
                $phpWord = \PhpOffice\PhpWord\IOFactory::load($tempFile);
                
                // Extract text from the document
                $text = '';
                foreach ($phpWord->getSections() as $section) {
                    foreach ($section->getElements() as $element) {
                        if ($element instanceof \PhpOffice\PhpWord\Element\Text) {
                            $text .= $element->getText() . "\n";
                        } elseif ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
                            foreach ($element->getElements() as $textRunElement) {
                                if ($textRunElement instanceof \PhpOffice\PhpWord\Element\Text) {
                                    $text .= $textRunElement->getText() . " ";
                                }
                            }
                            $text .= "\n";
                        }
                    }
                }

                // Clean up temp file
                unlink($tempFile);

                $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                $mimeType = $extension === 'doc' ? 'application/msword' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

                FileContent::create([
                    'file_name' => basename($filePath),
                    'original_name' => basename($filePath),
                    's3_path' => $filePath,
                    'mime_type' => $mimeType,
                    'file_size' => Storage::disk('s3')->size($filePath),
                    'content' => $text,
                    'metadata' => [
                        'created_at' => Storage::disk('s3')->lastModified($filePath),
                    ],
                    'status' => 'completed',
                    'processed_at' => now()
                ]);

                return response()->json([
                    'success' => true,
                    'total_files' => 1,
                    'results' => [[
                        'file' => basename($filePath),
                        'status' => 'success'
                    ]]
                ]);

            } catch (\Exception $e) {
                FileContent::create([
                    'file_name' => basename($filePath),
                    'original_name' => basename($filePath),
                    's3_path' => $filePath,
                    'mime_type' => $extension === 'doc' ? 'application/msword' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'file_size' => Storage::disk('s3')->size($filePath),
                    'status' => 'failed',
                    'metadata' => ['error' => $e->getMessage()],
                    'processed_at' => now()
                ]);

                return response()->json([
                    'success' => false,
                    'total_files' => 1,
                    'results' => [[
                        'file' => basename($filePath),
                        'status' => 'error',
                        'error' => $e->getMessage()
                    ]]
                ], 500);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function processMedia(Request $request)
    {
        try {
            $request->validate([
                's3_file_path' => 'required|string'
            ]);

            $filePath = $request->s3_file_path;

            // Check if file was already processed
            $existing = FileContent::where('s3_path', $filePath)->first();
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
                // Create temp directory if it doesn't exist
                $tempDir = storage_path('app/temp');
                if (!file_exists($tempDir)) {
                    mkdir($tempDir, 0777, true);
                }

                $tempFile = $this->streamS3FileToTemporaryFolder($filePath);

                // Initialize getID3
                $getID3 = new \getID3();
                $fileInfo = $getID3->analyze($tempFile);

                // Extract metadata
                $metadata = [
                    'duration' => $fileInfo['playtime_seconds'] ?? null,
                    'duration_formatted' => $fileInfo['playtime_string'] ?? null,
                    'bitrate' => $fileInfo['bitrate'] ?? null,
                    'sample_rate' => $fileInfo['audio']['sample_rate'] ?? null,
                    'channels' => $fileInfo['audio']['channels'] ?? null,
                    'codec' => $fileInfo['audio']['dataformat'] ?? $fileInfo['video']['dataformat'] ?? null,
                    'width' => $fileInfo['video']['resolution_x'] ?? null,
                    'height' => $fileInfo['video']['resolution_y'] ?? null,
                    'frame_rate' => $fileInfo['video']['frame_rate'] ?? null,
                    'tags' => $fileInfo['tags'] ?? null,
                ];

                // Determine mime type
                $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                $mimeType = mime_content_type($tempFile);

                // Clean up temp file
                unlink($tempFile);

                FileContent::create([
                    'file_name' => basename($filePath),
                    'original_name' => basename($filePath),
                    's3_path' => $filePath,
                    'mime_type' => $mimeType,
                    'file_size' => Storage::disk('s3')->size($filePath),
                    'metadata' => $metadata,
                    'status' => 'completed',
                    'processed_at' => now()
                ]);

                return response()->json([
                    'success' => true,
                    'total_files' => 1,
                    'results' => [[
                        'file' => basename($filePath),
                        'status' => 'success'
                    ]]
                ]);

            } catch (\Exception $e) {
                if (isset($tempFile) && file_exists($tempFile)) {
                    unlink($tempFile);
                }

                FileContent::create([
                    'file_name' => basename($filePath),
                    'original_name' => basename($filePath),
                    's3_path' => $filePath,
                    'mime_type' => mime_content_type($tempFile),
                    'file_size' => Storage::disk('s3')->size($filePath),
                    'status' => 'failed',
                    'metadata' => ['error' => $e->getMessage()],
                    'processed_at' => now()
                ]);

                return response()->json([
                    'success' => false,
                    'total_files' => 1,
                    'results' => [[
                        'file' => basename($filePath),
                        'status' => 'error',
                        'error' => $e->getMessage()
                    ]]
                ], 500);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function processMediaLocally(Request $request)
    {
        try {
            $file = $request->file('file');
            if (!$file) {
                return response()->json(['success' => false, 'error' => 'No file uploaded'], 400);
            }

            // Store file temporarily
            $tempPath = storage_path('app/temp/' . $file->getClientOriginalName());
            $file->move(storage_path('app/temp/'), $file->getClientOriginalName());

            Log::info('Saved file at: ' . $tempPath);
            Log::info('File exists: ' . (file_exists($tempPath) ? 'yes' : 'no'));

            // Locate FFprobe
            $ffprobePath = trim(shell_exec('which ffprobe')) ?: '/usr/local/bin/ffprobe';
            if (!file_exists($ffprobePath)) {
                $ffprobePath = '/opt/homebrew/bin/ffprobe';
            }

            Log::info('Using FFprobe: ' . $ffprobePath);

            $process = new Process([
                $ffprobePath,
                '-v',
                'error',
                '-print_format',
                'json',
                '-show_format',
                '-show_streams',
                $tempPath
            ]);

            $process->run();

            if (!$process->isSuccessful()) {
                throw new \Exception('FFprobe failed: ' . $process->getErrorOutput());
            }

            $output = $process->getOutput();
            Log::info('Raw FFprobe output: ' . $output);
            $metadata = json_decode($output, true);
            $detailedLyrics = $this->extractDetailedLyrics($tempPath);

            // Format the metadata for better display
            $formattedMetadata = [
                'Audio Information' => $this->extractAudioInfo($metadata),
                'Video Information' => $this->extractVideoInfo($metadata),
                'General Information' => $this->extractFormatInfo($metadata),
                'Detailed Lyrics' => $detailedLyrics
            ];

            return response()->json([
                'success' => true, 
                'metadata' => $formattedMetadata,
                'raw_metadata' => $metadata // Keep the raw data if needed
            ]);
        } catch (\Exception $e) {
            Log::error('Analyze error: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function extractAudioInfo($metadata) {
        $audioStream = collect($metadata['streams'])
            ->firstWhere('codec_type', 'audio');
        
        return $audioStream ? [
            'Codec' => $audioStream['codec_long_name'] ?? 'N/A',
            'Sample Rate' => ($audioStream['sample_rate'] ?? 'N/A') . ' Hz',
            'Channels' => $audioStream['channels'] ?? 'N/A',
            'Channel Layout' => $audioStream['channel_layout'] ?? 'N/A',
            'Duration' => ($audioStream['duration'] ?? 'N/A') . ' seconds',
            'Bit Rate' => ($audioStream['bit_rate'] ?? 'N/A') . ' bps',
            'Other Details' => array_diff_key($audioStream, array_flip([
                'codec_long_name', 'sample_rate', 'channels', 
                'channel_layout', 'duration', 'bit_rate'
            ]))
        ] : [];
    }

    private function extractVideoInfo($metadata) {
        $videoStream = collect($metadata['streams'])
            ->firstWhere('codec_type', 'video');
        
        return $videoStream ? [
            'Codec' => $videoStream['codec_long_name'] ?? 'N/A',
            'Resolution' => ($videoStream['width'] ?? 'N/A') . 'x' . ($videoStream['height'] ?? 'N/A'),
            'Pixel Format' => $videoStream['pix_fmt'] ?? 'N/A',
            'Duration' => ($videoStream['duration'] ?? 'N/A') . ' seconds',
            'Other Details' => array_diff_key($videoStream, array_flip([
                'codec_long_name', 'width', 'height', 
                'pix_fmt', 'duration'
            ]))
        ] : [];
    }

    private function extractFormatInfo($metadata) {
        $format = $metadata['format'] ?? [];
        $lyrics = $this->extractLyrics($metadata);
        
        return [
            'Format' => $format['format_long_name'] ?? 'N/A',
            'Duration' => ($format['duration'] ?? 'N/A') . ' seconds',
            'Size' => $this->formatBytes($format['size'] ?? 0),
            'Bit Rate' => ($format['bit_rate'] ?? 'N/A') . ' bps',
            'Tags' => $format['tags'] ?? [],
            'Lyrics' => $lyrics,
            'Other Details' => array_diff_key($format, array_flip([
                'format_long_name', 'duration', 'size', 
                'bit_rate', 'tags'
            ]))
        ];
    }

    private function extractLyrics($metadata) {
        $format = $metadata['format'] ?? [];
        $tags = $format['tags'] ?? [];
        
        // Check various common lyrics tag formats
        $possibleLyricsTags = [
            'lyrics',      // Standard ID3v2 lyrics
            'LYRICS',      // Alternative capitalization
            'USLT',        // Unsynchronized lyrics/text
            'SYLT',        // Synchronized lyrics/text
            'TEXT',        // Text transcription
            'unsyncedlyrics', // Another common variant
            'TXXX:LYRICS', // Custom ID3v2 frame
        ];

        foreach ($possibleLyricsTags as $tag) {
            if (!empty($tags[$tag])) {
                return $tags[$tag];
            }
        }

        // If no lyrics found in common tags, check for any tag containing 'lyric'
        foreach ($tags as $key => $value) {
            if (stripos($key, 'lyric') !== false) {
                return $value;
            }
        }

        return 'No lyrics found';
    }

    private function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    private function extractDetailedLyrics($filePath) {
        $ffmpegPath = trim(shell_exec('which ffmpeg'));
        
        if (empty($ffmpegPath)) {
            $ffmpegPath = '/usr/local/bin/ffmpeg';
        }

        $process = new Process([
            $ffmpegPath,
            '-i', $filePath,
            '-f', 'ffmetadata',
            'pipe:1'
        ]);

        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $output = $process->getOutput();
        
        // Parse the FFmpeg metadata output for lyrics
        $lines = explode("\n", $output);
        $lyrics = [];
        $inLyricsBlock = false;

        foreach ($lines as $line) {
            if (strpos($line, '[LYRICS]') === 0) {
                $inLyricsBlock = true;
                continue;
            }
            if ($inLyricsBlock && strlen($line) > 0) {
                $lyrics[] = $line;
            }
        }

        return !empty($lyrics) ? implode("\n", $lyrics) : null;
    }

    private function streamS3FileToTemporaryFolder($filePath){
        $tempDir = storage_path('app/temp');
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        $tempFile = $tempDir . '/' . str::random(40);
        $stream = Storage::disk('s3')->readStream($filePath);
        $destination = fopen($tempFile, 'wb');
        stream_copy_to_stream($stream, $destination);
        fclose($destination);
        fclose($stream);

        return $tempFile;
    }
}
