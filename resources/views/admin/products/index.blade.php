@extends('layouts.app')

@section('title', 'Products')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0">Products</h2>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-success" href="{{ route('admin.products.export', request()->only('status', 'category_id')) }}">Export Excel</a>
            <a class="btn btn-primary" href="{{ route('admin.products.create') }}">+ New Product</a>
        </div>
    </div>

    <form method="GET" class="row g-2 mb-3 align-items-end">
        <div class="col-md-3">
            <label class="form-label small">Status</label>
            <select name="status" class="form-select">
                <option value="">All</option>
                <option value="enabled" @selected(($filters['status'] ?? '') === 'enabled')>Enabled</option>
                <option value="disabled" @selected(($filters['status'] ?? '') === 'disabled')>Disabled</option>
                <option value="trashed" @selected(($filters['status'] ?? '') === 'trashed')>Trashed</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small">Category</label>
            <select name="category_id" class="form-select">
                <option value="">All categories</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected(($filters['categoryId'] ?? null) == $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <button class="btn btn-outline-primary">Filter</button>
            <a class="btn btn-link" href="{{ route('admin.products.index') }}">Reset</a>
        </div>
    </form>

    {{-- Bulk-delete form lives outside the table; row checkboxes are linked to it via the HTML5 `form` attribute. --}}
    <form method="POST" action="{{ route('admin.products.bulk-destroy') }}" id="bulk-form" onsubmit="return confirm('Delete selected products?')">
        @csrf
        @method('DELETE')
    </form>

    <div class="card">
        <div class="card-body p-0">
            <table class="table table-striped table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th style="width: 32px;"><input type="checkbox" id="check-all" form="bulk-form"></th>
                        <th>Name</th>
                        <th>Category</th>
                        <th class="text-end">Price</th>
                        <th class="text-end">Stock</th>
                        <th>Status</th>
                        <th class="text-end" style="width: 200px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($products as $product)
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="{{ $product->id }}" class="row-check" form="bulk-form"></td>
                        <td>
                            <a href="{{ route('admin.products.show', $product) }}">{{ $product->name }}</a>
                        </td>
                        <td>{{ $product->category?->name ?? '—' }}</td>
                        <td class="text-end">{{ number_format($product->price, 2) }}</td>
                        <td class="text-end">{{ $product->stock }}</td>
                        <td>
                            @if ($product->trashed())
                                <span class="badge bg-secondary">Trashed</span>
                            @elseif ($product->enabled)
                                <span class="badge bg-success">Enabled</span>
                            @else
                                <span class="badge bg-warning text-dark">Disabled</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.products.edit', $product) }}">Edit</a>
                            <form action="{{ route('admin.products.destroy', $product) }}" method="POST" class="d-inline" onsubmit="return confirm('Delete this product?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No products found.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mt-3">
        <button type="submit" form="bulk-form" class="btn btn-danger">Delete selected</button>
        <div>{{ $products->links() }}</div>
    </div>

    <script>
        document.getElementById('check-all').addEventListener('change', function (e) {
            document.querySelectorAll('.row-check').forEach(cb => cb.checked = e.target.checked);
        });
    </script>
@endsection
