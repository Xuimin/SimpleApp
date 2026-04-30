<?php

namespace App\Exports;

use App\Models\Product;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

class ProductsExport implements FromQuery, WithHeadings, WithMapping, WithStrictNullComparison
{
    public function __construct(
        public ?int $categoryId = null,
        public ?bool $enabled = null,
    ) {}

    public function query()
    {
        return Product::query()
            ->with('category')
            ->forCategory($this->categoryId)
            ->when(! is_null($this->enabled), fn ($q) => $q->where('enabled', $this->enabled));
    }

    public function headings(): array
    {
        return ['ID', 'Name', 'Category', 'Description', 'Price', 'Stock', 'Enabled', 'Created'];
    }

    public function map($product): array
    {
        return [
            $product->id,
            $product->name,
            $product->category?->name,
            $product->description,
            $product->price,
            $product->stock,
            $product->enabled ? 'Yes' : 'No',
            $product->created_at?->format('Y-m-d H:i'),
        ];
    }
}
