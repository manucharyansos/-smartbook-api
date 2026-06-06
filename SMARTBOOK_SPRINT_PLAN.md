# SmartBook Sprint Plan

## Working mode
- We are in launch-finalization mode.
- Do not open new large modules.
- Do not refactor stable architecture unless it blocks launch.
- After every block: frontend typecheck, frontend build, backend syntax check, manual flow test.

## Sprint 1 — Booking core hardening
- Fix booking business-context bugs (super admin / cross-business correctness)
- Harden create/update/reschedule/cancel flows
- Prevent invalid staff/service/location combinations
- Prevent overlap and blocked-time mistakes
- Re-test timezone handling and UTC/local conversions
- Manual QA: owner flow + super admin flow

## Sprint 2 — Availability + calendar correctness
- Audit availability calculations
- Ensure blocked/off-time is respected everywhere
- Ensure location/staff filters in calendar are reliable
- Clean quick actions in calendar
- Manual QA: calendar + availability + drag/drop + reschedule

## Sprint 3 — Plan enforcement + business settings
- Enforce locations/staff/services limits in backend
- Show usage vs plan limits clearly in UI
- Stabilize business settings and location management
- Manual QA: hit limits, upgrade/custom-plan paths

## Sprint 4 — Public booking polish
- Tighten mobile booking flow
- Close dead ends and improve success/error states
- Make public service/staff/location chain strict and clear
- Manual QA: guest booking end-to-end on mobile sizes

## Sprint 5 — Dashboard + permissions + final QA
- Minimum owner analytics
- Role/permission cleanup
- Empty/loading/error state pass on all core pages
- Final QA checklist and launch pass

## Current focus now
Sprint 1 only.
