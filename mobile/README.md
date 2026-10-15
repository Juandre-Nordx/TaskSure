# TaskSure manager and employee mobile frontend

TypeScript, Vite, Tailwind CSS 4 and Capacitor 8. Login, assigned work, SAST calendar, task details, camera/photo/PDF evidence, completion history and optional reviews, notifications and settings. Android and iOS projects load the compiled frontend locally and connect to the Laravel mobile API.

```sh
npm ci
cp .env.example .env
npm test
npm run sync
npm run android  # Android Studio
# or npm run ios on macOS with Xcode
```

For a local browser preview, leave `VITE_API_URL` unset, run Laravel on port 8080 and run `npm run dev`. For a production native build, set `VITE_API_URL` to the HTTPS Railway API URL shown in `.env.example`.

See [mobile setup, API and deployment](../docs/MOBILE.md) for native prerequisites, Firebase/APNs configuration, secure sign-in behavior and device validation. Managers have separate My tasks and Team tasks screens, scoped assignment and optional review/reopening. Owners use Blade. Business logic and permissions stay in Laravel. See the [release guide](../docs/COMPLETION-REVIEW-RELEASE.md) before upgrading production.

The supplied TaskSure logo is bundled locally and installed as the Android/iOS app icon. See [logo and app icon updates](../docs/BRANDING.md) for rebuilding and updating an installed app.

Published company policies require manager/employee acknowledgement before normal features open. The bundled screen rechecks Laravel on navigation/resume, handles blocked API responses, and keeps policy reading/sign-out available. See [the company-policy release guide](../docs/COMPANY-POLICY.md) for the database upgrade, coordinated Android update and physical-phone checks. Install this update before publishing the first policy.
