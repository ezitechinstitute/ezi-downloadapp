<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Download extends Model
{
    protected $fillable = [
        'url',
        'platform',
        'quality',
        'title',
        'thumbnail_url',
        'status',
        'progress',
        'video_url',
        'error_message',
    ];

    protected $casts = [
        'progress' => 'integer',
    ];
}