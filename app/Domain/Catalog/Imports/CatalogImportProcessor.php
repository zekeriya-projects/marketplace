<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Imports;

use App\Domain\Catalog\Support\MinorUnits;
use App\Domain\Inventory\Enums\InventoryMovementType;
use App\Models\Brand;
use App\Models\CatalogImport;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use XMLReader;

final class CatalogImportProcessor
{
    /** @return array{total:int,created:int,updated:int,failed:int} */
    public function process(CatalogImport $import): array
    {
        $result = ['total' => 0, 'created' => 0, 'updated' => 0, 'failed' => 0];
        foreach ($this->rows($import) as $row) {
            $result['total']++;
            try {
                $created = $this->upsertRow($import, $row);
                $result[$created ? 'created' : 'updated']++;
            } catch (\Throwable) {
                $result['failed']++;
            }

            if ($result['total'] % 25 === 0) {
                $import->update(['processed_rows' => $result['total'], 'created_rows' => $result['created'], 'updated_rows' => $result['updated'], 'failed_rows' => $result['failed']]);
            }
        }

        return $result;
    }

    /** @return Generator<int, array<string, mixed>> */
    private function rows(CatalogImport $import): Generator
    {
        $path = Storage::disk($import->disk)->path($import->path);
        if ($import->format === 'xml') {
            yield from $this->xmlRows($path);

            return;
        }

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $headers = [];
        foreach ($sheet->getRowIterator() as $index => $sheetRow) {
            $values = [];
            foreach ($sheetRow->getCellIterator() as $cell) {
                $values[] = trim((string) $cell->getFormattedValue());
            }
            if ($index === 1) {
                $headers = array_map(fn (string $value): string => Str::snake(Str::lower($value)), $values);

                continue;
            }
            if (count(array_filter($values, fn ($value): bool => $value !== '')) === 0) {
                continue;
            }
            yield array_combine($headers, array_pad($values, count($headers), '')) ?: [];
        }
        $spreadsheet->disconnectWorksheets();
    }

    /** @return Generator<int, array<string, string>> */
    private function xmlRows(string $path): Generator
    {
        $reader = new XMLReader;
        if (! $reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new InvalidArgumentException('XML dosyası açılamadı.');
        }
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || ! in_array($reader->name, ['product', 'item'], true)) {
                continue;
            }
            $node = simplexml_load_string($reader->readOuterXml(), options: LIBXML_NONET);
            if ($node === false) {
                continue;
            }
            $row = [];
            foreach ($node->children() as $key => $value) {
                $row[Str::snake((string) $key)] = trim((string) $value);
            }
            yield $row;
        }
        $reader->close();
    }

    private function upsertRow(CatalogImport $import, array $row): bool
    {
        $name = trim((string) ($row['product_name'] ?? ''));
        $sku = trim((string) ($row['sku'] ?? ''));
        if ($name === '' || $sku === '') {
            throw new InvalidArgumentException('Ürün adı ve SKU zorunludur.');
        }

        return DB::transaction(function () use ($import, $row, $name, $sku): bool {
            $tenantId = $import->tenant_id;
            $category = $this->reference(Category::class, $tenantId, trim((string) ($row['category'] ?? '')));
            $brand = $this->reference(Brand::class, $tenantId, trim((string) ($row['brand'] ?? '')));
            $variant = ProductVariant::query()->where('tenant_id', $tenantId)->where('sku', $sku)->first();
            $created = $variant === null;
            $product = $variant?->product ?? Product::query()->where('tenant_id', $tenantId)->where('name', $name)->where('brand_id', $brand?->id)->first();
            $product ??= Product::query()->create(['tenant_id' => $tenantId, 'name' => $name, 'brand' => $brand?->name, 'brand_id' => $brand?->id, 'category_id' => $category?->id, 'description' => $row['product_description'] ?? null, 'status' => $this->status($row['status'] ?? null)]);
            $description = trim((string) ($row['product_description'] ?? ''));
            $product->update(['name' => $name, 'brand' => $brand?->name, 'brand_id' => $brand?->id, 'category_id' => $category?->id, 'description' => $description !== '' ? $description : $product->description]);
            $values = ['tenant_id' => $tenantId, 'product_id' => $product->id, 'name' => trim((string) ($row['variant_name'] ?? '')) ?: $name, 'sku' => $sku, 'barcode' => trim((string) ($row['barcode'] ?? '')) ?: null, 'base_price_amount' => MinorUnits::fromDecimal((string) ($row['price'] ?? '0')), 'currency' => strtoupper(trim((string) ($row['currency'] ?? 'TRY'))), 'status' => $this->status($row['status'] ?? null)];
            if ($variant) {
                $variant->update($values);
            } else {
                $variant = ProductVariant::query()->create($values);
            }
            $this->setStock($import, $variant, $row);

            return $created;
        }, attempts: 3);
    }

    private function reference(string $model, string $tenantId, string $name): Category|Brand|null
    {
        if ($name === '') {
            return null;
        }
        $existing = $model::query()->where('tenant_id', $tenantId)->whereRaw('lower(name) = lower(?)', [$name])->first();
        if ($existing) {
            return $existing;
        }
        $slug = Str::slug($name) ?: 'referans';
        if ($model::query()->where('tenant_id', $tenantId)->where('slug', $slug)->exists()) {
            $slug .= '-'.Str::lower(Str::random(6));
        }

        return $model::query()->create(['tenant_id' => $tenantId, 'name' => $name, 'slug' => $slug, 'status' => 'active']);
    }

    private function setStock(CatalogImport $import, ProductVariant $variant, array $row): void
    {
        if (! isset($row['stock']) || trim((string) $row['stock']) === '') {
            return;
        }
        $quantity = filter_var($row['stock'], FILTER_VALIDATE_INT);
        if ($quantity === false || $quantity < 0) {
            throw new InvalidArgumentException('Stok negatif olmayan tam sayı olmalıdır.');
        }
        $code = trim((string) ($row['warehouse_code'] ?? ''));
        $warehouse = Warehouse::query()->where('tenant_id', $import->tenant_id)->when($code !== '', fn ($query) => $query->where('code', $code), fn ($query) => $query->where('is_default', true))->firstOrFail();
        $item = InventoryItem::query()->firstOrCreate(['tenant_id' => $import->tenant_id, 'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id], ['quantity' => 0, 'reserved_quantity' => 0]);
        $item->refresh();
        $delta = $quantity - $item->quantity;
        if ($delta === 0) {
            return;
        }
        InventoryMovement::query()->create(['tenant_id' => $import->tenant_id, 'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'type' => InventoryMovementType::Import, 'quantity_delta' => $delta, 'quantity_before' => $item->quantity, 'quantity_after' => $quantity, 'reference_type' => CatalogImport::class, 'reference_id' => $import->id, 'note' => 'Katalog dosyası aktarımı', 'created_by_user_id' => $import->user_id]);
        $item->update(['quantity' => $quantity]);
    }

    private function status(mixed $status): string
    {
        return in_array($status, ['active', 'draft', 'archived'], true) ? $status : 'active';
    }
}
