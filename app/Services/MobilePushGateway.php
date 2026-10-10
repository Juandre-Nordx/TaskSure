<?php

namespace App\Services;

use App\Models\PushDevice;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MobilePushGateway
{
    // A false result means the provider has permanently invalidated this device token.
    public function send(PushDevice $device, array $data): bool
    {
        return $device->platform === 'android' ? $this->fcm($device, $data) : $this->apns($device, $data);
    }

    private function fcm(PushDevice $device, array $data): bool
    {
        $account = json_decode(config('mobile.fcm_service_account') ?? '', true);
        if (! is_array($account) || empty($account['private_key']) || empty($account['client_email']) || empty($account['project_id'])) {
            throw new RuntimeException('Firebase service account is not configured.');
        }
        $accessToken = Cache::remember('mobile:fcm:'.hash('sha256', $account['private_key']), 3500, function () use ($account) {
            $assertion = JWT::encode([
                'iss' => $account['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token', 'iat' => time(), 'exp' => time() + 3600,
            ], $account['private_key'], 'RS256');
            $response = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion,
            ]);
            if (! $response->successful() || ! $response->json('access_token')) {
                throw new RuntimeException('Firebase authorization failed. Check worker credentials.');
            }

            return $response->json('access_token');
        });
        $response = Http::withToken($accessToken)->timeout(20)->post('https://fcm.googleapis.com/v1/projects/'.rawurlencode($account['project_id']).'/messages:send', [
            'message' => [
                'token' => $device->token, 'notification' => ['title' => 'TaskSure', 'body' => 'You have a task update. Open TaskSure to view it.'],
                'data' => array_map('strval', $data), 'android' => ['priority' => 'high'],
            ],
        ]);
        if (collect($response->json('error.details') ?? [])->contains(fn ($detail) => ($detail['errorCode'] ?? '') === 'UNREGISTERED')) {
            return false;
        }
        if (! $response->successful()) {
            throw new RuntimeException('Firebase push failed (HTTP '.$response->status().').');
        }

        return true;
    }

    private function apns(PushDevice $device, array $data): bool
    {
        $key = str_replace('\\n', "\n", config('mobile.apns_key') ?? '');
        if (! $key || ! config('mobile.apns_key_id') || ! config('mobile.apns_team_id') || ! config('mobile.apns_bundle_id')) {
            throw new RuntimeException('APNs signing credentials are not configured.');
        }
        $jwt = Cache::remember('mobile:apns:'.hash('sha256', $key.config('mobile.apns_team_id').config('mobile.apns_key_id')), 3000, fn () => JWT::encode([
            'iss' => config('mobile.apns_team_id'), 'iat' => time(),
        ], $key, 'ES256', config('mobile.apns_key_id')));
        $host = config('mobile.apns_sandbox') ? 'api.sandbox.push.apple.com' : 'api.push.apple.com';
        $response = Http::withToken($jwt)->withHeaders([
            'apns-topic' => config('mobile.apns_bundle_id'), 'apns-push-type' => 'alert', 'apns-priority' => '10',
        ])->withOptions(['curl' => [CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0]])->timeout(20)->post('https://'.$host.'/3/device/'.$device->token, [
            'aps' => ['alert' => ['title' => 'TaskSure', 'body' => 'You have a task update. Open TaskSure to view it.'], 'sound' => 'default'],
        ] + $data);
        if (in_array($response->json('reason'), ['Unregistered', 'BadDeviceToken', 'DeviceTokenNotForTopic'])) {
            return false;
        }
        if (! $response->successful()) {
            throw new RuntimeException('APNs push failed (HTTP '.$response->status().').');
        }

        return true;
    }
}
