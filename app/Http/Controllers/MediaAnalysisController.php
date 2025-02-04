<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class MediaAnalysisController extends Controller
{
    public function analyze(Request $request)
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
}
