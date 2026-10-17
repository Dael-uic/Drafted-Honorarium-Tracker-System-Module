# Honorarium Tracking Workspace (initial implementation)

This patch adds a shared Honorarium Tracking workspace to the existing Laravel + React/Inertia system.

## Included

- Shared `/honorarium-workspace` page for Administrative Assistants, Finance, and faculty.
- Workflow, schedule-board, payment-rate, and records tabs.
- AA can view student-uploaded defense receipts and send the receipt plus panel names to Finance.
- Finance can view submissions and verify or reject them; a rejection requires a reason.
- A separate `finance_honorarium_submissions` table tracks the handoff, status, notes, submitter, verifier, and timestamps.
- Sidebar links for AA, Finance, faculty, Dean, and coordinator roles.

## Apply the patch

1. Back up the current project and database first.
2. Copy/merge these files into the project root, preserving existing `.env`, `README.md`, and local configuration:
   - `app/Http/Controllers/HonorariumWorkspaceController.php`
   - `app/Models/FinanceHonorariumSubmission.php`
   - `database/migrations/2026_10_10_000001_create_finance_honorarium_submissions_table.php`
   - `resources/js/pages/honorarium/workspace/Index.tsx`
   - Updated `routes/web.php` and `resources/js/components/app-sidebar.tsx`
3. Install dependencies if needed: `composer install` and `npm ci`.
4. Run the migration: `php artisan migrate`.
5. Build frontend assets: `npm run build`.
6. Start the existing app as usual and visit `/honorarium-workspace` while signed in.

## Notes / current scope

- The workspace uses the existing `defense_requests.proof_of_payment` field and existing panel assignments. It does not replace the current student payment submission page.
- Defense scheduling and overlap prevention remain in the existing defense scheduling module; this tab is a consolidated schedule view, not a replacement scheduler.
- The faculty view filters records by matching the logged-in faculty name against panelist names. If the production data has different faculty/panelist identity mapping, that mapping should be connected before deployment.
- Email notifications, approval-stage notifications, and a complete immutable audit trail are not included in this first patch.
- Confirm the exact production role label used by Finance. The controller supports `Finance`, `Finance Office`, and `Finance Officer` (case-insensitive).
- Test in a development/staging database first. This archive has been syntax-checked for the new PHP files and routes; a full application build/runtime test requires the project's installed PHP/Node dependencies and database configuration.
