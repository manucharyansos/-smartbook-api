<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrialAttempt extends Model
{
    protected $fillable = [
        'phone_norm',
        'fingerprint',
        'email',
        'ip',
    ];
}
