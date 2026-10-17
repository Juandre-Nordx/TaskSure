# Recurring tasks on specific dates

**Daily, Weekly and Monthly remain available.** The new **Specific dates** option lets store owners and scoped managers select a finite set of dates on web or Android, with a common deadline time in SAST. Laravel still owns task generation, permissions, evidence, completion and review.

## Create a schedule

1. Open **Assign a task**, enter the instructions, responsible person and proof requirements, then choose **Repeat → Specific dates**.
2. Tap calendar days to select/unselect them. To add a continuous range, enter **Range start** and **Range end**, then **Add date range**. Both endpoints are included. Overlapping ranges/individual dates are deduplicated. Remove any unwanted day using its date button.
3. Set **Deadline time for each selected date · SAST**. The selected-date preview shows the dates, deadline time and total occurrence count before saving. At least one and at most **366 unique dates** are allowed per schedule. New dates must be today or later in South African time.
4. Save. As with existing assignment-time recurrence, the earliest selected occurrence is assigned immediately. Remaining dates appear as **Planned recurring** calendar entries and become separate tasks when eligible through the existing scheduler. Calendar viewing does not create tasks or send alerts. Store settings' template creation retains its existing generate-when-eligible behavior rather than creating an immediate future first task.

Example: select **20, 22, 25 and 30 October 2026**, due **16:00 SAST**. These are four independent occurrences, stored with 14:00 UTC deadlines. Each starts Assigned / Not reviewed and needs its own required evidence, completion note and review. Completing one does not complete any other occurrence.

An existing task can also use **Make this task recurring → Specific dates**. Select dates after that task's original SAST deadline date. The original task/deadline/proof remains unchanged, while the selected repeats use the chosen common time. Assignment-time specific dates use no scheduled-start offset; conversion retains an existing task's start-to-deadline interval for future repeats.

Owners and managers keep their existing scopes: owners manage their authorised stores; managers manage their own series while the responsible person remains in their permitted team. Employees can see the dates but cannot edit or pause a schedule. Company-policy acknowledgement requirements also apply to creation/editing APIs.

## Edit future dates safely

Use **Edit specific dates** on a task or the web **Recurring tasks** page. Managers have the same editor on mobile. Owners can also edit through **Store settings**.

- Add/remove dates or change the common deadline, inspect the preview and save. New dates can precede the previous next-date cursor, provided they are today or later.
- Date/time changes update only future, unstarted, untouched occurrences. Started/completed/reopened work, proof, submission/review records, past deadlines and individually reassigned/rescheduled occurrences remain intact. Tasks with uploaded proof or other work activity are protected even if their status still says Assigned.
- Removing an ungenerated future date removes its preview. Removing an eligible already-created future occurrence cancels it, records the reason and retains its history. Readding a date cancelled by this schedule restores the **same task ID**; no duplicate task is inserted. Ordinary manually cancelled tasks stay cancelled. Cancelled records remain available under existing calendar/history filters.
- Past dates/deadline times remain in the schedule, including past uncreated dates in a paused series. Resuming still catches up eligible missed dates, using their preserved original times. New dates cannot be added in the past.
- Saving over a newer edit or a pause/resume change is rejected; reload the schedule first. The server serialises edits, generation and task transitions using database locks.
- The status control preserves pause/resume behavior. A finished schedule can be edited to add future dates; save it Active to generate them when eligible. “Finished” refers to the generation schedule, not completion of its existing tasks.
- Template content/assignee/proof changes in Store settings retain the previous behavior of applying to uncreated tasks. The specific-date editor changes dates/time/status. Changing between an interval schedule and specific dates requires a new schedule so existing work keeps its original series.

The database's unique template/date constraint remains authoritative. A per-save UUID/fingerprint also deduplicates retried specific-date creation requests, including the first task and assignment alert. Reusing the key with different content is rejected. IDs, store/company and creator permissions come from the authenticated request context.

## Controlled Railway release

No production deployment, migrations or Railway changes are performed automatically.

1. Review the source and **all pending migrations**. Back up PostgreSQL and the existing private upload volume together. Preserve APP_KEY, database references, current data, upload path, Firebase file and signing key.
2. This release adds `2026_10_17_000002_add_specific_date_schedules`: nullable date/time JSON and creation-key/fingerprint metadata, default-zero schedule revision on `task_templates`, and default-false `tasks.schedule_removed`. Original business fields, interval schedules, deadlines, users, proof, reviews, sessions and tokens are preserved. Nothing is published, generated or seeded by the migration. Its rollback refuses to discard schedule history.
3. Coordinate a maintenance window and stop old scheduler/worker writers. **Old scheduler code cannot process the new frequency.** Release web/worker/scheduler together before allowing specific-date creation. If earlier completion/store/policy-library migrations are pending, follow their coordinated procedures too.
4. Keep the **web-only Pre-Deploy Command**:

   ```sh
   php artisan migrate --force
   ```

   Run it through the authorised deployment step, never during Docker image build. Leave migration commands off worker/scheduler. Do not reset databases or seed production.
5. Deploy the approved source to web, worker and scheduler. Retain `PROCESS_ROLE=worker` / `PROCESS_ROLE=scheduler`, one replica each, existing shared PostgreSQL/APP_KEY/provider settings, and web's `/data` volume with `UPLOAD_STORAGE_PATH=/data/uploads`. No new service, native plugin or Firebase registration is needed. In the **web service shell**, check:

   ```sh
   php artisan migrate:status
   php artisan schedule:list
   php artisan queue:failed
   ```

   Confirm the new migration is **Ran**, `/health` returns 200 and original task/evidence workflows remain intact. Restore public access after the coordinated source update; run `php artisan up` if maintenance remains active. Do not run `tasks:generate` merely to inspect configuration: it writes eligible tasks and alerts.
6. The existing scheduler remains required for later dates. Verify exactly one eligible occurrence/alert after its next pass, and none duplicated after restarting the scheduler. Check queue/provider logs and real notification receipt independently.
7. Install the signed Android update below. Test the four-date example, a date range with individual removals, future edits, independent completion/proof/review, protected historical/started work, pause/resume and normal policy enforcement on both roles.

## Windows Android rebuild

After the reviewed source is delivered to your computer, open PowerShell in the repository root:

```powershell
cd mobile
npm ci
npm test
npm run build
npx cap sync android
npm run android
```

Keep your existing `mobile/.env` pointing at `https://tasksure-production.up.railway.app/api/mobile/v1`, `mobile/android/app/google-services.json` and original signing keystore. In Android Studio select **Build → Generate Signed App Bundle or APK → APK → release**. Application ID remains **`za.co.tasksure.employee`**. This update uses **versionCode 10 / versionName 1.9**; raise the code if a higher version is already installed. Install as an update.

A local debug compilation from `mobile/` with the JDK/Android SDK configured is:

```powershell
.\android\gradlew.bat --project-dir android :app:assembleDebug
```

On a physical phone verify calendar taps/keyboard, native range/date/time inputs, selection preview, manager creation/editing, employee read-only controls, per-date photo/note/review, back/resume, offline/retry, push and signed installation. Browser tests and debug compilation cannot establish these native/provider behaviors or Railway runtime status. Older APKs keep Daily/Weekly/Monthly and require rebuilding to display Specific dates/edit controls.

## API fields

- `POST /api/mobile/v1/team/tasks` with `recurrence: "specific_dates"`, `specific_dates: ["2026-10-20","2026-10-22","2026-10-25","2026-10-30"]`, `due_time: "16:00"`, and a UUID `creation_key`, plus existing assignment content/assignee/proof fields. Omit `due_at`, `scheduled_at` and `recurrence_until` for this mode. Repeated equivalent payloads with that key reuse the first task; use a new key for a new intended schedule. Older one-off/interval requests remain compatible.
- `POST /api/mobile/v1/tasks/{task}/recurrence`: `frequency: "specific_dates"`, `specific_dates`, `due_time`; omit interval-only next/end fields. These dates must follow the original task date. Conversion itself is guarded by the original task's row lock and rejects an already-linked task.
- `POST /api/mobile/v1/recurring-tasks/{template}/schedule`: `specific_dates`, `due_time`, boolean `active` and integer `schedule_revision` from the latest task response. Revision conflict/invalid dates return validation 422, without partial writes.
- Task recurrence metadata now includes nullable `specific_dates` and `schedule_revision`. Existing routes, auth/role/company/store checks and the template/date uniqueness constraint are retained.
