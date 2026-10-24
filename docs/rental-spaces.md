# Space rentals

Rental businesses use the existing Vizit directory, map, owner/manager authentication, client records, booking notifications and subscription billing. Their `vertical` and `business_type` are `rentals`. Each registered rental business represents one independently bookable space at one location. Separate spaces need separate profiles in this version.

The `rental` subscription costs **25,000 AMD monthly**. Its annual price is 300,000 AMD, with no two-month promotion. The existing trial and billing recovery flow remain available. Regular staff-based plans cannot be selected by a rental business, and rental plans cannot be selected by other business types. Existing businesses and their subscriptions are not converted.

## Booking and money

- Rental bookings have nullable `service_id` and `staff_id`; no placeholder services or employees are created. Owner and manager accounts remain administrative accounts.
- The owner sets an hourly rate, minimum/maximum duration, slot step, weekly schedule and an optional gap between reservations. Prices are calculated on the server, rounded up to whole AMD and retained when the rate changes or a booking moves.
- Duration limits support up to 30 days. Customers can choose minutes, hours or days; one day is 24 elapsed hours billed at the hourly rate. The optional `rental_allow_overnight` setting permits closed hours between arrival and return on different local dates. Both handover times still require an open schedule window. Existing spaces default to `false`; continuous 24-hour schedules work across day boundaries without this option.
- Long-stay availability loads reservations and explicit blocks through the final day plus the configured buffer. Conflicts anywhere inside a stay prevent the entire booking. The existing locked write path performs the same complete-interval check at creation, verification and rescheduling.
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

For the already registered demo space only, run `php artisan rental:setup-demo thest-vizit-demo-space --owner-email=sos.evistep@gmail.com`. It validates the existing demo name and owner, installs the bundled logo/cover/gallery in public storage, and sets 5,000 AMD/hour, 1 hour–7 days, overnight rentals, 10:00–22:00 handover hours and a 15-minute buffer. `--dry-run` validates without writes. Re-running preserves existing logo/cover, gallery entries, users and bookings. It creates no account, changes no credential, billing status or subscription, and refuses a non-demo business or wrong owner.

Rollback must not restore a non-null service constraint while rental booking history exists. The migration deliberately retains that nullable constraint, the plan and the category on rollback. Do not roll back gallery tables after real media data is created without retaining a backup.

## Validation

28 rental integration scenarios cover registration/onboarding, plan separation, no-catalog behavior, server prices, 2-hour/5-hour/3-day stays, later-day conflicts, buffers/breaks/blocks, handover restrictions, continuous schedules, expired holds, OTP management, rescheduling, tenant isolation, ledger retries/refunds, public logo/cover/gallery delivery, idempotent demo setup and overnight/report boundaries.

The social-login test fixture now uses an already registered customer, matching the existing login contract. An additional test proves that Google login does not silently create a new customer. Authentication and legal-acceptance behavior are unchanged. The complete suite passes 154 tests.

Validation used a disposable SQLite database. Production MySQL migration behavior, real email/Telegram delivery, video playback and desktop/mobile browser interactions remain staging checks. No live business, payment or booking data was changed.
