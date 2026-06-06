<?php

namespace Database\Seeders;

use App\Models\BusinessCategory;
use App\Support\BusinessVertical;
use Illuminate\Database\Seeder;

class BusinessCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            // General appointment-based services
            ['services', 'beauty-salon', 'Գեղեցկության սրահ', 'Салон красоты', 'Beauty salon', 'sparkles', 10],
            ['services', 'barber-shop', 'Բարբերշոփ', 'Барбершоп', 'Barber shop', 'scissors', 20],
            ['services', 'nail-studio', 'Մատնահարդարման ստուդիա', 'Ногтевая студия', 'Nail studio', 'hand', 30],
            ['services', 'massage-spa', 'Մերսում և SPA', 'Массаж и SPA', 'Massage & SPA', 'spa', 40],
            ['services', 'fitness-trainer', 'Ֆիթնես մարզիչ', 'Фитнес-тренер', 'Fitness trainer', 'dumbbell', 50],
            ['services', 'car-wash', 'Ավտոլվացում', 'Автомойка', 'Car wash', 'car', 60],
            ['services', 'auto-service', 'Ավտոսերվիս', 'Автосервис', 'Auto service', 'wrench', 70],
            ['services', 'consulting', 'Խորհրդատվություն', 'Консультации', 'Consulting', 'messages', 80],
            ['services', 'courses', 'Դասընթացներ', 'Курсы', 'Courses', 'book-open', 90],
            ['services', 'photo-studio', 'Ֆոտոստուդիա', 'Фотостудия', 'Photo studio', 'camera', 100],
            ['services', 'other-services', 'Այլ ծառայություն', 'Другая услуга', 'Other service', 'grid', 999],

            // Healthcare
            ['healthcare', 'clinic', 'Կլինիկա', 'Клиника', 'Clinic', 'hospital', 10],
            ['healthcare', 'dental-clinic', 'Ատամնաբուժարան', 'Стоматология', 'Dental clinic', 'tooth', 20],
            ['healthcare', 'private-doctor', 'Մասնավոր բժիշկ', 'Частный врач', 'Private doctor', 'stethoscope', 30],
            ['healthcare', 'diagnostic-center', 'Ախտորոշիչ կենտրոն', 'Диагностический центр', 'Diagnostic center', 'activity', 40],
            ['healthcare', 'laboratory', 'Լաբորատորիա', 'Лаборатория', 'Laboratory', 'test-tube', 50],
            ['healthcare', 'physiotherapy', 'Ֆիզիոթերապիա', 'Физиотерапия', 'Physiotherapy', 'heart-pulse', 60],
            ['healthcare', 'rehabilitation', 'Ռեաբիլիտացիա', 'Реабилитация', 'Rehabilitation', 'accessibility', 70],
            ['healthcare', 'other-healthcare', 'Այլ բժշկական ծառայություն', 'Другая медицинская услуга', 'Other healthcare', 'plus-circle', 999],
        ];

        foreach ($categories as [$vertical, $slug, $hy, $ru, $en, $icon, $sort]) {
            BusinessCategory::updateOrCreate(
                ['slug' => $slug],
                [
                    'vertical' => BusinessVertical::normalize($vertical),
                    'name_hy' => $hy,
                    'name_ru' => $ru,
                    'name_en' => $en,
                    'icon' => $icon,
                    'sort_order' => $sort,
                    'is_active' => true,
                ]
            );
        }
    }
}
