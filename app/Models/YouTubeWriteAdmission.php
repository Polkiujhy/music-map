<?php

namespace App\Models;

use App\Integrations\YouTubeWriteAdmission\YouTubeWriteOperationType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['operation_type', 'operation_id', 'quota_day', 'admitted_at'])]
class YouTubeWriteAdmission extends Model
{
    public $timestamps = false;

    protected $table = 'youtube_write_admissions';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'operation_type' => YouTubeWriteOperationType::class,
            'quota_day' => 'immutable_date',
            'admitted_at' => 'immutable_datetime',
        ];
    }
}
