@extends('layouts.app')

@section('title', 'Edit Product')

@section('content')
    <h2 class="mb-3">Edit Product</h2>
    <form method="POST" action="{{ route('admin.products.update', $product) }}" class="card card-body">
        @method('PUT')
        @include('admin.products._form')
        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary">Save</button>
            <a class="btn btn-link" href="{{ route('admin.products.index') }}">Cancel</a>
        </div>
    </form>
@endsection
