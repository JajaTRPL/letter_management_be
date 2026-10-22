<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Workflow\WorkflowTestHelpers;
use Tests\TestCase;

/**
 * Guests share a per-IP ceiling high enough for a campus Wi-Fi login wave;
 * signed-in users keep their own per-account ceiling regardless of IP.
 */
class ApiThrottleTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_guest_requests_from_one_ip_allow_600_per_minute(): void
    {
        $server = ['REMOTE_ADDR' => '10.20.30.40'];
        for ($i = 0; $i < 600; $i++) {
            $this->withServerVariables($server)->getJson('/api/auth/config')->assertOk();
        }

        $this->withServerVariables($server)->getJson('/api/auth/config')->assertStatus(429);
        $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.41'])->getJson('/api/auth/config')->assertOk();
    }

    public function test_signed_in_users_behind_one_ip_have_separate_limits(): void
    {
        $server = ['REMOTE_ADDR' => '10.20.30.40'];
        $first = $this->activeUser(['role' => 'mahasiswa'])->createToken('t')->plainTextToken;
        $second = $this->activeUser(['role' => 'mahasiswa'])->createToken('t')->plainTextToken;

        for ($i = 0; $i < 100; $i++) {
            $this->withServerVariables($server)->withToken($first)->getJson('/api/profile')->assertOk();
        }
        $this->withServerVariables($server)->withToken($first)->getJson('/api/profile')->assertStatus(429);

        $this->app['auth']->forgetGuards();
        $this->withServerVariables($server)->withToken($second)->getJson('/api/profile')->assertOk();
    }
}
