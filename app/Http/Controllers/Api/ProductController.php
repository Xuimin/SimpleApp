<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BulkDeleteProductRequest;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class ProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = (int) $request->integer('per_page', 15);
        $status = $request->query('status');
        $categoryId = $request->integer('category_id') ?: null;

        $products = Product::query()
            ->with('category')
            ->forCategory($categoryId)
            ->when($status === 'enabled', fn ($q) => $q->where('enabled', true))
            ->when($status === 'disabled', fn ($q) => $q->where('enabled', false))
            ->when($status === 'trashed', fn ($q) => $q->onlyTrashed())
            ->latest('id')
            ->paginate($perPage);

        return ProductResource::collection($products);
    }
    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = Product::create($request->validated())->load('category');

        return (new ProductResource($product))
            ->response()
            ->setStatusCode(201);
    }
    public function show(Product $product): ProductResource
    {
        return new ProductResource($product->load('category'));
    }
    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        $product->update($request->validated());

        return new ProductResource($product->load('category'));
    }
    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json(null, 204);
    }
    public function bulkDestroy(BulkDeleteProductRequest $request): JsonResponse
    {
        $deleted = Product::whereIn('id', $request->validated('ids'))->delete();

        return response()->json(['deleted' => $deleted]);
    }
}
