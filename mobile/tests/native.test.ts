import { afterEach, describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({
  platform: 'android',
  checkPermissions: vi.fn(),
  register: vi.fn(),
  addListener: vi.fn(),
}));
vi.mock('@capacitor/core', () => ({ Capacitor: { isNativePlatform: () => mocks.platform !== 'web', getPlatform: () => mocks.platform } }));
vi.mock('@capacitor/push-notifications', () => ({ PushNotifications: mocks }));
vi.mock('../src/build-config', () => ({ ANDROID_PUSH_CONFIGURED: false }));
import { enablePush, nativePushAvailable, setUpPush } from '../src/native';

afterEach(() => { mocks.platform = 'android'; vi.clearAllMocks(); });
describe('unconfigured Android push', () => {
  it('never invokes native Firebase registration without a matching client configuration', async () => {
    expect(nativePushAvailable()).toBe(false);
    await setUpPush(vi.fn(), vi.fn(), vi.fn());
    expect(await enablePush()).toContain('unavailable in this build');
    expect(mocks.checkPermissions).not.toHaveBeenCalled();
    expect(mocks.register).not.toHaveBeenCalled();
    expect(mocks.addListener).not.toHaveBeenCalled();
  });
  it('keeps browser push unavailable and allows native APNs configuration independently', () => {
    mocks.platform = 'web'; expect(nativePushAvailable()).toBe(false);
    mocks.platform = 'ios'; expect(nativePushAvailable()).toBe(true);
  });
});
