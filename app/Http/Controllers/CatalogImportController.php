<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\StoreCatalogImportRequest;
use App\Jobs\ProcessCatalogImportJob;
use App\Models\CatalogImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class CatalogImportController extends Controller
{
    public function index(TenantContext $context): Response
    {
        Gate::authorize('viewAny', CatalogImport::class);

        return Inertia::render('Imports/Index', [
            'imports' => $context->get()->catalogImports()->latest()->paginate(15),
            'canCreate' => Gate::allows('create', CatalogImport::class),
        ]);
    }

    public function store(StoreCatalogImportRequest $request, TenantContext $context): RedirectResponse
    {
        $file = $request->file('file');
        $tenant = $context->get();
        $path = $file->store("catalog-imports/{$tenant->id}", 'local');
        $import = $tenant->catalogImports()->create(['user_id' => $request->user()->id, 'format' => $request->string('format')->toString(), 'original_name' => $file->getClientOriginalName(), 'disk' => 'local', 'path' => $path, 'status' => 'pending']);
        ProcessCatalogImportJob::dispatch($import->id);

        return back()->with('success', 'Dosya alındı ve aktarım kuyruğa eklendi.');
    }
}
