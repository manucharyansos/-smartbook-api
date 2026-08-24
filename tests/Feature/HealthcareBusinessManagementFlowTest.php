<?php

use App\Models\Plan;
use Illuminate\Support\Facades\Mail;

it('lets a healthcare owner create and manage services and staff through onboarding', function () {
    Mail::fake();

    Plan::query()->create([
        'name' => 'Start',
        'code' => 'start',
        'version' => 3,
        'allowed_business_types' => ['beauty', 'dental', 'services', 'healthcare'],
        'price' => 7900,
        'monthly_price' => 7900,
        'yearly_price' => 79000,
        'currency' => 'AMD',
        'seats' => 1,
        'staff_limit' => 1,
        'duration_days' => 30,
        'locations' => 1,
        'features' => ['staff_limit' => 1, 'services_limit' => 10],
        'is_active' => true,
        'is_visible' => true,
        'sort_order' => 1,
    ]);

    $registration = $this->postJson('/api/auth/register', [
        'business_name' => 'Ararat Medical Center',
        'business_phone' => '+37498408879',
        'business_address' => 'Yerevan, Armenia',
        'latitude' => 40.1772,
        'longitude' => 44.5035,
        'business_type' => 'dental',
        'vertical' => 'healthcare',
        'plan_code' => 'start',
        'name' => 'Medical Owner',
        'email' => 'medical-owner@example.com',
        'password' => 'SecurePassword123!',
        'password_confirmation' => 'SecurePassword123!',
    ]);

    $registration
        ->assertOk()
        ->assertJsonPath('user.business_type', 'healthcare')
        ->assertJsonPath('user.vertical', 'healthcare')
        ->assertJsonPath('user.role', 'owner')
        ->assertJsonPath('user.needs_onboarding', true);

    $token = (string) $registration->json('token');

    $service = $this->withToken($token)->postJson('/api/services', [
        'name' => 'Cardiology consultation',
        'description' => 'Initial cardiology consultation and care plan.',
        'duration_minutes' => 45,
        'price' => 15000,
        'currency' => 'AMD',
        'is_active' => true,
    ]);

    $service
        ->assertCreated()
        ->assertJsonPath('data.name', 'Cardiology consultation');

    $staff = $this->withToken($token)->postJson('/api/staff', [
        'name' => 'Dr. Ani Hakobyan',
        'email' => 'doctor@example.com',
        'password' => 'SecurePassword123!',
        'role' => 'staff',
        'bio' => 'Cardiologist',
        'show_in_public_team' => true,
        'is_bookable' => true,
    ]);

    $staff
        ->assertCreated()
        ->assertJsonPath('data.role', 'staff')
        ->assertJsonPath('data.is_bookable', true);

    // Managers do not consume the single bookable staff seat on the Start plan.
    $this->withToken($token)->postJson('/api/staff', [
        'name' => 'Medical Reception',
        'email' => 'reception@example.com',
        'password' => 'SecurePassword123!',
        'role' => 'manager',
        'show_in_public_team' => false,
        'is_bookable' => false,
    ])
        ->assertCreated()
        ->assertJsonPath('data.role', 'manager');

    $this->withToken($token)
        ->postJson('/api/business/complete-onboarding')
        ->assertOk()
        ->assertJsonPath('business_type', 'healthcare')
        ->assertJsonPath('is_onboarding_completed', true);

    $this->withToken($token)
        ->getJson('/api/services')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Cardiology consultation');

    $this->withToken($token)
        ->getJson('/api/staff?only_active=0')
        ->assertOk()
        ->assertJsonFragment(['email' => 'doctor@example.com', 'is_bookable' => true]);

    $schedule = collect(range(1, 7))->map(fn (int $weekday) => [
        'weekday' => $weekday,
        'is_closed' => $weekday === 7,
        'start' => $weekday === 7 ? null : '09:00',
        'end' => $weekday === 7 ? null : '17:30',
        'break_start' => $weekday === 7 ? null : '13:00',
        'break_end' => $weekday === 7 ? null : '14:00',
    ])->all();

    $this->withToken($token)
        ->putJson('/api/staff/'.$staff->json('data.id').'/schedule', ['days' => $schedule])
        ->assertOk();

    $this->withToken($token)
        ->getJson('/api/staff/'.$staff->json('data.id').'/schedule')
        ->assertOk()
        ->assertJsonCount(7, 'data')
        ->assertJsonPath('data.0.start', '09:00');

    $this->withToken($token)
        ->putJson('/api/services/'.$service->json('data.id'), [
            'name' => 'Extended cardiology consultation',
            'duration_minutes' => 60,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Extended cardiology consultation');

    $this->withToken($token)
        ->patchJson('/api/staff/'.$staff->json('data.id'), [
            'bio' => 'Cardiologist · 10 years of experience',
            'show_in_public_team' => true,
            'is_bookable' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.bio', 'Cardiologist · 10 years of experience');
});
