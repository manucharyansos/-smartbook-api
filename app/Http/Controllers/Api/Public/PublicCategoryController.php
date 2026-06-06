<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\BusinessCategory;
use App\Support\BusinessVertical;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Schema;

class PublicCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vertical' => ['nullable', 'string', Rule::in(BusinessVertical::values())],
            'locale' => ['nullable', 'string', 'in:hy,ru,en'],
        ]);

        $locale = $data['locale'] ?? $request->getPreferredLanguage(['hy', 'ru', 'en']) ?? 'hy';

        if (!Schema::hasTable('business_categories')) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'locale' => $locale,
                    'vertical' => $data['vertical'] ?? null,
                    'reason' => 'business_categories_missing',
                ],
            ]);
        }

        $categories = BusinessCategory::query()
            ->when(Schema::hasColumn('business_categories', 'is_active'), fn ($q) => $q->where('is_active', true))
            ->forVertical($data['vertical'] ?? null)
            ->orderBy('vertical')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (BusinessCategory $category) => [
                'id' => $category->id,
                'vertical' => $category->vertical,
                'slug' => $category->slug,
                'name' => $category->getLocalizedName($locale),
                'description' => $category->getLocalizedDescription($locale),
                'name_hy' => $category->name_hy,
                'name_ru' => $category->name_ru,
                'name_en' => $category->name_en,
                'icon' => $category->icon,
                'sort_order' => (int) $category->sort_order,
            ])
            ->values();

        return response()->json([
            'data' => $categories,
            'meta' => [
                'locale' => $locale,
                'vertical' => $data['vertical'] ?? null,
            ],
        ]);
    }
}
