<?php

namespace Tests\Feature;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('_tenant_test_items');
        Schema::create('_tenant_test_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->timestamps();
        });

        Route::middleware(['web', 'auth'])->get('/_test/tenant-items/{item}', function (TenantTestItem $item) {
            return response()->json(['id' => $item->id, 'tenant_id' => $item->tenant_id, 'label' => $item->label]);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('_tenant_test_items');
        parent::tearDown();
    }

    public function test_other_tenant_gets_404_for_tenant_owned_model_and_same_tenant_gets_200(): void
    {
        $tenantA = $this->makeTenantWithUsers('Alpha Retail');
        $tenantB = $this->makeTenantWithUsers('Beta Fashion');

        $itemA = new TenantTestItem(['label' => 'Alpha Secret']);
        $itemA->forceFill(['tenant_id' => $tenantA['tenant']->id])->save();

        $itemB = new TenantTestItem(['label' => 'Beta Secret']);
        $itemB->forceFill(['tenant_id' => $tenantB['tenant']->id])->save();

        $this->actingAs($tenantA['owner'])
            ->get('/_test/tenant-items/'.$itemA->id)
            ->assertOk()
            ->assertJson(['id' => $itemA->id, 'tenant_id' => $tenantA['tenant']->id]);

        $this->actingAs($tenantA['owner'])
            ->get('/_test/tenant-items/'.$itemB->id)
            ->assertNotFound();

        $this->actingAs($tenantB['owner'])
            ->get('/_test/tenant-items/'.$itemA->id)
            ->assertNotFound();
    }

    public function test_belongs_to_tenant_scopes_queries_and_assigns_tenant_on_create(): void
    {
        $tenantA = $this->makeTenantWithUsers('Store One');
        $tenantB = $this->makeTenantWithUsers('Store Two');

        $this->actingAs($tenantA['owner']);
        $created = TenantTestItem::create([
            'label' => 'Auto Scoped Item',
            'tenant_id' => $tenantB['tenant']->id, // ignored because not fillable + creating hook sets tenant A
        ]);
        $this->assertSame($tenantA['tenant']->id, $created->tenant_id);

        $itemB = new TenantTestItem(['label' => 'Store Two Only']);
        $itemB->forceFill(['tenant_id' => $tenantB['tenant']->id])->save();

        $visible = TenantTestItem::all();
        $this->assertCount(1, $visible);
        $this->assertSame('Auto Scoped Item', $visible->first()->label);

        $this->actingAs($tenantB['owner']);
        $visibleB = TenantTestItem::all();
        $this->assertCount(1, $visibleB);
        $this->assertSame('Store Two Only', $visibleB->first()->label);
    }

    public function test_workspace_member_list_never_leaks_other_tenant_users(): void
    {
        $tenantA = $this->makeTenantWithUsers('First Co');
        $tenantB = $this->makeTenantWithUsers('Second Co');

        $this->actingAs($tenantA['owner'])
            ->get('/workspace')
            ->assertOk()
            ->assertSee($tenantA['analyst']->email)
            ->assertDontSee($tenantB['owner']->email)
            ->assertDontSee($tenantB['analyst']->email);
    }

    public function test_database_seeder_creates_demo_store_and_other_company_cleanly(): void
    {
        $this->seed();

        $this->assertDatabaseHas('tenants', ['slug' => 'demo-store']);
        $this->assertDatabaseHas('tenants', ['slug' => 'other-company']);
        $this->assertDatabaseHas('users', ['email' => 'owner@demostore.test', 'role' => 'owner']);
        $this->assertDatabaseHas('users', ['email' => 'analyst@demostore.test', 'role' => 'analyst']);
        $this->assertDatabaseHas('users', ['email' => 'owner@othercompany.test', 'role' => 'owner']);
    }
}

class TenantTestItem extends Model
{
    use BelongsToTenant;

    protected $table = '_tenant_test_items';

    protected $fillable = ['label'];
}
