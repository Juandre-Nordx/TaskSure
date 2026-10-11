# TaskSure logo and app icons

The supplied TaskSure icon pack is installed in the browser, website navigation, employee app and native app projects. The original SVG is `public/tasksure-icon.svg`; its matching copy in `mobile/public/` is bundled with the app and loads locally.

## Website

The shared `partials.brand-icons` Blade view supplies ICO, 32px PNG and SVG favicons, the 180px Apple touch icon and the dark-green browser theme. Both the sign-in/password pages and signed-in dashboard include it. The website header uses the supplied SVG instead of a text checkmark.

Release the branding change through your existing GitHub/Railway workflow. The repository's Docker build includes these public assets and rebuilds the stylesheet; it does not require new dependencies or a database migration. After deployment, open `https://tasksure-production.up.railway.app/tasksure-icon.svg` and `https://tasksure-production.up.railway.app/favicon.ico`. Reopen the browser tab if its old favicon remains cached. The explicit favicon links include a version query to help refresh the old Laravel icon.

## Android

The supplied checkmark is used for all five legacy icon densities, round launcher icons, the Android 8+ adaptive foreground and the Android 13+ themed icon. The adaptive background is `#153E35`; the foreground is inset within Android's safe area. The notification status-bar icon uses the same checkmark in white, as required by Android. Android applies the selected launcher's circle/square shape to the adaptive icon.

In Windows PowerShell, from your existing TaskSure checkout after applying the branding update:

```powershell
cd mobile
npm ci
$env:VITE_API_URL="https://tasksure-production.up.railway.app/api/mobile/v1"
npm run build
npx cap sync android
npm run android
```

Keep your existing `mobile/android/app/google-services.json` in this checkout when building your Firebase-enabled app. In Android Studio, select the connected phone and click **Run**, or build an APK and install it on the phone. Use the same signing key as the currently installed app for an in-place update. The application ID remains `za.co.tasksure.employee`.

To assemble an APK from an Android Studio PowerShell terminal with Java and the Android SDK configured:

```powershell
cd android
.\gradlew.bat :app:assembleDebug
```

The APK is `mobile/android/app/build/outputs/apk/debug/app-debug.apk`. For a signed release, use the existing release signing workflow and increment `versionCode` for a later store release. Rebuilding the Vite frontend alone does not update the installed launcher icon: the native APK must also be built and installed.

## iOS

The Xcode app icon has also been replaced with an opaque 1024×1024 PNG generated from the supplied SVG. iOS supplies its own corner mask. Rebuild the native app with Xcode on macOS to see the new home-screen icon.

## Source assets

Browser files are copied directly from the uploaded pack. Android raster images are exported from its SVG at 48, 72, 96, 144 and 192 pixels. Adaptive foreground PNG fallbacks are 108, 162, 216, 324 and 432 pixels, with the same 0.84 scale as the vector foreground. These are committed app resources; `cap sync` preserves them.

This update changes branding assets and their view references. It contains no user/task data changes, database operations, Firebase credentials or signing keys.
