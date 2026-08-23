<?php

namespace Tests\Feature\Users;

use App\Mail\RegistrationOtpMail;
use App\Models\Department;
use App\Models\RegistrationOtpCode;
use App\Models\Setting;
use App\Models\User;
use App\Services\Users\UserImportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class UserOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        // Settings reads are cached forever per key; RefreshDatabase wipes the
        // table but not the cache, so stale values leak between tests.
        Setting::query()->delete();
        \Artisan::call('cache:clear');
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    // ------------------------------------------------------------------
    // UO.3 domain allow-list + UO.2 activation modes on real registration
    // ------------------------------------------------------------------

    public function test_registration_is_blocked_for_disallowed_domain_and_allowed_for_listed_one(): void
    {
        Setting::put('auth.allow_registration', 'true');
        Setting::put('auth.allowed_registration_domains', "BlueChipAnalytical.com\n");

        $blocked = $this->post('/register', [
            'name' => 'Outside Olive',
            'email' => 'olive@gmail.com',
            'password' => 'Sup3r-Secret-Pass!',
            'password_confirmation' => 'Sup3r-Secret-Pass!',
        ]);
        $blocked->assertSessionHasErrors(['email' => __('Please register with your organization email address.')]);
        $this->assertDatabaseMissing('users', ['email' => 'olive@gmail.com']);

        $allowed = $this->post('/register', [
            'name' => 'Inside Ivy',
            'email' => 'ivy@bluechipanalytical.com',
            'password' => 'Sup3r-Secret-Pass!',
            'password_confirmation' => 'Sup3r-Secret-Pass!',
        ]);
        $allowed->assertRedirect(route('registration.confirm.show'));
        $this->assertDatabaseHas('users', ['email' => 'ivy@bluechipanalytical.com']);
    }

    public function test_otp_mode_sends_code_and_blocks_signin_until_confirmed(): void
    {
        Mail::fake();
        Setting::put('auth.allow_registration', 'true');
        Setting::put('auth.registration_mode', 'otp');

        $response = $this->post('/register', [
            'name' => 'Pending Pat',
            'email' => 'pat@bluechipanalytical.com',
            'password' => 'Sup3r-Secret-Pass!',
            'password_confirmation' => 'Sup3r-Secret-Pass!',
        ]);
        $response->assertRedirect(route('registration.confirm.show'));
        $this->assertGuest();

        $user = User::query()->where('email', 'pat@bluechipanalytical.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);

        Mail::assertSent(RegistrationOtpMail::class);

        $login = $this->post(route('login'), [
            'email' => 'pat@bluechipanalytical.com',
            'password' => 'Sup3r-Secret-Pass!',
        ]);
        $login->assertRedirect(route('registration.confirm.show'));
        $this->assertGuest();

        RegistrationOtpCode::query()->where('email', $user->email)->delete();
        RegistrationOtpCode::create([
            'email' => $user->email,
            'code_hash' => Hash::make('246810'),
            'expires_at' => now()->addMinutes(10),
        ]);

        $verify = $this->withSession(['registration.confirm.email' => $user->email])
            ->post(route('registration.confirm.verify'), ['code' => '246810']);
        $verify->assertRedirect(route('login'));
        $this->assertNotNull($user->refresh()->email_verified_at);

        Mail::fake();
        $this->post('/register', [
            'name' => 'Wrong Way',
            'email' => 'wrong@bluechipanalytical.com',
            'password' => 'Sup3r-Secret-Pass!',
            'password_confirmation' => 'Sup3r-Secret-Pass!',
        ]);
        RegistrationOtpCode::query()->where('email', 'wrong@bluechipanalytical.com')->delete();
        RegistrationOtpCode::create([
            'email' => 'wrong@bluechipanalytical.com',
            'code_hash' => Hash::make('111111'),
            'expires_at' => now()->addMinutes(10),
        ]);
        $bad = $this->withSession(['registration.confirm.email' => 'wrong@bluechipanalytical.com'])
            ->post(route('registration.confirm.verify'), ['code' => '999999']);
        $bad->assertSessionHasErrors('code');
    }

    public function test_immediate_mode_verifies_at_registration(): void
    {
        Mail::fake();
        Setting::put('auth.allow_registration', 'true');
        Setting::put('auth.registration_mode', 'immediate');

        $response = $this->post('/register', [
            'name' => 'Quick Quinn',
            'email' => 'quinn@bluechipanalytical.com',
            'password' => 'Sup3r-Secret-Pass!',
            'password_confirmation' => 'Sup3r-Secret-Pass!',
        ]);
        $response->assertRedirect(config('fortify.home'));

        $user = User::query()->where('email', 'quinn@bluechipanalytical.com')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_settings_page_saves_activation_mode_and_domains(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)->test('pages::settings.branding')
            ->set('allow_registration', true)
            ->set('registration_mode', 'otp')
            ->set('allowed_registration_domains', "BlueChipAnalytical.com,\nmail.corp.example,,")
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('otp', Setting::get('auth.registration_mode'));
        $domains = (string) Setting::get('auth.allowed_registration_domains');
        $this->assertSame("bluechipanalytical.com\nmail.corp.example", $domains);
    }

    public function test_settings_rejects_invalid_domain_entries(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)->test('pages::settings.branding')
            ->set('allow_registration', true)
            ->set('allowed_registration_domains', "bluechipanalytical.com\nnot a domain")
            ->call('save')
            ->assertHasErrors('allowed_registration_domains');
    }

    // ------------------------------------------------------------------
    // UO.1 CSV/Excel import
    // ------------------------------------------------------------------

    public function test_import_dry_run_reports_problems_then_commit_creates_users(): void
    {
        $admin = $this->admin();
        Department::factory()->create(['name' => 'Operations']);
        User::factory()->create(['email' => 'boss@bluechipanalytical.com']);

        // A duplicate, an unknown department, and two good rows (one manager-first).
        $csv = "name,email,department,manager_email,phone,roles\n"
            ."Boss Man,boss@bluechipanalytical.com,,,,submitter\n"
            ."Good Goer,good@bluechipanalytical.com,Operations,boss@bluechipanalytical.com,+2348012345678,submitter\n"
            ."Dup Dan,dup@bluechipanalytical.com,,,,submitter\n"
            ."Bad Dept,bad@bluechipanalytical.com,Nope,,,\n"
            ."Dup Again,dup@bluechipanalytical.com,,,,submitter\n";
        $uploaded = UploadedFile::fake()->createWithContent('users.csv', $csv);

        $component = Livewire::actingAs($admin)->test('pages::admin.users-import')
            ->set('file', $uploaded)
            ->call('analyze')
            ->assertHasNoErrors();

        $counts = $component->instance()->counts();
        $this->assertSame(1, $counts['duplicates']);
        $this->assertSame(1, $counts['errors']);

        // Nothing written by the dry run.
        $this->assertDatabaseMissing('users', ['email' => 'good@bluechipanalytical.com']);

        $component->set('duplicateMode', 'skip')->call('commit')->assertHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'good@bluechipanalytical.com']);
        $this->assertDatabaseMissing('users', ['email' => 'bad@bluechipanalytical.com']);
        // The second "Dup Again" row is also skipped: its email was created
        // by the first "Dup Dan" row earlier in this same import.
        $result = $component->get('result');
        $this->assertSame(2, $result['created']);
        $this->assertSame(2, $result['skipped']);

        // The created user reports to the pre-existing manager.
        $goer = User::query()->where('email', 'good@bluechipanalytical.com')->first();
        $this->assertSame(User::query()->where('email', 'boss@bluechipanalytical.com')->value('id'), $goer->manager_id);
        $this->assertNotNull($goer->email_verified_at);

    }

    public function test_xlsx_import_creates_users(): void
    {
        $admin = $this->admin();
        Department::factory()->create(['name' => 'Finance']);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([['name', 'email', 'department', 'manager_email', 'phone', 'roles'], ['Exel Ella', 'ella@corp.example', 'Finance', '', '', 'submitter']]);
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        // Parse + analyze + commit directly through the service so the
        // spreadsheet binary is exercised exactly as an upload would be.
        $service = app(UserImportService::class);
        $rows = $service->rows($path, 'xlsx');
        $analyzed = $service->analyze($rows, $admin);
        $counts = $service->commit($analyzed, $admin);

        $this->assertSame(1, $counts['created']);
        $this->assertDatabaseHas('users', ['email' => 'ella@corp.example']);
        unlink($path);
    }

    public function test_import_page_requires_user_management_permission(): void
    {
        $viewer = User::factory()->create()->assignRole('viewer');

        $this->actingAs($viewer)->get(route('admin.users.import'))->assertForbidden();
        $this->actingAs($this->admin())->get(route('admin.users.import'))->assertOk();
    }

    public function test_template_download_returns_csv_with_headers(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.users.import.template'));
        $response->assertOk();

        $this->assertStringContainsString('name,email,department,manager_email,phone,roles', (string) $response->getContent());
    }

    public function test_prune_command_removes_stale_unconfirmed_accounts_only(): void
    {
        $stale = User::factory()->create(['email_verified_at' => null]);
        DB::table('users')->where('id', $stale->id)->update(['created_at' => now()->subDays(5)]);
        $fresh = User::factory()->create(['email_verified_at' => null]);
        $verified = User::factory()->create();

        $this->artisan('users:prune-unconfirmed')->assertSuccessful();

        $this->assertDatabaseMissing('users', ['id' => $stale->id]);
        $this->assertDatabaseHas('users', ['id' => $fresh->id]);
        $this->assertDatabaseHas('users', ['id' => $verified->id]);
    }
}
