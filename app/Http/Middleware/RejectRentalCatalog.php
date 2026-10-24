<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RejectRentalCatalog
{
    public function handle(Request $request, Closure $next)
    {
        abort_if($request->user()?->business?->isRental(), 403, 'Վարձով տարածքը չունի ծառայությունների կամ աշխատակիցների բաժին։');

        return $next($request);
    }
}
