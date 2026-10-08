<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\MigratesRoomChartTestSchema;
use Tests\TestCase;

class AuthLoginSecurityTest extends TestCase
{
    use MigratesRoomChartTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateRoomChartTestSchema();

        if (! Schema::hasTable('login_attempts')) {
            Schema::create('login_attempts', function (Blueprint $table) {
                $table->id();
                $table->string('email');
                $table->boolean('successful')->default(false);
                $table->string('ip_address')->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
        if (! Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function (Blueprint $table) {
                $table->id();
                $table->morphs('tokenable');
                $table->string('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('departments')) {
            Schema::create('departments', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('department_user')) {
            Schema::create('department_user', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('department_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('restaurant_user')) {
            Schema::create('restaurant_user', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('restaurant_master_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamps();
            });
        }

        RateLimiter::clear('login:desk@hotel.test|127.0.0.1');
        RateLimiter::clear('login-ip:127.0.0.1');
        User::factory()->create(['email' => 'desk@hotel.test', 'password' => Hash::make('right-password')]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function login(string $password): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/login', [
            'email' => 'desk@hotel.test',
            'password' => $password,
            'device_name' => 'web_app',
        ]);
    }

    public function test_locks_account_from_ip_after_five_failed_attempts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login('wrong')->assertStatus(422);
        }

        $this->login('right-password')
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_successful_login_resets_the_account_counter(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->login('wrong')->assertStatus(422);
        }
        $this->login('right-password')->assertOk();

        for ($i = 0; $i < 4; $i++) {
            $this->login('wrong')->assertStatus(422);
        }
        $this->login('right-password')->assertOk();
    }

    public function test_token_expires_after_seven_days(): void
    {
        $token = $this->login('right-password')->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/me')->assertOk();

        Carbon::setTestNow(now()->addDays(7)->addMinute());
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
    }
}
