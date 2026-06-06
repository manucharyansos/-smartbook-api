<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\BusinessVertical;
use Illuminate\Http\Request;

class BusinessTypeController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $business = $user?->business?->loadMissing('category');

        if (!$business) {
            return response()->json([
                'message' => 'Business context required.',
                'code' => 'business_required',
            ], 403);
        }

        $vertical = $business->normalizedVertical();
        $meta = $vertical === BusinessVertical::HEALTHCARE
            ? ['label' => 'Healthcare', 'label_hy' => 'Բժշկական', 'icon' => 'stethoscope']
            : ['label' => 'Services', 'label_hy' => 'Ծառայություններ', 'icon' => 'grid'];

        return response()->json([
            'data' => [
                'key' => $business->business_type ?? BusinessVertical::canonicalBusinessType($vertical),
                'vertical' => $vertical,
                'label' => $meta['label'],
                'label_hy' => $meta['label_hy'],
                'icon' => $business->category?->icon ?: $meta['icon'],
                'category' => $business->category ? [
                    'id' => $business->category->id,
                    'slug' => $business->category->slug,
                    'name_hy' => $business->category->name_hy,
                    'name_ru' => $business->category->name_ru,
                    'name_en' => $business->category->name_en,
                    'icon' => $business->category->icon,
                ] : null,
                'custom_category_name' => $business->custom_category_name,
            ],
        ]);
    }
}
