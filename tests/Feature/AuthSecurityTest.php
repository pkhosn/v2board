<?php

namespace Tests\Feature;

use App\Services\AuthService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AuthSecurityTest extends TestCase
{
    public function testArrayAuthDataCannotImpersonateStaff()
    {
        Cache::flush();
        $this->assertFalse(AuthService::decryptAuthData(['is_staff' => '1']));
        $this->assertFalse(AuthService::decryptAuthData(['is_admin' => '1']));
    }

    public function testMalformedAuthDataIsRejected()
    {
        foreach ([null, '', 1, true, new \stdClass(), 'invalid.jwt.token'] as $value) {
            $this->assertFalse(AuthService::decryptAuthData($value));
        }
    }

    public function testStaffRoutesAreDisabled()
    {
        foreach (app('router')->getRoutes() as $route) {
            $this->assertStringStartsNotWith('api/v1/staff/', $route->uri());
        }
        $this->getJson('/api/v1/staff/user/getUserInfoById?auth_data[is_staff]=1&id=1')
            ->assertNotFound();
    }
}
