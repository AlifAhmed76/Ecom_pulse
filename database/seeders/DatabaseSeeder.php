<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $demoStore = Tenant::firstOrCreate(
            ['slug' => 'demo-store'],
            ['name' => 'Demo Store']
        );

        $otherCompany = Tenant::firstOrCreate(
            ['slug' => 'other-company'],
            ['name' => 'Other Company']
        );

        $this->seedUser($demoStore, 'Demo Owner', 'owner@demostore.test', 'DemoStore123', User::ROLE_OWNER);
        $this->seedUser($demoStore, 'Demo Analyst', 'analyst@demostore.test', 'DemoStore123', User::ROLE_ANALYST);
        $this->seedUser($otherCompany, 'Other Owner', 'owner@othercompany.test', 'OtherCompany123', User::ROLE_OWNER);
    }

    private function seedUser(Tenant $tenant, string $name, string $email, string $password, string $role): void
    {
        $user = User::where('email', $email)->first() ?? new User();
        $user->forceFill([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => $role,
            'email_verified_at' => now(),
        ])->save();
    }
}
