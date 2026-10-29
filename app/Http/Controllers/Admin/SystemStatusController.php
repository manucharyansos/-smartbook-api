<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/** Lets the super admin confirm a deployment: code version, pending migrations and caches. */
class SystemStatusController extends Controller
{
    public function __invoke()
    {
        $ran = DB::table('migrations')->pluck('migration')->all();
        $files = collect(File::files(database_path('migrations')))->map(fn ($file) => $file->getFilenameWithoutExtension())->sort()->values();

        return response()->json(['data' => [
            'commit' => $this->commit(),
            'pending_migrations' => $files->reject(fn ($name) => in_array($name, $ran, true))->values(),
            'config_cached' => app()->configurationIsCached(),
            'routes_cached' => app()->routesAreCached(),
            'environment' => app()->environment(),
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'database' => DB::connection()->getDriverName(),
            'server_time' => now()->toISOString(),
        ]]);
    }

    private function commit(): ?string
    {
        $head = base_path('.git/HEAD');
        if (! is_file($head)) {
            return null;
        }
        $value = trim((string) file_get_contents($head));
        if (str_starts_with($value, 'ref: ')) {
            $ref = base_path('.git/'.substr($value, 5));
            if (is_file($ref)) {
                return substr(trim((string) file_get_contents($ref)), 0, 7);
            }
            $packed = base_path('.git/packed-refs');
            if (is_file($packed) && preg_match('/^([0-9a-f]{40}) '.preg_quote(substr($value, 5), '/').'$/m', (string) file_get_contents($packed), $match)) {
                return substr($match[1], 0, 7);
            }

            return null;
        }

        return preg_match('/^[0-9a-f]{40}$/', $value) ? substr($value, 0, 7) : null;
    }
}
