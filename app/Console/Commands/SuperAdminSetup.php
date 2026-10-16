<?php

namespace App\Console\Commands;

use App\Models\AccountAudit;
use App\Models\Store;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class SuperAdminSetup extends Command
{
    protected $signature = 'tasksure:super-admin {email} {name?} {--promote : Explicitly promote an existing owner}';

    protected $description = 'Create a platform Super Admin or explicitly promote an existing owner';

    public function handle(): int
    {
        $email = trim($this->argument('email'));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid email is required.');

            return self::FAILURE;
        }
        $user = User::where('email', $email)->first();
        if ($user) {
            if ($user->role === 'super_admin') {
                $this->info('This account is already a Super Admin.');

                return self::SUCCESS;
            }
            if (! $this->option('promote') || $user->role !== 'admin') {
                $this->error('Use a new email, or --promote for an existing store owner. No role was changed.');

                return self::FAILURE;
            }
            if (! $this->confirm('Promotion removes normal store task access and clears this account as assigned store owner. Continue?', false)) {
                return self::FAILURE;
            }
        } else {
            $name = trim($this->argument('name') ?? $this->ask('Super Admin name'));
            $password = $this->secret('New password (12+ characters, mixed case and number)');
            $confirmation = $this->secret('Confirm password');
            $validation = Validator::make(['name' => $name, 'password' => $password, 'password_confirmation' => $confirmation], ['name' => 'required|string|max:100', 'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()]]);
            if ($validation->fails()) {
                $this->error('Name or password did not meet requirements. No account was created.');

                return self::FAILURE;
            }
            $user = User::withoutEvents(fn () => User::create(['name' => $name, 'email' => $email, 'password' => $password, 'role' => 'super_admin', 'active' => true]));
        }
        DB::transaction(function () use ($user) {
            $user->forceFill(['role' => 'super_admin', 'active' => true])->save();
            Store::where('owner_id', $user->id)->update(['owner_id' => null]);
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->tokens()->delete();
            AccountAudit::create(['subject_id' => $user->id, 'action' => 'platform_super_admin_setup']);
        });
        $this->info('Super Admin ready. Sign in on the web at /super-admin. Reassign any stores needing an owner.');

        return self::SUCCESS;
    }
}
