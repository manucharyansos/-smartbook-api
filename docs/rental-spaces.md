# Space rentals

Rental businesses use the existing Vizit directory, map, owner/manager authentication, client records, booking notifications and subscription billing. Their `vertical` and `business_type` are `rentals`. Each registered rental business represents one independently bookable space at one location. Separate spaces need separate profiles in this version.

The `rental` subscription costs **25,000 AMD monthly**. Its annual price is 300,000 AMD, with no two-month promotion. The existing trial and billing recovery flow remain available. Regular staff-based plans cannot be selected by a rental business, and rental plans cannot be selected by other business types. Existing businesses and their subscriptions are not converted.

## Booking and money

- Rental bookings have nullable `service_id` and `staff_id`; no placeholder services or employees are created. Owner and manager accounts remain administrative accounts.
- The owner sets an hourly rate, minimum/maximum duration, slot step, weekly schedule and an optional gap between reservations. Prices are calculated on the server, rounded up to whole AMD and retained when the rate changes or a booking moves.
- Availability accounts for weekly breaks, exceptions, blocked intervals, confirmed bookings and unexpired ten-minute verification holds. Overnight windows are supported. Business-row locks serialize rental creation, moves, confirmation, blocking and payment recording.
- Public customers verify their booking with the existing four-digit email/Telegram flow and receive a token-protected management link. Rescheduling and cancellation use the existing cutoff rules. A failed verification delivery releases a newly created hold.
- Manual payment/refund records use the existing booking payment ledger. Each write requires an idempotency UUID; retries do not duplicate money. Recording a payment does not charge a bank card. Cancellation does not automatically refund money.
- Financial reports cover bookings **starting** in the selected business-local dates. They show booked value, completed value, recorded receipts, refunds, net receipts, unpaid balances and refunds due. Occupancy clips reservations to the report interval and uses the current schedule, excluding blocks. It is not a historical schedule snapshot or profit/tax accounting.

## Gallery

Up to 20 files per space. Images: JPEG, PNG and WebP, up to 10 MiB each. Videos: MP4 and WebM, up to 50 MiB each. Files are scoped to the business; captions/order can be edited and files removed. Playback depends on the video codec supported by the customer's browser; this version does not transcode videos.

## Deployment

1. Deploy this API change before the corresponding `smartbook-we` frontend change. Use the deployment's existing environment and back up its database.
2. Install the existing locked dependencies (`composer install --no-dev --prefer-dist --optimize-autoloader`).
3. Run `php artisan migrate --force`, then `php artisan optimize:clear`. No reset, fresh migration or application-wide data seed is required. The migration adds the rental plan/category without modifying existing business data.
4. Ensure public media storage is writable. `public/.user.ini` supplies upload limits for compatible PHP hosting. Confirm the actual host accepts a 50 MiB file and a 55 MiB request; a reverse proxy can impose a lower limit.
5. Deploy the paired frontend build and check registration, onboarding, a real email confirmation, gallery uploads/playback and mobile layouts on staging before production rollout.

Rollback must not restore a non-null service constraint while rental booking history exists. The migration deliberately retains that nullable constraint, the plan and the category on rollback. Do not roll back gallery tables after real media data is created without retaining a backup.

## Validation

14 rental integration scenarios cover registration/onboarding, plan separation, no-catalog behavior, server prices, overlaps, buffers/breaks/blocks, expired holds, OTP management, rescheduling, tenant isolation, ledger retries/refunds, gallery ownership and overnight/report boundaries. The complete suite passed 138 tests; one pre-existing social-login test also fails on the unchanged baseline `9b0fb44` (it expects login to create a previously unregistered client). No authentication or legal-acceptance rule was weakened to make that test pass.

Validation used a disposable SQLite database. Production MySQL migration behavior, real email/Telegram delivery, video playback and desktop/mobile browser interactions remain staging checks. No live business, payment or booking data was changed.
