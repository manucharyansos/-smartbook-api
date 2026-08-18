<?php

use App\Support\MediaUrl;

it('preserves external media URLs and normalizes media hosted by this api', function () {
    config()->set('app.url', 'https://api.vizit.am');

    expect(MediaUrl::absolute('https://cdn.example.com/images/cover.jpg'))
        ->toBe('https://cdn.example.com/images/cover.jpg')
        ->and(MediaUrl::absolute('https://api.vizit.am/storage/businesses/cover.jpg'))
        ->toBe('/api/media/file/businesses/cover.jpg');
});
