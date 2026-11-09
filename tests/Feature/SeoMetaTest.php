<?php

it('serves indexable meta for the legal pages that payment providers review', function (string $path, string $title) {
    $this->getJson('/api/public/seo/meta?path=' . urlencode($path))
        ->assertOk()
        ->assertJsonPath('title', $title)
        ->assertJsonPath('robots', 'index,follow,max-image-preview:large');
})->with([
    ['/refund-policy', 'Չեղարկման և վերադարձի քաղաքականություն | Vizit'],
    ['/personal-data', 'Անձնական տվյալների մշակման քաղաքականություն | Vizit'],
]);

it('keeps the business landing page indexable', function () {
    $this->getJson('/api/public/seo/meta?path=/business')
        ->assertOk()
        ->assertJsonPath('robots', 'index,follow,max-image-preview:large');
});

it('uses the raster share image that the frontend actually ships', function () {
    config()->set('services.public_booking.frontend_url', 'https://vizit.am');

    $this->getJson('/api/public/seo/meta?path=/pricing')
        ->assertOk()
        ->assertJsonPath('image', 'https://vizit.am/og-default.png');
});

it('lists the business landing and legal pages in the sitemap', function () {
    config()->set('services.public_booking.frontend_url', 'https://vizit.am');

    $xml = $this->get('/api/public/seo/sitemap.xml')->assertOk()->getContent();

    expect($xml)
        ->toContain('<loc>https://vizit.am/business</loc>')
        ->toContain('<loc>https://vizit.am/refund-policy</loc>')
        ->toContain('<loc>https://vizit.am/personal-data</loc>');
});
