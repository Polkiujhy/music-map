<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['quota_day', 'admitted_count', 'daily_limit'])]
class YouTubeWriteQuotaState extends Model
{
    public const GLOBAL_KEY = 'global';

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'singleton_key';

    protected $keyType = 'string';

    protected $table = 'youtube_write_quota_states';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quota_day' => 'immutable_date',
            'admitted_count' => 'integer',
            'daily_limit' => 'integer',
        ];
    }
}
