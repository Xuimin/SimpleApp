<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    private function asAdmin(): User
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/products')->assertUnauthorized();
    }

    public function test_non_admin_is_forbidden(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/products')->assertForbidden();
    }

    public function test_admin_can_list_products_with_category(): void
    {
        $this->asAdmin();
        $category = Category::factory()->create(['name' => 'Tools']);
        Product::factory()->count(3)->create(['category_id' => $category->id]);

        $response = $this->getJson('/api/products');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.category.id', $category->id)
            ->assertJsonPath('data.0.category.name', 'Tools');
    }

    public function test_admin_can_filter_products_by_category(): void
    {
        $this->asAdmin();
        $cat1 = Category::factory()->create();
        $cat2 = Category::factory()->create();
        Product::factory()->count(2)->create(['category_id' => $cat1->id]);
        Product::factory()->count(3)->create(['category_id' => $cat2->id]);

        $this->getJson('/api/products?category_id='.$cat2->id)
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_admin_can_create_product(): void
    {
        $this->asAdmin();
        $category = Category::factory()->create();

        $payload = [
            'name' => 'Test Product',
            'category_id' => $category->id,
            'description' => 'desc',
            'price' => 12.50,
            'stock' => 5,
            'enabled' => true,
        ];

        $this->postJson('/api/products', $payload)
            ->assertCreated()
            ->assertJsonPath('data.name', 'Test Product')
            ->assertJsonPath('data.category.id', $category->id);

        $this->assertDatabaseHas('products', ['name' => 'Test Product', 'price' => 12.50]);
    }

    public function test_create_validates_required_fields(): void
    {
        $this->asAdmin();

        $this->postJson('/api/products', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'category_id', 'price', 'stock']);
    }

    public function test_admin_can_update_product(): void
    {
        $this->asAdmin();
        $product = Product::factory()->create(['name' => 'Old']);

        $this->putJson('/api/products/'.$product->id, ['name' => 'New'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New');
    }

    public function test_admin_can_soft_delete_product(): void
    {
        $this->asAdmin();
        $product = Product::factory()->create();

        $this->deleteJson('/api/products/'.$product->id)->assertNoContent();

        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_admin_can_bulk_delete_products(): void
    {
        $this->asAdmin();
        $products = Product::factory()->count(3)->create();
        $ids = $products->pluck('id')->all();

        $this->deleteJson('/api/products/bulk', ['ids' => $ids])
            ->assertOk()
            ->assertJson(['deleted' => 3]);

        foreach ($ids as $id) {
            $this->assertSoftDeleted('products', ['id' => $id]);
        }
    }

    public function test_login_returns_token(): void
    {
        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('secret123'),
            'is_admin' => true,
        ]);

        $this->postJson('/api/login', [
            'email' => 'admin@example.com',
            'password' => 'secret123',
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'email', 'is_admin']]);
    }
}
