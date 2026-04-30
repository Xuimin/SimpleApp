<?php

namespace App\Http\Controllers\Admin;

use App\Exports\ProductsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\BulkDeleteProductRequest;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status');
        $categoryId = $request->integer('category_id') ?: null;

        $products = Product::query()
            ->with('category')
            ->forCategory($categoryId)
            ->when($status === 'enabled', fn ($q) => $q->where('enabled', true))
            ->when($status === 'disabled', fn ($q) => $q->where('enabled', false))
            ->when($status === 'trashed', fn ($q) => $q->onlyTrashed())
            ->latest('id')
            ->paginate(5)
            ->withQueryString();

        return view('admin.products.index', [
            'products' => $products,
            'categories' => Category::orderBy('name')->get(),
            'filters' => compact('status', 'categoryId'),
        ]);
    }

    public function create(): View
    {
        return view('admin.products.create', [
            'categories' => Category::orderBy('name')->get(),
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        Product::create($request->validated());

        return redirect()
            ->route('admin.products.index')
            ->with('status', 'Product created.');
    }

    public function show(Product $product): View
    {
        $product->load('category');

        return view('admin.products.show', compact('product'));
    }

    public function edit(Product $product): View
    {
        return view('admin.products.edit', [
            'product' => $product,
            'categories' => Category::orderBy('name')->get(),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $product->update($request->validated());

        return redirect()
            ->route('admin.products.index')
            ->with('status', 'Product updated.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->delete();

        return redirect()
            ->route('admin.products.index')
            ->with('status', 'Product deleted.');
    }

    public function bulkDestroy(BulkDeleteProductRequest $request): RedirectResponse
    {
        $deleted = Product::whereIn('id', $request->validated('ids'))->delete();

        return redirect()
            ->route('admin.products.index')
            ->with('status', "Deleted {$deleted} product(s).");
    }

    public function export(Request $request): BinaryFileResponse
    {
        $categoryId = $request->integer('category_id') ?: null;
        $status = $request->query('status');
        $enabled = match ($status) {
            'enabled' => true,
            'disabled' => false,
            default => null,
        };

        return Excel::download(
            new ProductsExport($categoryId, $enabled),
            'products-'.now()->format('Ymd-His').'.xlsx'
        );
    }
}
