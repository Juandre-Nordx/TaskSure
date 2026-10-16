<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('users')->whereNull('company_id')->exists()) {
            throw new RuntimeException('Resolve accounts without a company before migrating stores. No accounts were reassigned.');
        }
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('name', 120);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['company_id', 'active']);
        });
        Schema::create('store_user', function (Blueprint $table) {
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->primary(['store_id', 'user_id']);
            $table->index(['user_id', 'active']);
        });
        Schema::create('store_manager_employee', function (Blueprint $table) {
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('manager_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('users')->restrictOnDelete();
            $table->primary(['store_id', 'manager_id', 'employee_id']);
        });
        Schema::create('category_store', function (Blueprint $table) {
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->primary(['store_id', 'category_id']);
        });
        Schema::create('store_settings', function (Blueprint $table) {
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('key');
            $table->text('value');
            $table->primary(['store_id', 'key']);
        });
        foreach (['tasks', 'task_templates', 'personal_access_tokens', 'push_devices', 'alerts', 'account_audits'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->foreignId('store_id')->nullable()->constrained()->restrictOnDelete());
        }
        foreach (['alerts', 'account_audits'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete());
        }
        $map = [];
        foreach (DB::table('companies')->orderBy('id')->get() as $company) {
            $map[$company->id] = DB::table('stores')->insertGetId(['company_id' => $company->id, 'owner_id' => DB::table('users')->where('company_id', $company->id)->where('role', 'admin')->orderByDesc('active')->orderBy('id')->value('id'), 'name' => mb_substr($company->name.' store', 0, 120), 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            foreach (DB::table('categories')->pluck('id') as $category) {
                DB::table('category_store')->insert(['store_id' => $map[$company->id], 'category_id' => $category]);
            }
            foreach (DB::table('settings')->get() as $setting) {
                DB::table('store_settings')->insert(['store_id' => $map[$company->id], 'key' => $setting->key, 'value' => $setting->value]);
            }
        }
        $users = DB::table('users')->get()->keyBy('id');
        foreach ($users as $user) {
            DB::table('store_user')->insert(['store_id' => $map[$user->company_id], 'user_id' => $user->id, 'active' => true]);
        }
        foreach (DB::table('manager_employee')->get() as $scope) {
            if ($users[$scope->manager_id]->company_id === $users[$scope->employee_id]->company_id) {
                DB::table('store_manager_employee')->insert(['store_id' => $map[$users[$scope->manager_id]->company_id], 'manager_id' => $scope->manager_id, 'employee_id' => $scope->employee_id]);
            }
        }
        foreach (['tasks', 'task_templates'] as $name) {
            DB::table($name)->orderBy('id')->chunkById(200, function ($rows) use ($name, $users, $map) {
                foreach ($rows as $row) {
                    DB::table($name)->where('id', $row->id)->update(['store_id' => $map[$users[$row->employee_id]->company_id]]);
                }
            });
            Schema::table($name, fn (Blueprint $table) => $table->unsignedBigInteger('store_id')->nullable(false)->change());
        }
        DB::table('personal_access_tokens')->orderBy('id')->chunkById(200, function ($rows) use ($users, $map) {
            foreach ($rows as $row) {
                if ($row->tokenable_type === 'App\\Models\\User' && isset($users[$row->tokenable_id])) {
                    DB::table('personal_access_tokens')->where('id', $row->id)->update(['store_id' => $map[$users[$row->tokenable_id]->company_id]]);
                }
            }
        });
        foreach (DB::table('push_devices')->get() as $device) {
            DB::table('push_devices')->where('id', $device->id)->update(['store_id' => DB::table('personal_access_tokens')->where('id', $device->personal_access_token_id)->value('store_id')]);
        }
        DB::table('alerts')->orderBy('id')->chunkById(200, function ($rows) use ($users, $map) {
            foreach ($rows as $row) {
                $store = $row->task_id ? DB::table('tasks')->where('id', $row->task_id)->value('store_id') : ($row->type === 'policy_published' ? null : $map[$users[$row->user_id]->company_id]);
                $company = $store ? DB::table('stores')->where('id', $store)->value('company_id') : $users[$row->user_id]->company_id;
                DB::table('alerts')->where('id', $row->id)->update(['store_id' => $store, 'company_id' => $company]);
            }
        });
        foreach (DB::table('account_audits')->get() as $audit) {
            $user = $users[$audit->actor_id] ?? $users[$audit->subject_id] ?? null;
            if ($user) {
                DB::table('account_audits')->where('id', $audit->id)->update(['store_id' => $map[$user->company_id], 'company_id' => $user->company_id]);
            }
        }
        Schema::table('tasks', fn (Blueprint $table) => $table->index(['store_id', 'due_at', 'status']));
        Schema::table('task_templates', fn (Blueprint $table) => $table->index(['store_id', 'active', 'next_date']));
        Schema::table('alerts', fn (Blueprint $table) => $table->index(['user_id', 'store_id', 'read_at']));
    }

    public function down(): void
    {
        throw new RuntimeException('Store membership and historical data must be preserved. Use a reviewed forward migration.');
    }
};
