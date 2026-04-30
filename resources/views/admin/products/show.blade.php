@extends('layouts.app')

@section('title', $product->name)

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0">{{ $product->name }}</h2>
        <div>
            <a href="{{ route('admin.products.edit', $product) }}" class="btn btn-outline-secondary">Edit</a>
            <a href="{{ route('admin.products.index') }}" class="btn btn-link">Back</a>
        </div>
    </div>

    <dl class="row card card-body">
        <dt class="col-sm-3">Category</dt><dd class="col-sm-9">{{ $product->category?->name }}</dd>
        <dt class="col-sm-3">Price</dt><dd class="col-sm-9">{{ number_format($product->price, 2) }}</dd>
        <dt class="col-sm-3">Stock</dt><dd class="col-sm-9">{{ $product->stock }}</dd>
        <dt class="col-sm-3">Status</dt><dd class="col-sm-9">{{ $product->enabled ? 'Enabled' : 'Disabled' }}</dd>
        <dt class="col-sm-3">Description</dt><dd class="col-sm-9">{{ $product->description ?? '—' }}</dd>
        <dt class="col-sm-3">Created</dt><dd class="col-sm-9">{{ $product->created_at }}</dd>
    </dl>
@endsection
