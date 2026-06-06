<?php
// app/Models/Concerns/BelongsToBusiness.php

namespace App\Models\Concerns;

use App\Models\Business;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

trait BelongsToBusiness
{
    public static function bootBelongsToBusiness(): void
    {
        static::creating(function ($model) {
            $user = Auth::user();
            if (!$user) return;

            if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
                return;
            }

            if (empty($model->business_id) && !empty($user->business_id)) {
                $model->business_id = $user->business_id;
            }
        });

        static::addGlobalScope('business', function (Builder $builder) {
            $user = Auth::user();
            if (!$user) return;

            if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
                return;
            }

            if (!empty($user->business_id)) {
                $builder->where('business_id', $user->business_id);
            }
        });
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }
}
