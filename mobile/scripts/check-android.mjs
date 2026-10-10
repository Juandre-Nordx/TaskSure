import { readFileSync, existsSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { join, resolve } from 'node:path';

// Run after sync:android. Checks the files actually copied into the native app.
const root = fileURLToPath(new URL('../', import.meta.url));
const android = resolve(root, 'android');
const assets = join(android, 'app/src/main/assets');
const failures = [];
const requireFirebase = process.argv.includes('--require-firebase');
function check(condition, message) {
  console.log(`${condition ? 'PASS' : 'FAIL'} ${message}`);
  if (!condition) failures.push(message);
}
function readJson(path) {
  try { return JSON.parse(readFileSync(path, 'utf8')); } catch { return null; }
}
const config = readJson(join(assets, 'capacitor.config.json'));
const appId = config?.appId;
check(Boolean(config), 'Synced Capacitor configuration exists (run npm run sync:android).');
if (config) {
  const gradle = readFileSync(join(android, 'app/build.gradle'), 'utf8');
  check(Boolean(appId) && gradle.includes(`applicationId "${appId}"`), `Android application ID matches Capacitor: ${appId}`);
  check(config.webDir === 'dist' && !config.server?.url && config.server?.androidScheme === 'https', 'Local dist bundle with https://localhost; no remote server.url.');
  const publicDir = join(assets, 'public');
  const jsDir = join(publicDir, 'assets');
  check(existsSync(join(publicDir, 'index.html')), 'Bundled Android index.html exists.');
  const source = existsSync(jsDir) ? readdirSync(jsDir).filter(f => f.endsWith('.js')).map(f => readFileSync(join(jsDir, f), 'utf8')).join('\n') : '';
  const urls = [...new Set(source.match(/https:\/\/[^\s"'`<>]+\/api\/mobile\/v1/g) || [])];
  check(urls.length === 1, 'Exactly one HTTPS mobile API URL is embedded in the Android JavaScript.');
  if (urls.length === 1) console.log(`API: ${urls[0]}`);
}
const plugins = readJson(join(assets, 'capacitor.plugins.json')) || [];
for (const id of ['@capacitor/camera', '@capacitor/push-notifications', '@aparajita/capacitor-secure-storage']) {
  check(plugins.some(p => p.pkg === id), `${id} is registered in the Android native plugin list.`);
}
const firebasePath = join(android, 'app/google-services.json');
if (existsSync(firebasePath)) {
  const firebase = readJson(firebasePath);
  check(Boolean(firebase?.project_info?.project_id) && firebase?.client?.some(c => c.client_info?.android_client_info?.package_name === appId), 'Firebase client configuration is valid JSON and includes this application ID.');
  console.log('Firebase server credentials, permissions and delivery still require account/device verification.');
} else if (requireFirebase) {
  check(false, 'Missing android/app/google-services.json. Download it from your Firebase Android app.');
} else {
  console.log('WARN Firebase client configuration is absent. Basic app installation is possible; keep push disabled on the backend until configured.');
}
console.log('This checks bundled configuration; Gradle compilation and physical-device testing are separate checks.');
process.exitCode = failures.length ? 1 : 0;
