<?php

it('rejects booking verification OTP values that are not exactly four digits', function () {
    foreach (['123', '12345', '12a4', '', null] as $otp) {
        $this->postJson('/api/public/bookings/OTP-CONTRACT-MISSING/verify', ['otp' => $otp])
            ->assertStatus(422)
            ->assertJsonValidationErrors('otp');
    }
});

it('applies the same four digit contract to the v1 public booking alias', function () {
    $this->postJson('/api/v1/public/bookings/OTP-CONTRACT-MISSING/verify', ['otp' => '12345'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('otp');
});

it('allows an exactly four digit OTP to reach the booking verification controller', function () {
    $this->postJson('/api/public/bookings/OTP-CONTRACT-MISSING/verify', ['otp' => '1234'])
        ->assertNotFound();
});
