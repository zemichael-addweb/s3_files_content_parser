<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PdfContent extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'file_name',
        'original_name', 
        's3_path',
        'mime_type',
        'file_size',
        'content',
        'metadata',
        'page_count',
        'status',
        'processed_at'
    ];

    protected $casts = [
        'metadata' => 'array',
        'processed_at' => 'datetime',
        'file_size' => 'integer',
        'page_count' => 'integer'
    ];

    protected $dates = [
        'processed_at'
    ];
}
