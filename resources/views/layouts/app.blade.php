<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="{{ route('admin.products.index') }}">{{ config('app.name') }}</a>
        @auth
            <div class="ms-auto d-flex align-items-center gap-3">
                <span class="text-white-50 small">{{ auth()->user()->email }}</span>
                <form action="{{ route('logout') }}" method="POST" class="m-0">
                    @csrf
                    <button class="btn btn-sm btn-outline-light" type="submit">Logout</button>
                </form>
            </div>
        @endauth
    </div>
</nav>

<main class="container pb-5">
    @if (session('status'))
        <div class="alert alert-success alert-dismissible fade show" role="alert" data-auto-dismiss>
            {{ session('status') }}
            <button type="button" class="btn-close" aria-label="Close" onclick="this.parentElement.remove()"></button>
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
            <button type="button" class="btn-close" aria-label="Close" onclick="this.parentElement.remove()"></button>
        </div>
    @endif

    @yield('content')
</main>

<script>
    // Auto-dismiss success flash messages after 5 seconds (errors stay until clicked).
    setTimeout(function () {
        document.querySelectorAll('.alert[data-auto-dismiss]').forEach(function (el) {
            el.classList.remove('show');                       // .fade transitions opacity to 0
            setTimeout(function () { el.remove(); }, 300);     // remove from DOM after fade completes
        });
    }, 5000);
</script>
</body>
</html>
