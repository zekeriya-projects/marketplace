import { Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import type { ProductFormData, ProductSubmission, ProductVariant, VariantTemplateSummary } from '../types/catalog';

type Props = {
    initial?: ProductFormData;
    submitUrl: string;
    method: 'post' | 'put';
    submitLabel: string;
    cancelUrl: string;
    categories: { id: string; name: string }[];
    brands: { id: string; name: string }[];
    productType?: 'simple' | 'variable';
    variantTemplate?: VariantTemplateSummary | null;
};

const emptyVariant = (template?: VariantTemplateSummary | null): ProductVariant => ({
    name: template ? 'Yeni varyant' : 'Varsayılan', sku: null, barcode: null, base_price: '0.00', currency: 'TRY', status: 'draft',
    variant_template_id: template?.id ?? null,
    option_values: template ? Object.fromEntries(template.options.map((option) => [option.name, option.values[0] ?? ''])) : null,
});

export default function ProductForm({ initial, submitUrl, method, submitLabel, cancelUrl, categories, brands, productType = 'simple', variantTemplate = null }: Props) {
    const [section, setSection] = useState<'general' | 'images' | 'description' | 'variants'>('general');
    const form = useForm<ProductSubmission>({ ...(initial ?? {
        name: '', category_id: null, brand_id: null, brand: null, description: null, status: 'draft', short_name: null, invoice_name: null, custom_code_1: null, custom_code_2: null, compare_at_price: null, purchase_price: null, desi: null, desi_2: null, vat_rate: 20, excise_tax_rate: '0', communication_tax_rate: '0', disable_external_sync: false, vat_exemption_code: null, expiration_date: null, variants: [emptyVariant(variantTemplate)],
    }), product_type: productType, images: [] });

    const updateVariant = (index: number, field: keyof ProductVariant, value: string) => {
        const variants = [...form.data.variants];
        variants[index] = { ...variants[index], [field]: value };
        form.setData('variants', variants);
    };

    const updateOption = (index: number, option: string, value: string) => {
        const variants = [...form.data.variants];
        const optionValues = { ...(variants[index].option_values ?? {}), [option]: value };
        variants[index] = { ...variants[index], option_values: optionValues, name: Object.values(optionValues).filter(Boolean).join(' / ') || 'Yeni varyant' };
        form.setData('variants', variants);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (method === 'post') form.post(submitUrl, { forceFormData: true });
        else { form.transform((data) => ({ ...data, _method: 'put' }) as ProductSubmission); form.post(submitUrl, { forceFormData: true }); }
    };

    return (
        <form className="product-detail-layout" onSubmit={submit}>
            <aside className="product-section-nav"><button className={section === 'general' ? 'active' : ''} onClick={() => setSection('general')} type="button">Genel</button><button className={section === 'images' ? 'active' : ''} onClick={() => setSection('images')} type="button">Görsel</button><button className={section === 'description' ? 'active' : ''} onClick={() => setSection('description')} type="button">Açıklama</button>{productType === 'variable' && <button className={section === 'variants' ? 'active' : ''} onClick={() => setSection('variants')} type="button">Varyantlar</button>}<button className="button" disabled={form.processing} type="submit">{submitLabel}</button><Link className="button secondary" href={cancelUrl}>İptal</Link></aside>
            <div className="product-detail-content">
            {section === 'general' && <section className="panel form-panel">
                <h2>Ürün bilgileri</h2>
                <label>Ürün adı<input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></label>
                {form.errors.name && <span className="error">{form.errors.name}</span>}
                <div className="form-row">
                    <label>Kategori<select value={form.data.category_id ?? ''} onChange={(e) => form.setData('category_id', e.target.value || null)}><option value="">Kategori yok</option>{categories.map((category) => <option value={category.id} key={category.id}>{category.name}</option>)}</select></label>
                    <label>Marka<select value={form.data.brand_id ?? ''} onChange={(e) => { const id = e.target.value || null; form.setData((data) => ({ ...data, brand_id: id, brand: brands.find((brand) => brand.id === id)?.name ?? null })); }}><option value="">Marka yok</option>{brands.map((brand) => <option value={brand.id} key={brand.id}>{brand.name}</option>)}</select></label>
                    <label>Durum<select value={form.data.status} onChange={(e) => form.setData('status', e.target.value as ProductFormData['status'])}>
                        <option value="draft">Taslak</option><option value="active">Aktif</option><option value="archived">Arşivlendi</option>
                    </select></label>
                </div>
                <label>Açıklama<textarea rows={4} value={form.data.description ?? ''} onChange={(e) => form.setData('description', e.target.value)} /></label>
                <div className="form-divider"><span>Gruplandırma</span></div>
                <div className="form-row"><label>Kısa ad<input value={form.data.short_name ?? ''} onChange={(e) => form.setData('short_name', e.target.value)} /></label><label>Fatura adı<input value={form.data.invoice_name ?? ''} onChange={(e) => form.setData('invoice_name', e.target.value)} /></label><label>Özel kod 1<input value={form.data.custom_code_1 ?? ''} onChange={(e) => form.setData('custom_code_1', e.target.value)} /></label><label>Özel kod 2<input value={form.data.custom_code_2 ?? ''} onChange={(e) => form.setData('custom_code_2', e.target.value)} /></label></div>
                <div className="form-divider"><span>Fiyatlandırma</span></div>
                {productType === 'simple' && <div className="form-row"><label>Stok kodu<input value={form.data.variants[0].sku ?? ''} onChange={(e) => updateVariant(0, 'sku', e.target.value)} /></label><label>Barkod<div className="input-action"><input value={form.data.variants[0].barcode ?? ''} onChange={(e) => updateVariant(0, 'barcode', e.target.value)} /><button onClick={() => updateVariant(0, 'barcode', `${Date.now()}`.slice(-13))} type="button">Oluştur</button></div></label><label>Temel satış fiyatı<input inputMode="decimal" value={form.data.variants[0].base_price} onChange={(e) => updateVariant(0, 'base_price', e.target.value)} /></label><label>Kur tipi<select value={form.data.variants[0].currency} onChange={(e) => updateVariant(0, 'currency', e.target.value)}><option>TRY</option><option>USD</option><option>EUR</option></select></label></div>}
                <div className="form-row"><label>Temel üstü çizgili fiyat<input inputMode="decimal" value={form.data.compare_at_price ?? ''} onChange={(e) => form.setData('compare_at_price', e.target.value)} /></label><label>Alış fiyatı<input inputMode="decimal" value={form.data.purchase_price ?? ''} onChange={(e) => form.setData('purchase_price', e.target.value)} /></label></div>
                <div className="form-divider"><span>Kargo ve vergi</span></div>
                <div className="form-row"><label>Desi<input min="0" step="0.01" type="number" value={form.data.desi ?? ''} onChange={(e) => form.setData('desi', e.target.value)} /></label><label>Desi 2<input min="0" step="0.01" type="number" value={form.data.desi_2 ?? ''} onChange={(e) => form.setData('desi_2', e.target.value)} /></label><label>KDV oranı<select value={form.data.vat_rate} onChange={(e) => form.setData('vat_rate', Number(e.target.value))}>{[0, 1, 10, 20].map((rate) => <option key={rate} value={rate}>%{rate}</option>)}</select></label><label>ÖTV oranı (%)<input max="100" min="0" step="0.01" type="number" value={form.data.excise_tax_rate} onChange={(e) => form.setData('excise_tax_rate', e.target.value)} /></label><label>ÖİV oranı (%)<input max="100" min="0" step="0.01" type="number" value={form.data.communication_tax_rate} onChange={(e) => form.setData('communication_tax_rate', e.target.value)} /></label><label>Varsayılan istisna kodu<input value={form.data.vat_exemption_code ?? ''} onChange={(e) => form.setData('vat_exemption_code', e.target.value)} /></label><label>Son kullanma tarihi<input type="date" value={form.data.expiration_date ?? ''} onChange={(e) => form.setData('expiration_date', e.target.value)} /></label><label className="checkbox"><input checked={form.data.disable_external_sync} onChange={(e) => form.setData('disable_external_sync', e.target.checked)} type="checkbox" /> Dışarıdan ürün senkronizasyonu yapma</label></div>
                {Object.values(form.errors).filter(Boolean).map((error, index) => <span className="error" key={index}>{error}</span>)}
            </section>
            }
            {section === 'images' && <section className="panel form-panel"><h2>Ürün görselleri</h2><p>JPG, PNG veya WebP biçiminde en fazla 10 görsel yükleyebilirsiniz. Her görsel en fazla 5 MB olabilir.</p>{initial?.primary_image_url && <div className="current-product-image"><img src={initial.primary_image_url} alt={initial.name} /><div><strong>Mevcut ürün görseli</strong><small>Yeni bir görsel seçerseniz ürün galerisinin sonuna eklenir.</small></div></div>}<label className="image-dropzone">Görselleri buraya bırakın veya seçmek için tıklayın<input accept="image/jpeg,image/png,image/webp" multiple type="file" onChange={(e) => form.setData('images', Array.from(e.target.files ?? []))} /><strong>{form.data.images.length ? `${form.data.images.length} görsel seçildi` : 'Görsel seçilmedi'}</strong></label></section>}
            {section === 'description' && <section className="panel form-panel"><h2>Açıklama</h2><p>Ürünün kullanımını, özelliklerini ve müşterinin karar vermesini sağlayacak ayrıntıları yazın.</p><textarea className="description-editor" rows={22} value={form.data.description ?? ''} onChange={(e) => form.setData('description', e.target.value)} /></section>}
            {section === 'variants' && <section className="panel">
                <div className="section-heading"><div><h2>Varyantlar</h2><p>Satılabilir her SKU ayrı bir varyanttır.</p></div>
                    <button className="button secondary small" type="button" onClick={() => form.setData('variants', [...form.data.variants, emptyVariant(variantTemplate)])}>Varyant ekle</button>
                </div>
                {form.errors.variants && <span className="error">{form.errors.variants}</span>}
                <div className="variant-list">
                    {form.data.variants.map((variant, index) => (
                        <article className="variant-card" key={variant.id ?? index}>
                            <div className="variant-heading"><strong>Varyant {index + 1}</strong>
                                {form.data.variants.length > 1 && !variant.id && <button className="danger-link" type="button" onClick={() => form.setData('variants', form.data.variants.filter((_, itemIndex) => itemIndex !== index))}>Kaldır</button>}
                            </div>
                            <div className="variant-grid">
                                {variantTemplate?.options.map((option) => <label key={option.name}>{option.name}<select value={variant.option_values?.[option.name] ?? ''} onChange={(e) => updateOption(index, option.name, e.target.value)}>{option.values.map((value) => <option key={value} value={value}>{value}</option>)}</select></label>)}
                                <label>Ad<input value={variant.name} onChange={(e) => updateVariant(index, 'name', e.target.value)} /></label>
                                <label>SKU<input value={variant.sku ?? ''} onChange={(e) => updateVariant(index, 'sku', e.target.value)} /></label>
                                <label>Barkod<input value={variant.barcode ?? ''} onChange={(e) => updateVariant(index, 'barcode', e.target.value)} /></label>
                                <label>Temel fiyat<input inputMode="decimal" value={variant.base_price} onChange={(e) => updateVariant(index, 'base_price', e.target.value)} /></label>
                                <label>Para birimi<input maxLength={3} value={variant.currency} onChange={(e) => updateVariant(index, 'currency', e.target.value.toUpperCase())} /></label>
                                <label>Durum<select value={variant.status} onChange={(e) => updateVariant(index, 'status', e.target.value)}>
                                    <option value="draft">Taslak</option><option value="active">Aktif</option><option value="archived">Arşivlendi</option>
                                </select></label>
                            </div>
                            {Object.entries(form.errors).filter(([key]) => key.startsWith(`variants.${index}.`)).map(([key, error]) => <span className="error" key={key}>{error}</span>)}
                        </article>
                    ))}
                </div>
            </section>}
            </div>
        </form>
    );
}
