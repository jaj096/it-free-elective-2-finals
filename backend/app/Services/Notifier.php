<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\CertificateRequest;
use App\Models\User;
use App\Notifications\RequestUpdate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/** Sends in-app notifications to the role responsible for the next step. */
class Notifier
{
    public function toRole(Role $role, CertificateRequest $request, string $kind, string $message): void
    {
        $users = User::where('role', $role->value)->get();
        Notification::send($users, new RequestUpdate($request, $kind, $message));

        Log::channel('automation')->info('notification.sent', [
            'request_id' => $request->id,
            'kind' => $kind,
            'to_role' => $role->value,
            'recipients' => $users->count(),
        ]);
    }

    public function toRequester(CertificateRequest $request, string $kind, string $message): void
    {
        $request->requester->notify(new RequestUpdate($request, $kind, $message));

        Log::channel('automation')->info('notification.sent', [
            'request_id' => $request->id,
            'kind' => $kind,
            'to_role' => Role::Requester->value,
            'recipients' => 1,
        ]);
    }
}
