# TaskSure employee mobile frontend

TypeScript, Vite, Tailwind CSS 4 and Capacitor 8. Login, assigned work, SAST calendar, task details, camera/photo/PDF evidence, submissions and review history, notifications and settings. Android and iOS projects load the compiled frontend locally and connect to the Laravel employee API.

```sh
npm ci
cp .env.example .env
npm test
npm run sync
npm run android  # Android Studio
# or npm run ios on macOS with Xcode
```

For a local browser preview, leave `VITE_API_URL` unset, run Laravel on port 8080 and run `npm run dev`. For a production native build, set `VITE_API_URL` to the HTTPS Railway API URL shown in `.env.example`.

See [mobile setup, API and deployment](../docs/MOBILE.md) for native prerequisites, Firebase/APNs configuration, secure sign-in behavior and device validation. Manager/owner screens and business logic stay in Laravel.
