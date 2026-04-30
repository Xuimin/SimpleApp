<?php

namespace Tests\Feature;

use App\Exports\ProductsExport;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class ProductAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_guest_redirected_to_login(): void
    {
        $this->get('/admin/products')->assertRedirect('/login');
    }

    public function test_non_admin_user_is_forbidden(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get('/admin/products')->assertForbidden();
    }

    public function test_admin_can_view_index(): void
    {
        Product::factory()->count(2)->create();

        $this->actingAs($this->admin())
            ->get('/admin/products')
            ->assertOk()
            ->assertViewIs('admin.products.index');
    }

    public function test_admin_can_filter_by_status(): void
    {
        $category = Category::factory()->create();
        Product::factory()->create(['name' => 'Live', 'category_id' => $category->id, 'enabled' => true]);
        Product::factory()->create(['name' => 'Off', 'category_id' => $category->id, 'enabled' => false]);

        $this->actingAs($this->admin())
            ->get('/admin/products?status=enabled')
            ->assertOk()
            ->assertSee('Live')
            ->assertDontSee('Off');
    }

    public function test_admin_can_create_via_web_form(): void
    {
        $category = Category::factory()->create();

        $this->actingAs($this->admin())
            ->post('/admin/products', [
                'name' => 'WebProduct',
                'category_id' => $category->id,
                'price' => 9.99,
                'stock' => 10,
                'enabled' => '1',
            ])
            ->assertRedirect('/admin/products');

        $this->assertDatabaseHas('products', ['name' => 'WebProduct']);
    }

    public function test_excel_export_route_returns_download(): void
    {
        Product::factory()->count(2)->create();

        $response = $this->actingAs($this->admin())->get('/admin/products/export');

        $response->assertOk();
        $disposition = $response->headers->get('content-disposition');
        $this->assertStringContainsString('attachment', (string) $disposition);
        $this->assertStringContainsString('products-', (string) $disposition);
    }

    public function test_excel_export_writes_zero_stock_as_zero_not_blank(): void
    {
        Product::factory()->create(['name' => 'OutOfStock', 'stock' => 0]);
        Product::factory()->create(['name' => 'InStock', 'stock' => 7]);

        $rows = Excel::raw(new ProductsExport(), \Maatwebsite\Excel\Excel::XLSX);
        $sheet = (new \PhpOffice\PhpSpreadsheet\Reader\Xlsx())
            ->load($this->writeTempXlsx($rows))
            ->getActiveSheet()
            ->toArray();

        $stockColumn = array_column($sheet, 5);   // 0-indexed: ID,Name,Category,Description,Price,Stock,...
        $names = array_column($sheet, 1);

        $zeroRow = array_search('OutOfStock', $names, true);
        $sevenRow = array_search('InStock', $names, true);

        $this->assertNotFalse($zeroRow, 'OutOfStock row missing from export');
        $this->assertSame(0, (int) $stockColumn[$zeroRow], 'stock=0 should render as 0, not blank');
        $this->assertSame(7, (int) $stockColumn[$sevenRow]);
    }

    private function writeTempXlsx(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'export_').'.xlsx';
        file_put_contents($path, $bytes);

        return $path;
    }

    public function test_admin_can_delete_a_single_product_via_row_button(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin())
            ->delete('/admin/products/'.$product->id)
            ->assertRedirect('/admin/products');

        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_index_page_does_not_nest_per_row_delete_inside_bulk_form(): void
    {
        Product::factory()->count(2)->create();

        $html = $this->actingAs($this->admin())->get('/admin/products')->getContent();

        $bulkOpen = strpos($html, 'id="bulk-form"');
        $bulkClose = strpos($html, '</form>', $bulkOpen);
        $rowDeleteAction = strpos($html, 'admin/products/'.Product::first()->id);

        $this->assertNotFalse($bulkOpen, 'bulk-form not found');
        $this->assertNotFalse($bulkClose, 'bulk-form closing tag not found');
        $this->assertNotFalse($rowDeleteAction, 'row delete form not found');
        $this->assertGreaterThan(
            $bulkClose,
            $rowDeleteAction,
            'row delete form must NOT be nested inside the bulk-delete form (HTML5 forbids nested forms).'
        );
    }

    public function test_bulk_delete_via_web(): void
    {
        $products = Product::factory()->count(2)->create();

        $this->actingAs($this->admin())
            ->delete('/admin/products/bulk', ['ids' => $products->pluck('id')->all()])
            ->assertRedirect('/admin/products');

        foreach ($products as $p) {
            $this->assertSoftDeleted('products', ['id' => $p->id]);
        }
    }
}
