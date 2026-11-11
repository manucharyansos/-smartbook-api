<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A previous public link of a business; it still opens that business. */
class BusinessSlugAlias extends Model
{
    protected $fillable = ['business_id', 'slug'];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }
}
