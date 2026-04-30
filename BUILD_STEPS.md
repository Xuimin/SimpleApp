# Build Steps — Laravel CRUD (Products + Categories)

A walkthrough of every command and decision used to build this project, ordered the way I did them. Useful for explaining to a technical interviewer.

Stack: **Laravel 13.7**, **PHP 8.4.20 (XAMPP, upgraded from 8.2)**, **MySQL 8 (XAMPP)**, **Sanctum** (API auth), **maatwebsite/excel** (SpartnerNL Laravel-Excel), **darkaonline/l5-swagger** (OpenAPI docs), **PHPUnit**.

---

## 1. Prerequisites

XAMPP installs PHP and MySQL but does not put them on PATH. Composer is at `~/composer/composer.phar`. I ran:

```bash
export PATH="/c/Users/Chan Xui Min/xampp/php:$PATH"
alias composer='php "/c/Users/Chan Xui Min/composer/composer.phar"'
```

Two PHP extensions had to be enabled in `xampp/php/php.ini` because Laravel-Excel needs them:

```ini
extension=gd
extension=zip
```

(They were commented out by default.)

Started MySQL via `xampp/mysql_start.bat`.

---

## 2. Scaffold the Laravel project

In the empty `laravel-crud` directory:

```bash
composer create-project laravel/laravel . --prefer-dist
```

Installed Laravel 12. The installer ran `php artisan key:generate` and `php artisan migrate` automatically.

---

## 3. Install required packages

```bash
composer require maatwebsite/excel darkaonline/l5-swagger
composer require laravel/sanctum
```

Then publish their configs/migrations:

```bash
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan vendor:publish --provider="Maatwebsite\Excel\ExcelServiceProvider" --tag=config
php artisan vendor:publish --provider="L5Swagger\L5SwaggerServiceProvider"
```

> Why `maatwebsite/excel`? It is the composer package name; the GitHub org is `SpartnerNL/Laravel-Excel`. Same library.

---

## 4. Configure MySQL

Edit `.env`:

```
APP_NAME="Laravel CRUD"
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=laravel_crud
DB_USERNAME=root
DB_PASSWORD=
```

Create the database:

```bash
mysql -u root -e "CREATE DATABASE laravel_crud CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Tests use **SQLite in-memory** (set in `phpunit.xml`) so they are isolated and fast.

---

## 5. Domain — Category & Product

```bash
php artisan make:model Category -mf
php artisan make:model Product  -mf
```

`-mf` creates the migration **and** factory in one command.

### Migrations

`categories`:

```php
$table->id();
$table->string('name')->unique();
$table->softDeletes();
$table->timestamps();
```

`products`:

```php
$table->id();
$table->foreignId('category_id')->constrained()->restrictOnDelete();
$table->string('name');
$table->text('description')->nullable();
$table->decimal('price', 10, 2);
$table->unsignedInteger('stock')->default(0);
$table->boolean('enabled')->default(true);
$table->softDeletes();
$table->timestamps();
$table->index(['category_id', 'enabled']);
```

I also added `is_admin` (boolean, default false) to the Laravel-shipped `users` migration.

### Models

- `Category` — uses `SoftDeletes`, `hasMany(Product::class)`.
- `Product` — uses `SoftDeletes`, `belongsTo(Category::class)`, casts `price`/`enabled`/`stock`, two query scopes (`enabled()`, `forCategory($id)`).
- `User` — added `Laravel\Sanctum\HasApiTokens` and `is_admin` to `$fillable` and casts.

### Factories + Seeder

`DatabaseSeeder` creates one admin (`admin@example.com / password`), 5 categories, and 6 products per category.

Run:

```bash
php artisan migrate:fresh --seed
```

---

## 6. Validation — Form Requests

```bash
php artisan make:request StoreProductRequest
php artisan make:request UpdateProductRequest
php artisan make:request BulkDeleteProductRequest
```

`StoreProductRequest` rules:

```php
'name'        => ['required', 'string', 'max:255'],
'category_id' => ['required', 'integer', 'exists:categories,id'],
'description' => ['nullable', 'string'],
'price'       => ['required', 'numeric', 'min:0'],
'stock'       => ['required', 'integer', 'min:0'],
'enabled'     => ['sometimes', 'boolean'],
```

`UpdateProductRequest` makes everything `sometimes` so partial updates work.
`BulkDeleteProductRequest` validates `ids` is a non-empty array of existing product ids.

---

## 7. JSON shape — API Resources

```bash
php artisan make:resource ProductResource
php artisan make:resource CategoryResource
```

`ProductResource` returns the product fields plus a nested `category: { id, name }` object — that satisfies the requirement to expose both **category id** and **category name** in the JSON response.

---

## 8. Excel export

`app/Exports/ProductsExport.php` implements three Laravel-Excel concerns:

- `FromQuery` — paginates the export over a query (memory-safe for large data sets), and supports the same `category_id` / `enabled` filters as the listing.
- `WithHeadings` — first row of the sheet.
- `WithMapping` — turns each `Product` into a row.

Used in the controller as:

```php
return Excel::download(new ProductsExport($categoryId, $enabled), 'products-...xlsx');
```

---

## 9. Admin Dashboard (web UI)

```bash
php artisan make:controller Admin/ProductController --resource
php artisan make:controller Auth/LoginController
php artisan make:middleware AdminMiddleware
```

`AdminMiddleware` requires `auth()->user()->is_admin === true`. Registered as the `admin` alias inside `bootstrap/app.php`:

```php
$middleware->alias([
    'admin' => AdminMiddleware::class,
]);
```

`routes/web.php` — login routes for guests; everything under `/admin` requires `auth + admin`. The export and bulk-delete routes are declared **before** `Route::resource(...)` so `products/export` and `products/bulk` aren’t captured by `products/{product}`.

Blade views (Bootstrap 5 via CDN):

- `layouts/app.blade.php`     — nav, flash messages, error bag.
- `auth/login.blade.php`      — email/password form.
- `admin/products/index.blade.php` — table with pagination, status filter, category filter, bulk-delete checkbox column, export button.
- `admin/products/_form.blade.php` — shared form partial used by create/edit.
- `admin/products/{create,edit,show}.blade.php`.

---

## 10. REST API

```bash
php artisan make:controller Api/ProductController
php artisan make:controller Api/AuthController
```

Routes (`routes/api.php`):

```php
Route::post('/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::post('/logout',         [AuthController::class, 'logout']);
    Route::delete('products/bulk', [ProductController::class, 'bulkDestroy']);
    Route::apiResource('products', ProductController::class);
});
```

- `POST /api/login` → returns a Sanctum token.
- `GET  /api/products?category_id=X&status=enabled&per_page=20` — paginated list with category filter.
- `POST /api/products` — create.
- `GET  /api/products/{id}` — show.
- `PUT  /api/products/{id}` — update.
- `DELETE /api/products/{id}` — soft delete.
- `DELETE /api/products` body `{ "ids": [...] }` — bulk soft delete.

---

## 11. API Documentation (Swagger / OpenAPI)

Annotations live as **PHP 8 attributes** (preferred in `swagger-php` 6+):

```php
use OpenApi\Attributes as OA;

#[OA\Info(version: '1.0.0', title: 'Laravel CRUD API', ...)]
#[OA\SecurityScheme(securityScheme: 'sanctum', type: 'http', scheme: 'bearer')]
#[OA\Get(path: '/api/products', summary: '...', security: [['sanctum' => []]], ...)]
```

Generate the docs and view the UI:

```bash
php artisan l5-swagger:generate
# then open http://localhost:8000/api/documentation
```

---

## 12. Testing

```bash
php artisan make:test ProductApiTest
php artisan make:test ProductAdminTest
php artisan make:test --unit ProductModelTest
```

Coverage:

| Layer | What we assert |
| --- | --- |
| `tests/Unit/ProductModelTest`     | belongsTo / hasMany relationships, soft delete behaviour, `enabled()` and `forCategory()` scopes |
| `tests/Feature/ProductApiTest`    | unauthenticated → 401, non-admin → 403, list/filter/create/update/soft-delete/bulk-delete, validation errors, login issues a Sanctum token |
| `tests/Feature/ProductAdminTest`  | guest redirect, non-admin → 403, index render, status filter, create via form, bulk delete, Excel export returns an `attachment` content-disposition |

Run the suite:

```bash
php artisan test
# 23 tests, 63 assertions — all green
```

---

## 12.5 · Upgrade to Laravel 13 (PHP 8.4)

Laravel 13 requires PHP 8.3+, so the in-place upgrade was: replace XAMPP's PHP, then bump composer.

```bash
# 1. Stop any running php.exe processes (artisan serve, etc.)
# 2. Back up the existing install (so the move is reversible)
mv  "/c/Users/Chan Xui Min/xampp/php"  "/c/Users/Chan Xui Min/xampp/php-8.2-backup"

# 3. Download and extract PHP 8.4 (TS x64, VS17) from windows.php.net
#    https://downloads.php.net/~windows/releases/php-8.4.20-Win32-vs17-x64.zip
#    extract into  C:\Users\Chan Xui Min\xampp\php\

# 4. Build a fresh php.ini from the development template and re-enable the
#    extensions Laravel + Laravel-Excel need:
cp  xampp/php/php.ini-development  xampp/php/php.ini
# In php.ini, set:
#   extension_dir = "C:\Users\Chan Xui Min\xampp\php\ext"
# and uncomment:
#   extension=curl, fileinfo, gd, gettext, mbstring, exif,
#             mysqli, openssl, pdo_mysql, pdo_sqlite, zip

# 5. Bump composer.json
#   "laravel/framework": "^13.0"
#   "laravel/tinker":    "^3.0"     # tinker 2.x doesn't yet support L13

php "$composer" update --with-all-dependencies

# 6. Re-run the migrations and tests on the new stack
php artisan migrate:fresh --seed
php artisan l5-swagger:generate
php artisan test         # 23 passed (63 assertions) on Laravel 13.7 / PHP 8.4
```

> **Apache note**: XAMPP's bundled Apache loaded the old PHP 8.2 `php8ts.dll`. Since `php artisan serve` runs the project on its own port (8000) using the CLI, the project still runs fine even if Apache no longer loads the new PHP. To make XAMPP's Apache also work with PHP 8.4 you would have to either point `httpd-xampp.conf` at the new `php8ts.dll` (the filename is the same in 8.4) or — more reliably — install a newer XAMPP build that pairs Apache + PHP 8.4 together. None of the brief's requirements depend on Apache, so we left this alone. The previous install is preserved at `xampp/php-8.2-backup/` if you ever need to roll back.

---

## 13. How to demo the project end-to-end

```bash
# 1. start MySQL (XAMPP)  +  start Laravel
php artisan migrate:fresh --seed
php artisan serve

# 2. open http://localhost:8000 → redirected to /login
#    log in with admin@example.com / password
#    you land on /admin/products — full CRUD UI, filters, bulk delete, Excel export

# 3. API
curl -X POST http://localhost:8000/api/login \
     -H "Accept: application/json" -H "Content-Type: application/json" \
     -d '{"email":"admin@example.com","password":"password"}'
# → returns { token: "...", user: {...} }

curl -H "Authorization: Bearer <token>" \
     http://localhost:8000/api/products?category_id=1

# 4. Swagger UI: http://localhost:8000/api/documentation
```

---

## Mapping back to the brief

| Requirement | Where it lives |
| --- | --- |
| Create / Read (filter+paginate) / Update / Delete | `Admin\ProductController` + `Api\ProductController` |
| Soft delete | `SoftDeletes` trait on `Product` + `softDeletes()` migration column |
| Bulk delete | `bulkDestroy()` on both controllers, validated by `BulkDeleteProductRequest` |
| Export to Excel | `App\Exports\ProductsExport` + admin `export()` action |
| Category model + 1-to-many relationship | `App\Models\Category` ↔ `App\Models\Product` |
| Category id + name in JSON | `ProductResource::toArray()` returns nested `category: { id, name }` |
| RESTful API (auth, list with filter, detail, delete, bulk delete) | `routes/api.php` under `auth:sanctum + admin` |
| Form Request validation | `StoreProductRequest`, `UpdateProductRequest`, `BulkDeleteProductRequest` |
| PHPUnit tests | `tests/Unit/ProductModelTest`, `tests/Feature/ProductApiTest`, `tests/Feature/ProductAdminTest` |
| API documentation | `L5-Swagger` + PHP attribute annotations on Api controllers and `ProductResource` |
| SpartnerNL Laravel-Excel | `maatwebsite/excel` package (same library, different distribution name) |
