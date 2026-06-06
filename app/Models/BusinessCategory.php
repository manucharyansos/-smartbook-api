<?php

namespace App\Models;

use App\Support\BusinessVertical;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BusinessCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'vertical',
        'slug',
        'name_hy',
        'name_ru',
        'name_en',
        'description_hy',
        'description_ru',
        'description_en',
        'icon',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function businesses()
    {
        return $this->hasMany(Business::class);
    }

    public function getLocalizedName(string $locale = 'hy'): string
    {
        $locale = $this->normalizeLocale($locale);
        return (string) ($this->{"name_{$locale}"} ?: $this->name_hy ?: $this->name_en ?: $this->slug);
    }

    public function getLocalizedDescription(string $locale = 'hy'): ?string
    {
        $locale = $this->normalizeLocale($locale);
        return $this->{"description_{$locale}"} ?: $this->description_hy ?: $this->description_en;
    }

    public function scopeForVertical($query, ?string $vertical)
    {
        if (!$vertical) {
            return $query;
        }

        return $query->where('vertical', BusinessVertical::normalize($vertical));
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = strtolower(substr(trim($locale), 0, 2));
        return in_array($locale, ['hy', 'ru', 'en'], true) ? $locale : 'hy';
    }
}
