# Laravel CRUD — Products & Categories

A Laravel 13 application that exposes a single product-management domain through **two surfaces**:

1. an **admin dashboard** (Blade + Bootstrap 5) for browser users, and
2. a **REST API** (Sanctum-protected JSON) for machine clients.

The brief asked for full product CRUD with categories, soft + bulk delete, Excel export, request validation, tests, and Swagger docs. This README explains the *approach* — the why, not the play-by-play. 

---

## Stack

| Layer | Package / version | Notes |
| --- | --- | --- |
| Framework | **Laravel 13.7** (`laravel/framework: ^13.0`) | Project was scaffolded on Laravel 12 (the only version PHP 8.2 supported) and upgraded in-place after bumping XAMPP to PHP 8.4. |
| PHP | **8.4.20** (XAMPP, VS17 TS x64) | Required by Laravel 13. The original 8.2 install is preserved at `xampp/php-8.2-backup/` for rollback. |
| Database | **MySQL 8** (XAMPP) for dev; **SQLite `:memory:`** for tests | MySQL matches a typical production target; in-memory SQLite (configured in `phpunit.xml`) makes the test suite hermetic and ~5 s to run. |
| API auth | **Laravel Sanctum** (`^4.3`) | Personal-access-token style. No OAuth ceremony, no cookies, ships with Laravel. |
| Excel | **maatwebsite/excel** (`^3.1`) — i.e. SpartnerNL Laravel-Excel | Mandated by the brief. Used `FromQuery` (memory-safe streaming) + `WithStrictNullComparison` (so `stock = 0` writes as `0`, not blank). |
| API docs | **darkaonline/l5-swagger** (`^11.0`) + **swagger-php 6** | Package installed and route registered at `/api/documentation`. Per-endpoint annotations are currently trimmed from the source — re-adding `#[OA\…]` PHP attributes on the API controllers and running `php artisan l5-swagger:generate` republishes the spec. |
| Testing | **PHPUnit 11** | 25 tests / 72 assertions covering the model layer, web admin, JSON API, and Excel export. |

---

## Architectural approach

### 1. Two route files, one domain layer

The same operations are exposed through `routes/web.php` *and* `routes/api.php` because their callers want different things:

- **`web.php`** — session cookies, CSRF, `redirect()->route('login')` on auth failure, Blade responses.
- **`api.php`** — stateless, Sanctum-token auth, `401` / `422` JSON, no CSRF, `/api` prefix.

The controllers split accordingly (`App\Http\Controllers\Admin\ProductController` vs `App\Http\Controllers\Api\ProductController`), but they reuse the **same** Form Requests, the **same** Eloquent model, and the API additionally uses an `App\Http\Resources\ProductResource` for response shaping. Business rules live in one place; only the response format differs.

### 2. Domain modelling

- `Category` and `Product` both use **soft deletes** (`deleted_at`) — destroy operations are reversible at the database layer, the admin "Trashed" filter exposes them, and tests can assert with `assertSoftDeleted`.
- `Product belongsTo Category`; the FK is `restrictOnDelete()` so you can't orphan products by deleting a category outright.
- The `Product` model exposes two named scopes — `enabled(?bool)` and `forCategory(?int)` — used by both the web and API listings, so the filtering logic isn't duplicated across controllers.
- Casts (`price` → `decimal:2`, `stock` → `integer`, `enabled` → `boolean`) are declared on the model so the same value is always the same type whether it's in HTML, JSON, or an Excel cell.
- Index strategy on `products`: a single-column index on `enabled` and a composite `(category_id, enabled)` index — both align with the most common filter combos.

### 3. Validation as a single source of truth

All input validation lives in **Form Requests**:

| Request | Used by | Notable rules |
| --- | --- | --- |
| `StoreProductRequest` | Admin & API create | `required` + `exists:categories,id` for `category_id`; `boolean` cast on `enabled` via `prepareForValidation()` |
| `UpdateProductRequest` | Admin & API update | Same fields, but every rule is `sometimes` so partial updates work |
| `BulkDeleteProductRequest` | Admin & API bulk delete | `ids` is required, non-empty array, each id `exists:products,id` |

These same classes are typehinted in the web controller, the API controller, and the test suite, so a rule change cascades everywhere automatically. `authorize()` returns `true` because access is gated higher up by the `admin` middleware.

### 4. Stable JSON contract via API Resources

`ProductResource::toArray()` produces a stable JSON shape with the category id and name nested — exactly the shape the brief asked for:

```json
{
  "id": 1,
  "name": "Acme Widget",
  "description": "...",
  "price": 19.99,
  "stock": 42,
  "enabled": true,
  "category": { "id": 3, "name": "Tools" },
  "created_at": "2026-04-30T11:04:09+00:00",
  "updated_at": "2026-04-30T11:04:09+00:00",
  "deleted_at": null
}
```

The category object uses `whenLoaded('category', …)` so the `name` only appears when the relationship is eager-loaded — preventing accidental N+1 in list endpoints.

### 5. Authentication & authorization

- **Web** — Laravel's stock `auth` (session) middleware *plus* a small `App\Http\Middleware\AdminMiddleware` that checks `$user->is_admin`.
- **API** — `auth:sanctum` *plus* the same `AdminMiddleware`.
- Both surfaces share the middleware, so any future tightening (e.g. adding ownership rules) is a one-place change.
- Failure modes are content-aware: `AdminMiddleware` returns a JSON `{"message":"Forbidden"}` 403 for JSON requests and `abort(403)` (HTML page) for everything else.
- `POST /api/login` is the only public API endpoint; it issues a personal access token. Subsequent requests carry `Authorization: Bearer …`.

### 6. Excel export — `FromQuery` + `WithStrictNullComparison`

```php
class ProductsExport implements FromQuery, WithHeadings, WithMapping, WithStrictNullComparison
```

- **`FromQuery`** (instead of `FromCollection`) means rows stream from MySQL in chunks — fine for a 10 k-row catalogue, no memory blow-up.
- **`WithStrictNullComparison`** is the fix for an upstream PhpSpreadsheet quirk where the integer `0` is treated as "empty" in loose-comparison mode, leaving `stock` cells blank for out-of-stock products. With strict comparison only literal `null` is treated as empty.
- The export accepts the same `category_id` and `enabled` filters as the listing, so "what you see in the dashboard is what you download".

### 7. Tests as a regression net, not just happy-path coverage

| Layer | File | What it locks in |
| --- | --- | --- |
| Eloquent | `tests/Unit/ProductModelTest` | `belongsTo` / `hasMany`, soft delete, `enabled()` and `forCategory()` scopes |
| Web admin | `tests/Feature/ProductAdminTest` | guest → 302, non-admin → 403, listing, status filter, create, single delete, **bulk delete**, **per-row delete is *not* nested in the bulk form**, Excel export sends a download with **`stock=0` rendered as `0`** |
| JSON API | `tests/Feature/ProductApiTest` | unauthenticated → 401, non-admin → 403, list/filter/create/update/soft-delete/bulk-delete, validation errors, `POST /api/login` issues a token, response uses `data.0.category.{id,name}` shape |

Two of the more interesting tests exist because of bugs that actually slipped through:

- `test_index_page_does_not_nest_per_row_delete_inside_bulk_form` — guards against the original index template that wrapped the table in the bulk-delete `<form>` and accidentally nested every row's delete `<form>` inside it (HTML5 silently drops the inner tag, so individual deletes hit the wrong endpoint).
- `test_excel_export_writes_zero_stock_as_zero_not_blank` — actually parses the generated `.xlsx` with PhpSpreadsheet and asserts the stock column reads `0` for an out-of-stock row.

### 8. Small UX touches

- **Flash messages** auto-dismiss after 5 s (success only; errors stay until manually closed) — implemented with a `data-auto-dismiss` attribute and a 12-line inline script in the layout. No Bootstrap JS bundle required.
- **Create button** disables itself + shows a spinner the moment the form submits, and re-enables on `pageshow` if the user navigates back via bfcache. Prevents accidental double-creates without any framework.
- **Bulk-delete UX** uses the HTML5 `form="bulk-form"` attribute to wire row checkboxes to a form that lives *outside* the table, sidestepping the nested-form trap.

---

## Quick start

```bash
# 1. Database (XAMPP MySQL must be running)
mysql -u root -e "CREATE DATABASE laravel_crud CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. App
cp .env.example .env       # set DB_CONNECTION=mysql, DB_DATABASE=laravel_crud, DB_USERNAME=root
composer install
php artisan key:generate
php artisan migrate:fresh --seed
php artisan l5-swagger:generate

# 3. Run
php artisan serve          # http://127.0.0.1:8000
```

The seeder creates:

- **Admin user** — `admin@example.com` / `password` (`is_admin = true`)
- **Three categories** — `Item`, `Food`, `Equipment`
- No products. Add some via the dashboard or the API.

URLs to explore:

| What | URL / verb |
| --- | --- |
| Web login | `GET /login` |
| Admin dashboard (5 / page) | `GET /admin/products` |
| Admin filter | `GET /admin/products?status=enabled&category_id=2` |
| Excel export | `GET /admin/products/export` |
| Swagger UI | `GET /api/documentation` (page renders; spec is empty until annotations are re-added) |
| Issue API token | `POST /api/login` with JSON `{ "email":"…", "password":"…" }` |
| List products | `GET /api/products?category_id=1&status=enabled&per_page=20` (with `Authorization: Bearer …`) |
| Create / update / delete | `POST` / `PUT` / `DELETE /api/products[/{id}]` |
| Bulk delete | `DELETE /api/products/bulk` body `{ "ids": [1,2,3] }` |

---

## Repository map

```
app/
├── Exports/ProductsExport.php           # FromQuery + WithStrictNullComparison + WithMapping
├── Http/
│   ├── Controllers/
│   │   ├── Admin/ProductController.php  # web (Blade) admin: index/create/edit/show/destroy/bulk/export
│   │   ├── Api/ProductController.php    # JSON CRUD + bulk
│   │   ├── Api/AuthController.php       # POST /api/login → Sanctum token; POST /api/logout
│   │   └── Auth/LoginController.php     # web login form / submit / logout
│   ├── Middleware/AdminMiddleware.php   # 403 if !is_admin, JSON-aware
│   ├── Requests/                        # Store / Update / BulkDelete — used by both surfaces
│   └── Resources/                       # ProductResource (nested category), CategoryResource
└── Models/{Category,Product,User}.php   # SoftDeletes, scopes, casts, HasApiTokens

routes/
├── web.php   # /, /login, /logout, /admin/* — session + CSRF + Blade
└── api.php   # /api/login, /api/logout, /api/user, /api/products/* — Sanctum + JSON

resources/views/
├── layouts/app.blade.php                # nav + flash messages auto-dismiss after 5s
├── auth/login.blade.php
└── admin/products/{index,create,edit,show,_form}.blade.php

tests/
├── Unit/ProductModelTest.php            # relationships, soft delete, scopes
└── Feature/
    ├── ProductAdminTest.php             # 10 tests covering the dashboard
    └── ProductApiTest.php               # 10 tests covering the JSON API

database/
├── migrations/                           # users (+is_admin), categories, products, sanctum tokens
├── factories/                            # CategoryFactory, ProductFactory, UserFactory
└── seeders/DatabaseSeeder.php            # admin + 3 categories
```

---

## Running the tests

```bash
php artisan test                # 25 passed (72 assertions)
```

The whole suite runs against an **in-memory SQLite database** (configured in `phpunit.xml`) — no MySQL needed for CI. `RefreshDatabase` resets schema between tests, and Sanctum's `Sanctum::actingAs($user, ['*'])` is used on the API tests to bypass the token round-trip.

---

## Things deliberately out of scope

- **No password reset / registration flow.** The brief only requires authenticated admin access; the seeder ships an admin and that's enough for the demo.
- **No queue-backed export.** `Excel::download()` is fine at this scale; if the catalogue grew, switching to `Excel::queue()` is a one-line change.
- **No JS framework.** Two tiny inline scripts (auto-dismiss flash, disable Create button) keep the dashboard server-rendered. Adding Vue / Livewire would be over-engineering for the scope.
- **No frontend build step.** Bootstrap is loaded from CDN. `vite.config.js` is left in place but unused.
- **Apache integration.** XAMPP's bundled Apache loaded the old PHP 8.2's `php8ts.dll`. `php artisan serve` (port 8000) bypasses Apache entirely, so the project runs fine — but pointing Apache at the new PHP would need a separate `httpd-xampp.conf` tweak.

---

## Decisions worth highlighting in an interview

1. **Two route files, one domain layer.** Same business logic shared via Form Requests, model scopes, and `ProductResource`; the controllers themselves stay thin.
2. **Soft deletes everywhere.** Both `Category` and `Product` are recoverable; the admin "Trashed" status filter exposes them.
3. **`AdminMiddleware` is content-aware** — JSON 403 vs HTML `abort(403)` — so the same alias works on both route files.
4. **`WithStrictNullComparison` for Excel.** Caught a real bug (zero-stock cells rendering blank) and locked it in with a regression test that actually parses the generated `.xlsx`.
5. **Per-row delete forms are *not* nested inside the bulk-delete form.** Browsers silently drop nested `<form>` open tags, which broke individual deletes; the bulk form lives outside the table now and row checkboxes attach to it via the HTML5 `form="bulk-form"` attribute. Regression test asserts the `</form>` of the bulk form appears *before* the row delete form opens.
6. **Tests cover the actual regressions, not just the happy path.** Both fixes above ship with a test that would have caught them on the way in.

---

