<?php

use App\Services\LoyaltyService;
use Illuminate\Support\Carbon;

it('calculates the remaining balance and only unspent points expiring within thirty days', function () {
    $asOf = Carbon::parse('2026-09-01 12:00:00', 'UTC');
    $entries = collect([
        (object) [
            'id' => 1,
            'delta_points' => 100,
            'created_at' => $asOf->copy()->subDays(10),
            'expires_at' => $asOf->copy()->addDays(20),
        ],
        (object) [
            'id' => 2,
            'delta_points' => -30,
            'created_at' => $asOf->copy()->subDays(5),
            'expires_at' => null,
        ],
        (object) [
            'id' => 3,
            'delta_points' => 50,
            'created_at' => $asOf->copy()->subDay(),
            'expires_at' => null,
        ],
    ]);

    $breakdown = (new LoyaltyService())->balanceBreakdownFromEntries($entries, $asOf);

    expect($breakdown)->toBe([
        'balance' => 120,
        'expiring_in_30_days' => 70,
    ]);
    expect((new LoyaltyService())->balanceFromEntries($entries, $asOf->copy()->addDays(21)))->toBe(50);
});
