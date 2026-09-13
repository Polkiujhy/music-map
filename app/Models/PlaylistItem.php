<?php

namespace App\Models;

use Database\Factories\PlaylistItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'position',
    'occurrence_id',
    'catalog_id',
    'catalog_uri',
    'title',
    'creators',
    'album',
    'duration_milliseconds',
    'isrc',
    'is_available',
])]
class PlaylistItem extends Model
{
    /** @use HasFactory<PlaylistItemFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return BelongsTo<Playlist, $this>
     */
    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'creators' => 'array',
            'is_available' => 'boolean',
        ];
    }
}
