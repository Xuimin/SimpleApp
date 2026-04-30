<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_belongs_to_category(): void
    {
        $category = Category::factory()->create(['name' => 'Books']);
        $product = Product::factory()->create(['category_id' => $category->id]);

        $this->assertInstanceOf(Category::class, $product->category);
        $this->assertSame('Books', $product->category->name);
    }

    public function test_category_has_many_products(): void
    {
        $category = Category::factory()->create();
        Product::factory()->count(4)->create(['category_id' => $category->id]);

        $this->assertCount(4, $category->fresh()->products);
    }

    public function test_soft_delete_keeps_row_in_db(): void
    {
        $product = Product::factory()->create();
        $product->delete();

        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertNotNull(Product::withTrashed()->find($product->id));
        $this->assertNull(Product::find($product->id));
    }

    public function test_enabled_scope_filters(): void
    {
        Product::factory()->create(['enabled' => true]);
        Product::factory()->create(['enabled' => false]);

        $this->assertSame(1, Product::enabled()->count());
        $this->assertSame(1, Product::enabled(false)->count());
    }

    public function test_for_category_scope_filters(): void
    {
        $cat = Category::factory()->create();
        Product::factory()->create(['category_id' => $cat->id]);
        Product::factory()->count(2)->create();

        $this->assertSame(1, Product::forCategory($cat->id)->count());
        $this->assertSame(3, Product::forCategory(null)->count());
    }
}
