Vizit.am — ամրագրման հաստատում

Դուք սկսել եք ամրագրում {{ $booking->business?->name }}-ում։

Հաստատման կոդ՝ {{ $code }}
Վավեր է մինչև՝ {{ $expires->copy()->timezone($booking->business?->timezone ?: config('app.timezone'))->format('d.m.Y H:i') }}

Ծառայություն՝ {{ $booking->items->count() ? $booking->items->map(fn($item) => $item->service?->name)->filter()->implode(', ') : ($booking->service?->name ?: 'Ծառայություն') }}
@if($booking->staff?->name)
Մասնագետ՝ {{ $booking->staff?->name }}
@endif
Ամսաթիվ և ժամ՝ {{ $booking->starts_at?->copy()?->timezone($booking->business?->timezone ?: config('app.timezone'))->format('d.m.Y H:i') ?? '—' }}

Վերադարձեք Vizit-ի բաց ամրագրման էջ և մուտքագրեք կոդը։ Եթե դուք չեք սկսել այս ամրագրումը, անտեսեք նամակը։ Կոդը ոչ մեկին մի փոխանցեք։

Սա Vizit.am-ի ավտոմատ ծառայողական նամակ է։
