<?php

use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
});

it('accepts a contact request with one reply channel without echoing personal data', function () {
    $response = $this->postJson('/api/contact-requests', [
        'name' => 'Contact Person',
        'email' => 'CONTACT@EXAMPLE.COM',
        'phone' => '',
        'message' => 'Please tell me more about Vizit.',
    ]);

    $response
        ->assertCreated()
        ->assertJsonMissing(['email' => 'CONTACT@EXAMPLE.COM'])
        ->assertJsonMissing(['message' => 'Please tell me more about Vizit.']);

    $this->assertDatabaseHas('contact_requests', [
        'name' => 'Contact Person',
        'email' => 'contact@example.com',
        'status' => 'new',
    ]);
});

it('requires either email or phone on contact requests', function () {
    $this->postJson('/api/contact-requests', [
        'name' => 'Contact Person',
        'message' => 'Please contact me.',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'phone']);
});
