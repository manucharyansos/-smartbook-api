<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MediaUploadController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            'folder' => ['nullable', 'string', 'max:60'],
        ]);

        $folder = preg_replace('/[^a-zA-Z0-9_\/-]/', '', (string) $request->input('folder', 'uploads')) ?: 'uploads';
        $path = $request->file('file')->store($folder, 'public');

        return response()->json([
            'data' => [
                'path' => $path,
                'url' => Storage::disk('public')->url($path),
            ],
        ]);
    }
}
