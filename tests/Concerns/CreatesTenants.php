<?php

namespace Tests\Concerns;

use App\Models\Tenant;
use App\Models\User;

trait CreatesTenants
{
    /**
     * @return array{tenant: Tenant, owner: User, admin: User, analyst: User}
     */
    protected function makeTenantWithUsers(string $name = 'Acme Store'): array
    {
        $tenant = Tenant::create([
            'name' => $name,
            'slug' => Tenant::makeUniqueSlug($name),
        ]);

        $owner = User::factory()->forTenant($tenant)->owner()->create();
        $admin = User::factory()->forTenant($tenant)->admin()->create();
        $analyst = User::factory()->forTenant($tenant)->analyst()->create();

        return compact('tenant', 'owner', 'admin', 'analyst');
    }
}
