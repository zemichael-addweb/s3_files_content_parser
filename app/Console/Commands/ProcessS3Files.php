<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\FileContent;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Illuminate\Support\Str;

class ProcessS3Files extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'process:s3files';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->log('Command started. Getting files from S3...', 'info');

        // get all the files to start processing
        $files = $this->getFiles();

        $this->log('Files retrieved: ' . count($files), 'info');

        foreach ($files as $file) {
            $existing = FileContent::where('s3_path', $file)->first();
            if ($existing) {
                $this->log('File already processed: ' . basename($file), 'info');
                continue;
            }

            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $this->log('Processing ' . $extension . ' file: ' . $file, 'info');
            switch ($extension) {
                case in_array($extension, ['pdf']):
                    $this->processPdf($file);
                    break;
                case in_array($extension, ['txt']):
                    $this->processTxt($file);
                    break;
                case in_array($extension, ['doc', 'docx']):
                    $this->processDoc($file);
                    break;
                case in_array($extension, ['mp3', 'mp4', 'wav', 'avi', 'mov']):
                    $this->processMedia($file);
                    break;
                default:
                    $this->log('Unknown file type: ' . $file, 'error');
            }
        }
    }

    public function getFiles()
    {
        try {
            $s3Files = Storage::disk('s3')->allFiles('Sections');

            $allowedExtensions = ['pdf', 'txt', 'doc', 'docx', 'mp3', 'mp4', 'wav', 'avi', 'mov'];
            $filteredFiles = array_filter($s3Files, function ($file) use ($allowedExtensions) {
                return in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $allowedExtensions);
            });

            return $filteredFiles;
        } catch (\Exception $e) {
            $this->log('Error retrieving files: ' . $e->getMessage(), 'error');
            return [];
        }
    }

    public function processPdf($file)
    {
        try {
            $parser = new Parser();
            $tempFile = $this->streamS3FileToTemporaryFolder($file);

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
                'file_name' => basename($file),
                'original_name' => basename($file),
                's3_path' => $file,
                'mime_type' => 'application/pdf',
                'file_size' => Storage::disk('s3')->size($file),
                'content' => $text,
                'metadata' => $metadata,
                'page_count' => count($pdf->getPages()),
                'status' => 'completed',
                'processed_at' => now()
            ]);

            unset($pdf);

            $this->log('Successfully processed PDF: ' . basename($file), 'info');
        } catch (\Exception $e) {
            $this->log('Error processing PDF ' . basename($file) . ': ' . $e->getMessage(), 'error');
        }
    }

    private function processTxt($file)
    {
        try {
            // Read the content directly from S3
            $content = Storage::disk('s3')->get($file);

            FileContent::create([
                'file_name' => basename($file),
                'original_name' => basename($file),
                's3_path' => $file,
                'mime_type' => 'text/plain',
                'file_size' => Storage::disk('s3')->size($file),
                'content' => $content,
                'metadata' => [
                    'created_at' => Storage::disk('s3')->lastModified($file),
                ],
                'status' => 'completed',
                'processed_at' => now()
            ]);

            $this->log('Successfully processed text file: ' . basename($file), 'info');
        } catch (\Exception $e) {
            FileContent::create([
                'file_name' => basename($file),
                'original_name' => basename($file),
                's3_path' => $file,
                'mime_type' => 'text/plain',
                'file_size' => Storage::disk('s3')->size($file),
                'status' => 'failed',
                'metadata' => ['error' => $e->getMessage()],
                'processed_at' => now()
            ]);

            $this->log('Error processing text file ' . basename($file) . ': ' . $e->getMessage(), 'error');
        } catch (\Exception $e) {
            $this->log('Error processing text file ' . basename($file) . ': ' . $e->getMessage(), 'error');
        }
    }

    private function processDoc($file)
    {
        try {
            // Create temp file
            $tempFile = $this->streamS3FileToTemporaryFolder($file);

            // Load the document
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

            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $mimeType = $extension === 'doc' ? 'application/msword' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

            FileContent::create([
                'file_name' => basename($file),
                'original_name' => basename($file),
                's3_path' => $file,
                'mime_type' => $mimeType,
                'file_size' => Storage::disk('s3')->size($file),
                'content' => $text,
                'metadata' => [
                    'created_at' => Storage::disk('s3')->lastModified($file),
                ],
                'status' => 'completed',
                'processed_at' => now()
            ]);

            $this->log('Successfully processed document file: ' . basename($file), 'info');
        } catch (\Exception $e) {
            $this->log('Error processing document file ' . basename($file) . ': ' . $e->getMessage(), 'error');
        }
    }
    private function processMedia($file)
    {
        try {
            // Create temp directory if it doesn't exist
            $tempFile = $this->streamS3FileToTemporaryFolder($file);

            // Locate FFprobe
            $ffprobePath = trim(shell_exec('which ffprobe')) ?: '/usr/local/bin/ffprobe';
            if (!file_exists($ffprobePath)) {
                $ffprobePath = '/opt/homebrew/bin/ffprobe';
            }

            $this->log('Using FFprobe: ' . $ffprobePath);

            $process = new Process([
                $ffprobePath,
                '-v',
                'error',
                '-print_format',
                'json',
                '-show_format',
                '-show_streams',
                $tempFile
            ]);

            $process->run();

            if (!$process->isSuccessful()) {
                throw new \Exception('FFprobe failed: ' . $process->getErrorOutput());
            }

            $output = $process->getOutput();
            $this->log('Raw FFprobe output: ' . $output);
            $metadata = json_decode($output, true);

            // Format the metadata for better display
            $formattedMetadata = [
                'Audio Information' => $this->extractAudioInfo($metadata),
                'Video Information' => $this->extractVideoInfo($metadata),
                'General Information' => $this->extractFormatInfo($metadata),
                'Lyrics' => $this->extractLyrics($metadata)
            ];

            $mimeType =  mime_content_type($tempFile);

            FileContent::create([
                'file_name' => basename($file),
                'original_name' => basename($file),
                's3_path' => $file,
                'mime_type' => $mimeType,
                'file_size' => $metadata['format']['size'] ?? 0,
                'metadata' => $formattedMetadata,
                'status' => 'completed',
                'processed_at' => now()
            ]);

            unlink($tempFile);

            return $formattedMetadata;
        } catch (\Exception $e) {
            $this->log('Process media error: ' . $e->getMessage(), 'error');
            throw $e;
        }
    }

    private function extractAudioInfo($metadata)
    {
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
                'codec_long_name',
                'sample_rate',
                'channels',
                'channel_layout',
                'duration',
                'bit_rate'
            ]))
        ] : [];
    }

    private function extractVideoInfo($metadata)
    {
        $videoStream = collect($metadata['streams'])
            ->firstWhere('codec_type', 'video');

        return $videoStream ? [
            'Codec' => $videoStream['codec_long_name'] ?? 'N/A',
            'Resolution' => ($videoStream['width'] ?? 'N/A') . 'x' . ($videoStream['height'] ?? 'N/A'),
            'Pixel Format' => $videoStream['pix_fmt'] ?? 'N/A',
            'Duration' => ($videoStream['duration'] ?? 'N/A') . ' seconds',
            'Other Details' => array_diff_key($videoStream, array_flip([
                'codec_long_name',
                'width',
                'height',
                'pix_fmt',
                'duration'
            ]))
        ] : [];
    }

    private function extractFormatInfo($metadata)
    {
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
                'format_long_name',
                'duration',
                'size',
                'bit_rate',
                'tags'
            ]))
        ];
    }

    private function extractLyrics($metadata)
    {
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

    private function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    private function extractDetailedLyrics($filePath)
    {
        $ffmpegPath = trim(shell_exec('which ffmpeg'));

        if (empty($ffmpegPath)) {
            $ffmpegPath = '/usr/local/bin/ffmpeg';
        }

        $process = new Process([
            $ffmpegPath,
            '-i',
            $filePath,
            '-f',
            'ffmetadata',
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

    private function log($message, $type = 'info')
    {
        if ($type === 'info') {
            Log::info($message);
            $this->info($message);
        } else if ($type === 'error') {
            Log::error($message);
            $this->error($message);
        } else if ($type === 'warning') {
            Log::warning($message);
            $this->warn($message);
        }
    }
}
