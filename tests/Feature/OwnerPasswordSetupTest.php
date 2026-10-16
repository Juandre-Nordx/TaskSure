<?php

namespace Tests\Feature;

use App\Models\AccountAudit;
use App\Models\Category;
use App\Models\Company;
use App\Models\Store;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OwnerPasswordSetupTest extends TestCase
{
    use RefreshDatabase;

    private User $platform;

    private User $owner;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->platform = User::create(['name' => 'Platform', 'email' => 'platform@passwords.test', 'role' => 'super_admin', 'active' => true, 'password' => 'PlatformPassword123!']);
        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@passwords.test', 'role' => 'admin', 'active' => true, 'password' => 'OriginalOwnerPassword123!']);
        $this->store = Store::findOrFail($this->owner->storeId());
        Notification::fake();
        config(['mail.default' => 'smtp']);
    }

    private function creation(array $extra = []): array
    {
        return $extra + ['name' => 'New branch', 'company_id' => $this->store->company_id, 'owner_name' => 'New owner', 'owner_email' => 'new-owner@passwords.test', 'active' => 1];
    }

    private function password(array $extra = []): array
    {
        return $extra + ['owner_id' => $this->owner->id, 'password' => 'NewOwnerPassword123!', 'password_confirmation' => 'NewOwnerPassword123!'];
    }

    private function endpoint(): string
    {
        return '/super-admin/stores/'.$this->store->id.'/owner-password';
    }

    public function test_initial_password_is_hashed_and_new_owner_can_sign_in_without_email(): void
    {
        $this->actingAs($this->platform)->post('/super-admin/stores', $this->creation(['password' => 'InitialOwnerPassword123!', 'password_confirmation' => 'InitialOwnerPassword123!']))->assertRedirect('/super-admin')->assertSessionHasNoErrors()->assertSessionHas('success', fn ($message) => str_contains($message, 'supplied password'));
        $owner = User::where('email', 'new-owner@passwords.test')->sole();
        $store = Store::where('name', 'New branch')->sole();
        $this->assertSame('admin', $owner->role);
        $this->assertTrue(Hash::check('InitialOwnerPassword123!', $owner->password));
        $this->assertNotSame('InitialOwnerPassword123!', $owner->password);
        $this->assertTrue($owner->inStore($store->id));
        $this->assertStringNotContainsString('InitialOwnerPassword123!', AccountAudit::all()->toJson());
        $this->get('/super-admin/stores/'.$store->id.'/edit')->assertOk()->assertDontSee('InitialOwnerPassword123!')->assertDontSee($owner->password);
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->post('/logout');
        $this->post('/login', ['email' => $owner->email, 'password' => 'InitialOwnerPassword123!'])->assertRedirect('/');
        $this->get('/')->assertOk()->assertSee('New branch');
    }

    public function test_blank_initial_password_keeps_the_secure_email_setup_flow(): void
    {
        $this->actingAs($this->platform)->post('/super-admin/stores', $this->creation(['password' => '', 'password_confirmation' => '']))->assertRedirect('/super-admin')->assertSessionHasNoErrors();
        $owner = User::where('email', 'new-owner@passwords.test')->sole();
        $token = null;
        Notification::assertSentTo($owner, ResetPassword::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });
        $this->assertNotNull($token);
        $this->post('/logout');
        $this->post('/reset-password', ['email' => $owner->email, 'token' => $token, 'password' => 'EmailSetupPassword123!', 'password_confirmation' => 'EmailSetupPassword123!'])->assertRedirect('/login');
        $this->post('/login', ['email' => $owner->email, 'password' => 'EmailSetupPassword123!'])->assertRedirect('/');
    }

    public function test_invalid_initial_passwords_do_not_create_accounts_or_leak_into_old_input(): void
    {
        $counts = [User::count(), Store::count(), AccountAudit::count()];
        foreach ([['short', 'short'], ['NoNumbersHere!', 'NoNumbersHere!'], ['NewOwnerPassword123!', 'DifferentPassword123!'], ['', 'NewOwnerPassword123!'], [str_repeat('a', 73).'A1', str_repeat('a', 73).'A1'], [str_repeat('é', 36).'A1', str_repeat('é', 36).'A1'], ["PasswordWithNull123!\0", "PasswordWithNull123!\0"]] as [$password, $confirmation]) {
            $this->actingAs($this->platform)->from('/super-admin/stores/create')->post('/super-admin/stores', $this->creation(['password' => $password, 'password_confirmation' => $confirmation]))->assertRedirect('/super-admin/stores/create')->assertSessionHasErrors('password')->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.password_confirmation');
            $this->assertSame($counts, [User::count(), Store::count(), AccountAudit::count()]);
        }
    }

    public function test_assigning_an_existing_owner_preserves_password_and_rejects_inline_replacement(): void
    {
        $hash = $this->owner->password;
        $fields = ['name' => 'Assigned branch', 'company_id' => $this->store->company_id, 'owner_id' => $this->owner->id, 'active' => 1];
        $this->actingAs($this->platform)->post('/super-admin/stores', $fields)->assertRedirect('/super-admin');
        $this->postJson('/super-admin/stores', $fields + ['password' => 'NewOwnerPassword123!', 'password_confirmation' => 'NewOwnerPassword123!'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertSame($hash, $this->owner->fresh()->password);
        $this->assertSame(1, Store::where('name', 'Assigned branch')->count());
        Notification::assertNothingSent();
    }

    public function test_setting_existing_owner_password_preserves_identity_memberships_tasks_and_other_accounts(): void
    {
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@passwords.test', 'role' => 'employee', 'active' => true, 'password' => 'OriginalStaffPassword123!']);
        $category = Category::create(['name' => 'Stock']);
        $task = Task::create(['employee_id' => $staff->id, 'creator_id' => $this->owner->id, 'category_id' => $category->id, 'title' => 'Preserved work', 'instructions' => 'Original work', 'area' => 'Stock', 'priority' => 'normal', 'required_evidence' => ['note'], 'due_at' => now()->addDay()]);
        $otherCompany = Company::create(['name' => 'Another company']);
        $secondStore = $otherCompany->stores()->firstOrFail();
        $this->owner->stores()->attach($secondStore, ['active' => true]);
        $this->owner->createToken('Old owner session', ['mobile']);
        $staff->createToken('Staff session', ['mobile']);
        foreach ([$this->owner, $staff] as $person) {
            DB::table('sessions')->insert(['id' => 'session-'.$person->id, 'user_id' => $person->id, 'payload' => 'original', 'last_activity' => now()->timestamp]);
            DB::table('password_reset_tokens')->insert(['email' => $person->email, 'token' => 'original-reset-token', 'created_at' => now()]);
        }
        $before = ['owner' => $this->owner->fresh()->only(['id', 'name', 'email', 'role', 'active', 'company_id']), 'staff' => $staff->fresh()->getAttributes(), 'task' => $task->fresh()->getAttributes(), 'store' => $this->store->fresh()->getAttributes(), 'memberships' => $this->owner->stores()->orderBy('stores.id')->pluck('stores.id')->all()];
        $this->actingAs($this->platform)->post($this->endpoint(), $this->password())->assertRedirect('/super-admin/stores/'.$this->store->id.'/edit')->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('NewOwnerPassword123!', $this->owner->fresh()->password));
        $this->assertFalse(Hash::check('OriginalOwnerPassword123!', $this->owner->fresh()->password));
        $this->assertSame($before['owner'], $this->owner->fresh()->only(array_keys($before['owner'])));
        $this->assertSame($before['staff'], $staff->fresh()->getAttributes());
        $this->assertSame($before['task'], $task->fresh()->getAttributes());
        $this->assertSame($before['store'], $this->store->fresh()->getAttributes());
        $this->assertSame($before['memberships'], $this->owner->stores()->orderBy('stores.id')->pluck('stores.id')->all());
        $this->assertSame(0, $this->owner->tokens()->count());
        $this->assertSame(1, $staff->tokens()->count());
        $this->assertDatabaseMissing('sessions', ['user_id' => $this->owner->id]);
        $this->assertDatabaseHas('sessions', ['user_id' => $staff->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $this->owner->email]);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $staff->email]);
        $audit = AccountAudit::where('action', 'platform_owner_password_set')->sole();
        $this->assertSame($this->platform->id, $audit->actor_id);
        $this->assertSame($this->owner->id, $audit->subject_id);
        $this->assertSame($this->store->id, $audit->store_id);
        $this->assertNull($audit->data);
        Notification::assertNothingSent();
        $this->post('/logout');
        $this->post('/login', ['email' => $this->owner->email, 'password' => 'NewOwnerPassword123!'])->assertRedirect('/');
        $this->get('/')->assertOk();
    }

    public function test_only_super_admin_can_set_owner_passwords(): void
    {
        $hash = $this->owner->password;
        $this->post($this->endpoint(), $this->password())->assertRedirect('/login');
        foreach (['admin', 'manager', 'employee'] as $role) {
            $person = $role === 'admin' ? $this->owner : User::create(['name' => $role, 'email' => $role.'@passwords.test', 'role' => $role, 'active' => true, 'password' => 'OriginalPassword123!']);
            $this->actingAs($person)->post($this->endpoint(), $this->password())->assertForbidden();
        }
        $this->assertSame($hash, $this->owner->fresh()->password);
        $this->assertDatabaseCount('account_audits', 0);
    }

    public function test_stale_owner_forms_cannot_reset_a_reassigned_owners_password(): void
    {
        $replacement = User::create(['name' => 'Replacement', 'email' => 'replacement@passwords.test', 'role' => 'admin', 'active' => true, 'password' => 'ReplacementPassword123!']);
        $hashes = [$this->owner->password, $replacement->password];
        $this->store->update(['owner_id' => $replacement->id]);
        $this->actingAs($this->platform)->postJson($this->endpoint(), $this->password())->assertStatus(409);
        $this->assertSame($hashes, [$this->owner->fresh()->password, $replacement->fresh()->password]);
        $this->assertDatabaseCount('account_audits', 0);
    }

    public function test_reset_requires_valid_password_and_cannot_promote_or_target_another_user(): void
    {
        $hash = $this->owner->password;
        $this->actingAs($this->platform)->post($this->endpoint(), $this->password(['password_confirmation' => 'MismatchPassword123!']))->assertSessionHasErrors('password')->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.password_confirmation');
        $this->postJson($this->endpoint(), $this->password(['user_id' => $this->platform->id, 'role' => 'super_admin', 'company_id' => 999]))->assertUnprocessable();
        $this->postJson($this->endpoint(), $this->password(['owner_id' => $this->platform->id]))->assertStatus(409);
        $this->assertSame($hash, $this->owner->fresh()->password);
        $this->assertSame('admin', $this->owner->fresh()->role);
        $this->assertDatabaseCount('account_audits', 0);
        $this->owner->update(['active' => false]);
        $this->postJson($this->endpoint(), $this->password())->assertUnprocessable();
        $this->assertSame($hash, $this->owner->fresh()->password);
    }
}
