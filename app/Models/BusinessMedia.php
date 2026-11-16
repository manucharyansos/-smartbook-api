<?php

namespace App\Models;

use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Model;

class BusinessMedia extends Model
{
    protected $table = 'business_media';

    public const COLLECTION_GALLERY = 'gallery';

    public const COLLECTION_PORTFOLIO = 'portfolio';

    protected $fillable = ['business_id', 'path', 'mime_type', 'kind', 'collection', 'staff_id', 'service_id', 'caption', 'sort_order'];

    protected $hidden = ['path'];

    protected $appends = ['url'];

    protected $casts = ['sort_order' => 'integer', 'staff_id' => 'integer', 'service_id' => 'integer'];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function staff()
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function getUrlAttribute(): ?string
    {
        return MediaUrl::absolute($this->path);
    }
}
