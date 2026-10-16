# PDF company policies

Owners can upload an existing PDF instead of typing a policy. This extends the existing company policy service, permissions, immutable versions and mandatory acknowledgement guard. Written policies remain supported.

## Workflow

1. Open **Company policy**, enter a title, choose **Upload PDF**, select a file and **Save draft**. Saving does not notify staff or interrupt access. The owner can preview/download the saved PDF. **Written policy** retains the text option.
2. Review the saved draft and **Publish policy**. Existing in-app alerts and configured email/push queues notify affected managers/employees. No new scheduler or Firebase service is needed.
3. Staff see the mandatory screen on their next authenticated request: title, exact version, publication date in SAST, **Read policy**, **Download PDF**, an unchecked checkbox and disabled **Confirm and continue** button.
4. Web uses an embedded browser PDF viewer with a download fallback. Android retrieves bytes with its bearer token and opens the existing FileViewer. **Download PDF** opens Android's Save As picker, even without a PDF reader installed. No broad storage permission is needed. iOS retains its existing document viewer/share action.
5. Opening/downloading does not acknowledge anything. Staff must tick **I have read and understand this company policy.** and confirm. Only the current version's successful server acknowledgement restores access. Reading and sign-out remain available while locked.
6. A replacement upload is a draft until publication. Publishing creates a new immutable version and requires confirmation again. If another version is published while someone is reading, their old confirmation returns HTTP 423, `POLICY_ACKNOWLEDGEMENT_REQUIRED` and the latest policy.
7. The owner's current overview keeps its selected-store staff list and role/status filters. Open a published version to access its original PDF and exact-version acknowledgement names, roles and SAST timestamps, including now-inactive staff. Stores in one company share the policy; other companies cannot read it. “Acknowledged” records confirmation, not proof of understanding.

Leave the file field empty to retain the saved draft PDF, or start a draft using the current published PDF when no draft exists. This permits title corrections without another upload; publishing still creates a new version. Revision checks reject stale owner tabs before writing their upload.

## Storage and API

- Validate detected MIME/type, `.pdf` extension, header and end marker. The shared `UPLOAD_MAX_KB` limit defaults to **10240 KB (10 MB)**. These checks are not antivirus scanning or a complete PDF parser.
- Store files on the existing private `evidence` disk at `company-policies/{company-id}/{random-uuid}.pdf`. Railway's `UPLOAD_STORAGE_PATH=/data/uploads` places them under `/data/uploads/company-policies/` on web's existing `/data` volume. Do not put them in `public/` or expose a public storage link.
- PostgreSQL retains original display name, byte size, private path and SHA-256 for each draft/published PDF. Published files are never overwritten. Successful draft uploads are retained; any future retention cleanup must preserve every referenced published PDF. Failed draft transactions remove only their own new upload.
- Authenticated PDF responses enforce the server-selected company and active store membership, send `private, no-store` / `nosniff`, and support inline viewing or `?download=1`. Draft access is owner-only. Reading a published PDF never unlocks protected task functions.
- API policy data adds nullable `document: { name, size, mime, url }`. Written policies retain `content` and `document: null`. The URL is API-relative, e.g. `/company-policy/versions/12/pdf`; it contains no token or private storage path. Every mobile read/export fetches through the API using the current sign-in and selected store. No external PDF viewing service receives the document or credentials.
- Native temporary PDFs use the existing private evidence cache, cleared on sign-out/store switch. An intentionally exported copy remains at the user's chosen location. Neither cached nor exported files can acknowledge a version or bypass protected server requests.
- Back up PostgreSQL and the **whole upload directory**, including `company-policies/`, together using [BACKUPS.md](BACKUPS.md). Worker/scheduler do not need this volume.

## Controlled Railway release

Implementation does not deploy or run production migrations automatically.

1. Review the source and back up PostgreSQL and web's `/data` volume. Preserve the existing APP_KEY, database references, upload path, Firebase configuration and APK signing key.
2. Review **all pending migrations**. This feature adds `2026_10_16_000002_add_company_policy_pdfs`: nullable file metadata columns on `companies` and `company_policy_versions`. It does not rewrite old policies, acknowledgements, accounts, tasks, sessions or tokens, publish anything, seed data or send alerts. Its rollback refuses to discard policy history. If earlier multi-store/completion migrations are pending, follow their coordinated release guides first.
3. Keep this Railway **web-only Pre-Deploy Command** for the explicitly authorised release:

   ```sh
   php artisan migrate --force
   ```

   No migrations run during Docker image build or entrypoint. Leave migration commands off worker/scheduler. Never reset databases, use `migrate:fresh`/`migrate:refresh`, or seed production.
4. Deploy approved web source, then the same source on existing worker/scheduler. In the web Railway shell check:

   ```sh
   php artisan migrate:status
   ```

   Confirm the PDF migration ran, `/health` succeeds and existing task/evidence workflows work. Web must retain one replica, `/data` volume mount and `UPLOAD_STORAGE_PATH=/data/uploads`. Existing PHP/Nginx limits cover the shared upload limit.
5. **Install the new signed Android update before publishing a PDF.** Older APKs cannot display the PDF controls. This release uses **versionCode 7 / versionName 1.6**, application ID **`za.co.tasksure.employee`**. Raise the code if your installed release is higher. Keep the original signing key and `mobile/android/app/google-services.json`.
6. Test with manager and employee accounts: publish → mandatory screen → read/download → verify access stays blocked → tick/confirm → normal work. Publish a replacement, test stale confirmation, new staff, history, sign-out and company isolation. Restart web and compare a previously published download's bytes. Check real push delivery/worker logs separately.

## Windows Android rebuild

Open PowerShell in the updated repository root:

```powershell
cd mobile
npm ci
npm test
npm run build
npx cap sync android
npm run android
```

Confirm existing `mobile/.env` targets `https://tasksure-production.up.railway.app/api/mobile/v1`. In Android Studio select **Build → Generate Signed App Bundle or APK → APK → release**, use the original keystore and install as an update. No new npm plugin or Firebase registration is required.

Physical Android checks remain: installed/missing PDF reader, Save PDF success/cancellation, hardware/gesture back, resume, offline confirmation and actual notifications. Compilation and mocked native handoffs do not prove those behaviours or production volume persistence.
