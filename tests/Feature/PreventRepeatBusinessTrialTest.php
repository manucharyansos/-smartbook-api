<?php

namespace Tests\Feature;

use App\Http\Middleware\PreventRepeatBusinessTrial;
use App\Models\TrialAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class PreventRepeatBusinessTrialTest extends TestCase
{
    use RefreshDatabase;

    private function request(array $data, array $headers = []): Request
    {
        $request = Request::create('/api/auth/register', 'POST', $data, [], [], $headers);
        $request->setLaravelSession($this->app['session.store']);
        return $request;
    }

    public function test_it_blocks_a_second_trial_for_the_same_phone(): void
    {
        TrialAttempt::create([
            'phone_norm' => '+37499111222',
            'email' => 'first@example.com',
            'fingerprint' => 'device-a',
            'ip' => '10.0.0.1',
        ]);

        $response = (new PreventRepeatBusinessTrial())->handle(
            $this->request(['business_phone' => '+374 99 111 222', 'email' => 'other@example.com']),
            fn () => response()->json(['ok' => true])
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('TRIAL_ALREADY_USED', $response->getData(true)['code']);
    }

    public function test_it_blocks_a_second_trial_for_the_same_device(): void
    {
        TrialAttempt::create([
            'phone_norm' => '+37499111222',
            'email' => 'first@example.com',
            'fingerprint' => 'device-a',
            'ip' => '10.0.0.1',
        ]);

        $response = (new PreventRepeatBusinessTrial())->handle(
            $this->request(
                ['business_phone' => '+374 77 333 444', 'email' => 'new@example.com'],
                ['HTTP_X_DEVICE_FINGERPRINT' => 'device-a']
            ),
            fn () => response()->json(['ok' => true])
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_it_allows_a_new_identity_to_continue_to_registration(): void
    {
        $response = (new PreventRepeatBusinessTrial())->handle(
            $this->request(
                ['business_phone' => '+374 77 333 444', 'email' => 'new@example.com'],
                ['HTTP_X_DEVICE_FINGERPRINT' => 'device-new']
            ),
            fn () => response()->json(['ok' => true])
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['ok']);
    }

    public function test_it_rate_limits_trial_farming_from_one_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            TrialAttempt::create([
                'phone_norm' => '+374' . (91000000 + $i),
                'email' => "trial{$i}@example.com",
                'fingerprint' => "device-{$i}",
                'ip' => '127.0.0.1',
            ]);
        }

        $response = (new PreventRepeatBusinessTrial())->handle(
            $this->request(['business_phone' => '+374 77 333 444', 'email' => 'fresh@example.com']),
            fn () => response()->json(['ok' => true])
        );

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('TRIAL_RATE_LIMITED', $response->getData(true)['code']);
    }
}
