import { Head } from '@inertiajs/react';
import ProductForm from '../../Components/ProductForm';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import type { Product, VariantTemplateSummary } from '../../types/catalog';

export default function Edit({ product, categories, brands, variantTemplates }: { product: Product; categories: { id: string; name: string }[]; brands: { id: string; name: string }[]; variantTemplates: VariantTemplateSummary[] }) {
    const { id, ...initial } = product;
    const template = variantTemplates.find((item) => item.id === product.variants[0]?.variant_template_id) ?? null;
    return <AuthenticatedLayout><Head title={`${product.name} düzenle`} />
        <section className="page-heading"><span className="eyebrow">Katalog</span><h1>Ürünü düzenle</h1><p>Merkezi ürün ve varyant bilgilerini güncelleyin.</p></section>
        <ProductForm brands={brands} cancelUrl={`/products/${id}`} categories={categories} initial={initial} method="put" productType={template ? 'variable' : 'simple'} submitLabel="Değişiklikleri kaydet" submitUrl={`/products/${id}`} variantTemplate={template} />
    </AuthenticatedLayout>;
}
