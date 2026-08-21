<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CatalogImport;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CatalogImportTemplateController extends Controller
{
    public function index(): InertiaResponse
    {
        Gate::authorize('viewAny', CatalogImport::class);

        return Inertia::render('ImportTemplates/Index');
    }

    public function download(): StreamedResponse
    {
        Gate::authorize('viewAny', CatalogImport::class);
        $spreadsheet = $this->spreadsheet();

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, 'katalog-aktarım-şablonu.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function spreadsheet(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Ürünler');
        $headers = ['product_name', 'product_description', 'category', 'brand', 'variant_name', 'sku', 'barcode', 'price', 'currency', 'stock', 'warehouse_code', 'status'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->fromArray(['Örnek Tişört', 'Pamuklu ürün', 'Giyim', 'Örnek Marka', 'Siyah / M', 'TSH-SYH-M', '8690000000001', '499.90', 'TRY', 25, 'MERKEZ', 'active'], null, 'A2');
        $sheet->freezePane('A2')->setAutoFilter('A1:L2');
        $sheet->getStyle('A1:L1')->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F46E5']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);
        foreach (range('A', 'L') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        foreach (['I' => '"TRY,USD,EUR"', 'L' => '"active,draft,archived"'] as $column => $formula) {
            $validation = new DataValidation;
            $validation->setType(DataValidation::TYPE_LIST)->setAllowBlank(false)->setShowDropDown(true)->setFormula1($formula);
            $sheet->setDataValidation("{$column}2:{$column}1000", $validation);
        }
        $guide = $spreadsheet->createSheet();
        $guide->setTitle('Açıklamalar');
        $guide->fromArray([['Alan', 'Açıklama'], ['product_name', 'Zorunlu ürün adı'], ['sku', 'Zorunlu ve organizasyon içinde benzersiz SKU'], ['price', 'Ondalıklı satış fiyatı; para birimi ayrı sütundadır'], ['stock', 'Negatif olmayan tam sayı'], ['warehouse_code', 'Boşsa varsayılan depo kullanılır'], ['status', 'active, draft veya archived']], null, 'A1');
        $guide->getStyle('A1:B1')->getFont()->setBold(true);
        $guide->getColumnDimension('A')->setWidth(24);
        $guide->getColumnDimension('B')->setWidth(75);

        return $spreadsheet;
    }
}
