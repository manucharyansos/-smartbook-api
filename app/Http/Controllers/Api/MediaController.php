<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    public function upload(Request $request)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'image', 'max:5120'],
            'folder' => ['nullable', 'string', 'max:60'],
        ]);

        $folder = Str::of((string) ($data['folder'] ?? 'uploads'))
            ->lower()
            ->replaceMatches('/[^a-z0-9\/_-]+/', '-')
            ->trim('/')
            ->value() ?: 'uploads';

        $path = $request->file('file')->store($folder, 'public');
        $url = MediaUrl::absolute($path);

        return response()->json([
            'data' => [
                'path' => $path,
                'url' => $url,
            ],
        ]);
    }

    public function show(string $path): StreamedResponse
    {
        $cleanPath = trim($path, '/');

        if ($cleanPath === '' || str_contains($cleanPath, '..')) {
            abort(404);
        }

        if (!Storage::disk('public')->exists($cleanPath)) {
            abort(404);
        }

        $mime = Storage::disk('public')->mimeType($cleanPath) ?: 'application/octet-stream';

        return Storage::disk('public')->response($cleanPath, null, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=31536000',
        ]);
    }
}
