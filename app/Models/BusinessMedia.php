<?php

namespace App\Models;

use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Model;

class BusinessMedia extends Model
{
    protected $table = 'business_media';

    protected $fillable = ['business_id', 'path', 'mime_type', 'kind', 'caption', 'sort_order'];

    protected $hidden = ['path'];

    protected $appends = ['url'];

    protected $casts = ['sort_order' => 'integer'];

    public function getUrlAttribute(): ?string
    {
        return MediaUrl::absolute($this->path);
    }
}
