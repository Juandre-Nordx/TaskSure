<?php

namespace Tests\Feature;

use App\Jobs\SendAlertPush;
use App\Models\Alert;
use App\Models\PushDevice;
use App\Models\User;
use App\Services\AlertService;
use App\Services\MobilePushGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MobilePushTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $platform): array
    {
        $user = User::create(['name' => 'Employee', 'email' => 'push@test.test', 'role' => 'employee', 'active' => true, 'password' => 'EmployeePassword123!']);
        $token = $user->createToken('phone', ['employee'], now()->addDay());
        $device = PushDevice::create(['user_id' => $user->id, 'personal_access_token_id' => $token->accessToken->id, 'platform' => $platform, 'token' => 'test-push-token', 'token_hash' => hash('sha256', 'test-push-token')]);
        $alert = Alert::create(['user_id' => $user->id, 'type' => 'assigned', 'message' => 'New task', 'dedupe_key' => 'push-fixture']);
        config(['mobile.push_enabled' => true]);

        return [$user, $device, $alert];
    }

    public function test_alerts_queue_one_push_per_new_alert_and_retries_skip_sent_devices(): void
    {
        [$user, $device, $alert] = $this->fixture('android');
        Bus::fake([SendAlertPush::class]);
        $service = app(AlertService::class);
        $service->send($user, null, 'assigned', 'Task update', 'new-alert');
        $service->send($user, null, 'assigned', 'Task update', 'new-alert');
        Bus::assertDispatchedTimes(SendAlertPush::class, 1);
        $gateway = $this->mock(MobilePushGateway::class);
        $gateway->shouldReceive('send')->once()->andReturn(true);
        $job = new SendAlertPush($alert->id);
        $job->handle($gateway);
        $job->handle($gateway);
        $this->assertDatabaseHas('push_deliveries', ['alert_id' => $alert->id, 'push_device_id' => $device->id, 'status' => 'sent']);
        $user->tokens()->delete();
        $job->handle($gateway);
        $this->assertDatabaseCount('push_devices', 0);
    }

    public function test_unconfigured_provider_records_failure_and_permanent_invalid_token_is_removed(): void
    {
        [$user, $device, $alert] = $this->fixture('android');
        Http::preventStrayRequests();
        config(['mobile.fcm_service_account' => null]);
        try {
            (new SendAlertPush($alert->id))->handle(app(MobilePushGateway::class));
            $this->fail('Unconfigured push must fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Mobile push delivery failed', $e->getMessage());
        }
        $this->assertDatabaseHas('push_deliveries', ['alert_id' => $alert->id, 'status' => 'failed', 'sent_at' => null]);
        $gateway = $this->mock(MobilePushGateway::class);
        $gateway->shouldReceive('send')->once()->andReturn(false);
        (new SendAlertPush($alert->id))->handle($gateway);
        $this->assertDatabaseCount('push_devices', 0);
    }

    public function test_fcm_http_v1_authorizes_and_sends_without_task_text_on_lock_screen(): void
    {
        [$user, $device, $alert] = $this->fixture('android');
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKey);
        config(['mobile.fcm_service_account' => json_encode(['project_id' => 'test-project', 'client_email' => 'service@test.test', 'private_key' => $privateKey])]);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-oauth-token']),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/test/messages/1']),
        ]);
        (new SendAlertPush($alert->id))->handle(app(MobilePushGateway::class));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'projects/test-project/messages:send') && $r['message']['token'] === 'test-push-token' && $r['message']['data']['alert_id'] === (string) $alert->id && ! str_contains($r['message']['notification']['body'], 'New task'));
        $this->assertDatabaseHas('push_deliveries', ['alert_id' => $alert->id, 'status' => 'sent']);
    }

    public function test_apns_uses_apple_token_signing_and_ios_payload(): void
    {
        [$user, $device, $alert] = $this->fixture('ios');
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $privateKey);
        config(['mobile.apns_key' => $privateKey, 'mobile.apns_key_id' => 'KEYID', 'mobile.apns_team_id' => 'TEAMID', 'mobile.apns_sandbox' => true]);
        Http::fake(['api.sandbox.push.apple.com/*' => Http::response([], 200)]);
        (new SendAlertPush($alert->id))->handle(app(MobilePushGateway::class));
        Http::assertSent(fn ($r) => $r->hasHeader('apns-topic', 'za.co.tasksure.employee') && $r->hasHeader('apns-push-type', 'alert') && $r['alert_id'] === (string) $alert->id && isset($r['aps']['alert']));
        $this->assertDatabaseHas('push_deliveries', ['alert_id' => $alert->id, 'status' => 'sent']);
    }
}
