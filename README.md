# Laravel CRUD — Products & Categories

A Laravel 13 application that exposes the same product-management domain through **two surfaces**:

1. an **admin dashboard** (Blade + Bootstrap) for browser users, and
2. a **REST API** (Sanctum-protected, OpenAPI-documented) for machine clients.

The brief asked for full CRUD with categories, soft + bulk delete, Excel export, request validation, tests, and Swagger docs. This README explains the *approach* I took — the trade-offs and the why. For the literal command-by-command build log see [`BUILD_STEPS.md`](./BUILD_STEPS.md).

---

## Stack

| Layer | Choice | Why |
| --- | --- | --- |
| Framework | **Laravel 13.7** | Latest stable. Started on 12 (the only version PHP 8.2 supported) and upgraded in-place after bumping XAMPP to PHP 8.4. |
| PHP | **8.4.20** (XAMPP, VS17 TS x64) | Required by Laravel 13. Old install kept at `xampp/php-8.2-backup/` for rollback. |
| Database | **MySQL 8** (XAMPP) for dev; **SQLite `:memory:`** for tests | MySQL matches production; in-memory SQLite makes the test suite fast and hermetic. |
| API auth | **Laravel Sanctum** | Lightweight token issuance, no OAuth ceremony, ships with Laravel. |
| Excel | **maatwebsite/excel** (a.k.a. SpartnerNL Laravel-Excel) | Mandated by the brief. Used `FromQuery` for memory-safe streaming + `WithStrictNullComparison` so `stock = 0` writes as `0` not blank. |
| API docs | **darkaonline/l5-swagger** + **swagger-php 6 PHP attributes** | Annotations live next to the code that produces them; one command (`l5-swagger:generate`) turns them into a Swagger UI at `/api/documentation`. |
| Testing | **PHPUnit 11** | Built into Laravel; 26 tests, 73 assertions covering model relationships, web admin, API, Excel export. |

---

## Architectural approach

### 1. Two route files, two HTTP contracts

The same domain (Product CRUD) is exposed through `routes/web.php` *and* `routes/api.php`. They are intentionally separate because their callers want different things:

- **`web.php`** — session cookies, CSRF, redirects on auth failure, Blade responses.
- **`api.php`** — stateless, Sanctum-token auth, `401`/`422` JSON, no CSRF, prefix `/api`.

The controllers are also split (`App\Http\Controllers\Admin\ProductController` vs `App\Http\Controllers\Api\ProductController`). They reuse the **same** Form Requests, the **same** Eloquent model, and the API additionally uses an `App\Http\Resources\ProductResource` for response shaping. So business rules live in one place; only the response format differs.

### 2. Domain modelling

- `Category` and `Product` both use **soft deletes** (`deleted_at`), so destroy operations are reversible at the database layer and `assertSoftDeleted` works in tests.
- `Product` `belongsTo` `Category`; the FK is `restrictOnDelete()` so you can't orphan products by deleting a category outright.
- The model exposes two named scopes — `enabled()` and `forCategory($id)` — used by both the web and API listings, so the filtering logic isn't duplicated across controllers.
- Casts (`price` → decimal:2, `stock` → integer, `enabled` → boolean) are declared on the model so the same value is always the same type whether it's in HTML, JSON, or Excel.

### 3. Validation — single source of truth

All input validation lives in **Form Requests**:

- `StoreProductRequest` — strict `required`s for create.
- `UpdateProductRequest` — same fields, but every rule is `sometimes` so `PATCH`-style partial updates work.
- `BulkDeleteProductRequest` — `ids` is required, must be a non-empty array of existing product ids (`exists:products,id`).

These same classes are typehinted in the web controller, the API controller, *and* the test suite, so a validation rule change cascades everywhere automatically. Authorization currently returns `true` from each request — access is gated higher up, by the `admin` middleware.

### 4. JSON response shape

`ProductResource::toArray()` produces a stable JSON shape with the category id and name nested as a sub-object, matching the brief:

```json
{
  "id": 1,
  "name": "Acme Widget",
  "price": 19.99,
  "stock": 42,
  "enabled": true,
  "category": { "id": 3, "name": "Tools" }
}
```

The OpenAPI schema for this object is declared via `#[OA\Schema]` on the resource class itself, so the docs and the actual payload can never drift apart.

### 5. Authentication & authorization

- Web: `auth` middleware (session) + a small `AdminMiddleware` that checks `$user->is_admin`. Non-admin authenticated users get a 403, not a 302 — that's deliberate, because the redirect-to-login pattern is reserved for *unauthenticated* visitors.
- API: `auth:sanctum` + the same `AdminMiddleware`. `POST /api/login` is the only public endpoint; it issues a personal access token and the rest of the API requires `Authorization: Bearer …`.
- Both surfaces share `AdminMiddleware`, so adding e.g. an "owner" rule later is a one-place change.

### 6. Why Excel export uses `FromQuery` + `WithStrictNullComparison`

- `FromQuery` (rather than `FromCollection`) means the export streams rows from the database in chunks — won't blow memory on a 10 k-row catalogue.
- `WithStrictNullComparison` is the fix for an upstream PhpSpreadsheet quirk where the integer `0` is treated as "empty" in loose comparison mode, leaving the `stock` cell blank for out-of-stock products. With strict comparison only literal `null` is treated as empty.
- The same `category_id` and `enabled` filters used by the listing are accepted by the export, so "what you see is what you download".

### 7. Tests as a regression net

The suite is split by what it's protecting:

| Layer | File | Locks in |
| --- | --- | --- |
| Eloquent | `tests/Unit/ProductModelTest` | belongsTo / hasMany, soft delete, `enabled()` and `forCategory()` scopes |
| Web admin | `tests/Feature/ProductAdminTest` | auth gate, admin gate, listing, status filter, create, single delete, **bulk delete**, **per-row delete is not nested in the bulk form**, **Excel export sends a download with stock=0 rendered as `0`** |
| JSON API | `tests/Feature/ProductApiTest` | unauthenticated → 401, non-admin → 403, list/filter/create/update/soft-delete/bulk-delete, validation errors, `POST /api/login` issues a token |

A few of those tests exist specifically because a bug slipped through earlier — the nested-form regression test, and the zero-stock Excel test, are there so the same bug can't quietly come back.

---

## Quick start

```bash
# 1. Database
mysql -u root -e "CREATE DATABASE laravel_crud CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. App
cp .env.example .env       # then point DB_* at MySQL (root / no password / laravel_crud)
composer install
php artisan key:generate
php artisan migrate:fresh --seed
php artisan l5-swagger:generate

# 3. Run
php artisan serve          # http://127.0.0.1:8000
```

Default seeded admin: **`admin@example.com` / `password`**.

URLs to explore:

| What | URL |
| --- | --- |
| Login | `http://127.0.0.1:8000/login` |
| Admin dashboard | `http://127.0.0.1:8000/admin/products` |
| Excel export | `http://127.0.0.1:8000/admin/products/export` |
| Swagger UI | `http://127.0.0.1:8000/api/documentation` |
| Issue API token | `POST http://127.0.0.1:8000/api/login` (JSON body) |
| List products | `GET http://127.0.0.1:8000/api/products?category_id=1&status=enabled` (with `Authorization: Bearer …`) |

---

## Repository map

```
app/
├── Exports/ProductsExport.php           # WithStrictNullComparison + FromQuery + WithMapping
├── Http/
│   ├── Controllers/
│   │   ├── Admin/ProductController.php  # web (Blade) admin CRUD + bulk + export
│   │   ├── Api/ProductController.php    # JSON CRUD + bulk (with Swagger attributes)
│   │   ├── Api/AuthController.php       # POST /api/login → Sanctum token
│   │   └── Auth/LoginController.php     # web login form
│   ├── Middleware/AdminMiddleware.php   # 403 if !is_admin (used by web AND api)
│   ├── Requests/                        # 3 Form Requests, used by both surfaces
│   └── Resources/                       # ProductResource (with #[OA\Schema])
└── Models/Category.php, Product.php     # SoftDeletes, scopes, casts

routes/
├── web.php   # /, /login, /admin/* — session + CSRF + Blade
└── api.php   # /api/login, /api/products/* — Sanctum + JSON

resources/views/
├── layouts/app.blade.php                # nav, flash messages auto-dismiss after 5s
├── auth/login.blade.php
└── admin/products/{index,create,edit,show,_form}.blade.php

tests/
├── Unit/ProductModelTest.php
└── Feature/{ProductAdminTest,ProductApiTest}.php

database/
├── migrations/                           # users (with is_admin), categories, products
├── factories/                            # CategoryFactory, ProductFactory
└── seeders/DatabaseSeeder.php            # admin user + 5 categories × 6 products
```

---

## Running the tests

```bash
php artisan test
```

The whole suite runs against an **in-memory SQLite database** (configured in `phpunit.xml`) — fast (~5 s) and isolated. RefreshDatabase resets schema between tests, and Sanctum's `actingAs($user)` is used to bypass the token round-trip.

---

## Things I deliberately did *not* do

- **No password reset / registration flow.** The brief only requires authenticated admin access; the seeder ships an admin and that's enough for the demo.
- **No queue-backed export.** `Excel::download()` is fine at the data scale the brief describes; if the catalogue grew, switching to `Excel::queue()` (also from `maatwebsite/excel`) is a one-line change.
- **No JS framework.** The dashboard is server-rendered Blade with two tiny inline scripts (auto-dismiss flash, disable Create button on submit). Adding Vue/Livewire would be over-engineering for the scope.
- **No frontend build step.** Bootstrap is loaded from a CDN. `vite.config.js` is left in place but unused.
- **No DELETE-on-bulk-empty UX guard in JS.** The bulk delete is gated server-side via `BulkDeleteProductRequest`; the form shows a JS `confirm()` and an empty submission would just bounce back with a validation error.

---

## Notable decisions worth highlighting in an interview

1. **Two route files, one domain layer.** Same controllers' worth of logic is shared via Form Requests, model scopes, and `ProductResource`; the controllers themselves stay thin.
2. **Soft deletes everywhere.** Both `Category` and `Product` are recoverable; the admin "Trashed" filter exposes deleted products.
3. **Swagger annotations as PHP 8 attributes**, not PHPDoc — required by swagger-php 6, and they live alongside the code that produces the response.
4. **`WithStrictNullComparison` for Excel.** Caught a real bug (zero-stock cells rendering blank) and locked it in with a regression test that actually parses the generated `.xlsx`.
5. **Per-row delete forms are *not* nested inside the bulk-delete form.** Browsers silently drop nested `<form>` open tags, which broke individual deletes; the bulk form lives outside the table now and row checkboxes attach to it via the HTML5 `form="bulk-form"` attribute.
6. **Tests cover the actual regressions, not just the happy path.** The two recent fixes (nested forms, zero-stock blank cell) each ship with a test that would have caught them.

---

## Reference

- [`BUILD_STEPS.md`](./BUILD_STEPS.md) — every command run and decision made, in order. Useful as a "build from scratch" recipe.
