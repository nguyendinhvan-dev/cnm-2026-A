<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['title', 'content', 'user_id', 'thumbnail'])]
class Post extends Model
{
    /**
     * Get full URL for thumbnail (fallback to placeholder)
     */
    public function thumbnailUrl()
    {
        return $this->thumbnail
            ? asset('storage/' . $this->thumbnail)
            : asset('images/default-thumb.png');
    }

{
    use SoftDeletes;

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
