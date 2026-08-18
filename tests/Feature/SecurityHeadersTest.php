<?php

it('adds defensive headers to api responses', function () {
    $response = $this->getJson('/api/plans');

    $response
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("default-src 'none'")
        ->toContain("frame-ancestors 'none'");
});

it('prevents authentication responses from being cached', function () {
    $response = $this->postJson('/api/auth/login', [
        'email' => 'missing@example.com',
        'password' => 'not-a-real-password',
    ]);

    expect($response->headers->get('Cache-Control'))
        ->toContain('no-store')
        ->toContain('private');
    $response->assertHeader('Pragma', 'no-cache');
});
