<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostDraft extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'url',
        'content',
        'media_ids',
    ];

    protected function casts(): array
    {
        return [
            'media_ids' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
