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
        'privacy_accepted' => true,
        'privacy_version' => config('legal.privacy_version'),
    ]);

    $response
        ->assertCreated()
        ->assertJsonMissing(['email' => 'CONTACT@EXAMPLE.COM'])
        ->assertJsonMissing(['message' => 'Please tell me more about Vizit.']);

    $this->assertDatabaseHas('contact_requests', [
        'name' => 'Contact Person',
        'email' => 'contact@example.com',
        'status' => 'new',
        'privacy_version' => config('legal.privacy_version'),
    ]);
});

it('requires either email or phone on contact requests', function () {
    $this->postJson('/api/contact-requests', [
        'name' => 'Contact Person',
        'message' => 'Please contact me.',
        'privacy_accepted' => true,
        'privacy_version' => config('legal.privacy_version'),
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'phone']);
});

it('requires current privacy acceptance and stores evidence', function () {
    $payload = [
        'name' => 'Contact Person',
        'email' => 'contact@example.com',
        'message' => 'Please contact me.',
    ];

    $this->postJson('/api/contact-requests', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['privacy_accepted', 'privacy_version']);

    $this->postJson('/api/contact-requests', [
        ...$payload,
        'privacy_accepted' => true,
        'privacy_version' => 'outdated',
    ])->assertUnprocessable()->assertJsonValidationErrors(['privacy_version']);

    $this->withHeader('User-Agent', 'Vizit legal test')->postJson('/api/contact-requests', [
        ...$payload,
        'privacy_accepted' => true,
        'privacy_version' => config('legal.privacy_version'),
    ])->assertCreated();

    $this->assertDatabaseHas('contact_requests', [
        'email' => 'contact@example.com',
        'privacy_version' => config('legal.privacy_version'),
        'legal_acceptance_user_agent' => 'Vizit legal test',
    ]);

    expect(\App\Models\ContactRequest::latest('id')->value('privacy_accepted_at'))->not->toBeNull();
});
