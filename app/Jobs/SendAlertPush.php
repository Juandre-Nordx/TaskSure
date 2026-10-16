<?php

namespace App\Jobs;

use App\Models\Alert;
use App\Models\PushDelivery;
use App\Models\PushDevice;
use App\Models\Store;
use App\Models\User;
use App\Services\MobilePushGateway;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendAlertPush implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 600;

    public function __construct(public int $alertId) {}

    public function uniqueId(): string
    {
        return (string) $this->alertId;
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(MobilePushGateway $gateway): void
    {
        $alert = Alert::with(['user', 'task'])->find($this->alertId);
        if (! config('mobile.push_enabled') || ! $alert || ! $alert->deliverable() || ! in_array($alert->user->role, User::ASSIGNEE_ROLES)) {
            return;
        }
        $devices = PushDevice::where('user_id', $alert->user_id)->with('accessToken')
            ->whereHas('accessToken', fn ($q) => $q->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now())))->get();
        $data = ['alert_id' => (string) $alert->id];
        if ($alert->task) {
            $data['task_id'] = (string) $alert->task_id;
        }
        $failure = null;
        foreach ($devices as $device) {
            $data['store_id'] = (string) $device->store_id;
            if (! $device->store_id || (int) $device->accessToken->store_id !== (int) $device->store_id || ! $alert->user->inStore($device->store_id)) {
                continue;
            }
            if ($alert->store_id ? $alert->store_id !== $device->store_id : Store::whereKey($device->store_id)->value('company_id') !== $alert->company_id) {
                continue;
            }
            if (! $device->accessToken->can('mobile') && ! $device->accessToken->can($alert->user->role)) {
                continue;
            }
            $delivery = PushDelivery::firstOrCreate(['alert_id' => $alert->id, 'push_device_id' => $device->id]);
            if ($delivery->status === 'sent') {
                continue;
            }
            try {
                if (! $gateway->send($device, $data)) {
                    $device->delete();

                    continue;
                }
                $delivery->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
            } catch (Throwable $e) {
                $delivery->update(['status' => 'failed', 'error' => 'Push delivery failed. Check worker configuration.']);
                $failure = $e;
            }
        }
        if ($failure) {
            // Keep provider credentials and device tokens out of persisted queue errors.
            throw new \RuntimeException('Mobile push delivery failed. Check push provider configuration and connectivity.');
        }
    }
}
