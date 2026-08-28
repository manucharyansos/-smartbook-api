<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlansSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'code' => 'start',
                'name' => 'Start',
                'description' => 'Սոլո մասնագետների և նոր սկսող բիզնեսների համար։',
                'monthly_price' => 7900,
                'yearly_price' => 79000,
                'staff_limit' => 1,
                'services_limit' => 10,
                'locations' => 1,
                'sort_order' => 1,
                'features' => [
                    'staff_limit' => 1,
                    'services_limit' => 10,
                    'email_notifications' => true,
                    'telegram_notifications' => true,
                    'sms_reminders' => false,
                    'whatsapp_notifications' => false,
                    'priority_support' => false,
                    'custom_pricing' => false,
                    'partner_terms' => false,
                ],
            ],
            [
                'code' => 'studio',
                'name' => 'Studio',
                'description' => 'Աճող սրահների, փոքր թիմերի և մինչև 2 հասցե ունեցող բիզնեսների համար։',
                'monthly_price' => 14900,
                'yearly_price' => 149000,
                'staff_limit' => 5,
                'services_limit' => 30,
                'locations' => 2,
                'sort_order' => 2,
                'features' => [
                    'staff_limit' => 5,
                    'services_limit' => 30,
                    'email_notifications' => true,
                    'telegram_notifications' => true,
                    'sms_reminders' => false,
                    'whatsapp_notifications' => false,
                    'priority_support' => false,
                    'custom_pricing' => false,
                    'partner_terms' => false,
                ],
            ],
            [
                'code' => 'scale',
                'name' => 'Scale',
                'description' => 'Մեծ թիմերի, premium սրահների և մինչև 3 հասցե ունեցող բիզնեսների համար։',
                'monthly_price' => 27900,
                'yearly_price' => 279000,
                'staff_limit' => 15,
                'services_limit' => 100,
                'locations' => 3,
                'sort_order' => 3,
                'features' => [
                    'staff_limit' => 15,
                    'services_limit' => 100,
                    'email_notifications' => true,
                    'telegram_notifications' => true,
                    'sms_reminders' => false,
                    'whatsapp_notifications' => false,
                    'priority_support' => true,
                    'custom_pricing' => false,
                    'partner_terms' => false,
                ],
            ],
            [
                'code' => 'custom',
                'name' => 'Custom',
                'description' => '16+ աշխատակից, ցանցային բիզնեսներ և անհատական պայմաններ։',
                'monthly_price' => 0,
                'yearly_price' => 0,
                'staff_limit' => 999,
                'services_limit' => 999,
                'locations' => 10,
                'sort_order' => 4,
                'is_visible' => true,
                'features' => [
                    'staff_limit' => 999,
                    'services_limit' => 999,
                    'email_notifications' => true,
                    'telegram_notifications' => true,
                    'sms_reminders' => false,
                    'whatsapp_notifications' => false,
                    'priority_support' => true,
                    'custom_pricing' => true,
                    'partner_terms' => true,
                ],
            ],
        ];

        foreach ($plans as $plan) {
            $monthly = (int) $plan['monthly_price'];
            $yearly = (int) $plan['yearly_price'];

            Plan::updateOrCreate(
                ['code' => $plan['code']],
                [
                    'name' => $plan['name'],
                    'code' => $plan['code'],
                    'version' => 3,
                    'business_type' => null,
                    'allowed_business_types' => ['beauty', 'dental', 'services', 'healthcare'],
                    'description' => $plan['description'],
                    'price' => $monthly,
                    'price_beauty' => $monthly,
                    'price_dental' => $monthly,
                    'monthly_price' => $monthly,
                    'yearly_price' => $yearly,
                    'currency' => 'AMD',
                    'seats' => (int) $plan['staff_limit'],
                    'staff_limit' => (int) $plan['staff_limit'],
                    'duration_days' => 30,
                    'locations' => (int) ($plan['locations'] ?? 1),
                    'features' => $plan['features'],
                    'sort_order' => (int) $plan['sort_order'],
                    'is_active' => true,
                    'is_visible' => (bool) ($plan['is_visible'] ?? true),
                ]
            );
        }

        Plan::query()
            ->whereNotIn('code', collect($plans)->pluck('code'))
            ->update(['is_visible' => false]);
    }
}
