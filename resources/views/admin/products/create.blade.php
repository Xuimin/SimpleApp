@extends('layouts.app')

@section('title', 'New Product')

@section('content')
    <h2 class="mb-3">New Product</h2>
    <form method="POST" action="{{ route('admin.products.store') }}" class="card card-body" id="product-create-form">
        @include('admin.products._form')
        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary" data-submit-button>
                <span class="submit-label">Create</span>
            </button>
            <a class="btn btn-link" href="{{ route('admin.products.index') }}">Cancel</a>
        </div>
    </form>

    <script>
        (function () {
            const form = document.getElementById('product-create-form');
            const btn  = form.querySelector('[data-submit-button]');
            const label = btn.querySelector('.submit-label');

            form.addEventListener('submit', function () {
                btn.disabled = true;
                label.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Creating…';
            });

            // Re-enable if user navigates back via bfcache (e.g. browser Back button).
            window.addEventListener('pageshow', function (e) {
                if (e.persisted) {
                    btn.disabled = false;
                    label.textContent = 'Create';
                }
            });
        })();
    </script>
@endsection
