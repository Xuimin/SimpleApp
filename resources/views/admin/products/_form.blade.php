@csrf
<div class="row g-3">
    <div class="col-md-8">
        <label class="form-label">Name</label>
        <input type="text" name="name" value="{{ old('name', $product->name ?? '') }}" class="form-control" required>
    </div>
    <div class="col-md-4">
        <label class="form-label">Category</label>
        <select name="category_id" class="form-select" required>
            <option value="">Select category</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}" @selected(old('category_id', $product->category_id ?? null) == $category->id)>{{ $category->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-4">
        <label class="form-label">Price</label>
        <input type="number" step="0.01" min="0" name="price" value="{{ old('price', $product->price ?? '') }}" class="form-control" required>
    </div>
    <div class="col-md-4">
        <label class="form-label">Stock</label>
        <input type="number" min="0" name="stock" value="{{ old('stock', $product->stock ?? 0) }}" class="form-control" required>
    </div>
    <div class="col-md-4 d-flex align-items-end">
        <div class="form-check">
            <input type="hidden" name="enabled" value="0">
            <input type="checkbox" name="enabled" value="1" class="form-check-input" id="enabled"
                @checked(old('enabled', $product->enabled ?? true))>
            <label class="form-check-label" for="enabled">Enabled</label>
        </div>
    </div>
    <div class="col-12">
        <label class="form-label">Description</label>
        <textarea name="description" rows="4" class="form-control">{{ old('description', $product->description ?? '') }}</textarea>
    </div>
</div>
