import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import ProductForm from '../../Components/ProductForm';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import type { VariantTemplateSummary } from '../../types/catalog';

type Reference = { id: string; name: string };
type ProductType = 'simple' | 'variable';

export default function Create({ categories, brands, variantTemplates }: { categories: Reference[]; brands: Reference[]; variantTemplates: VariantTemplateSummary[] }) {
    const [productType, setProductType] = useState<ProductType | null>(null);
    const [templateId, setTemplateId] = useState(variantTemplates[0]?.id ?? '');
    const template = variantTemplates.find((item) => item.id === templateId) ?? null;

    if (productType === null) return <AuthenticatedLayout><Head title="Ürün türü seçimi" /><section className="page-heading"><span className="eyebrow">Yeni ürün</span><h1>Ürün Türü Seçimi</h1><p>Ürününüzün satış yapısına en uygun türü seçerek başlayın.</p></section><section className="product-type-grid"><button className="product-type-card" onClick={() => setProductType('simple')} type="button"><span className="product-type-icon">●</span><span><small>Temel</small><strong>Basit Ürün</strong><p>Tek bir öğeden oluşan, standart özelliklere sahip ve müşterilerin seçenekleri arasında farklılık göstermeyen temel ürün.</p></span><b>Devam et →</b></button><button className="product-type-card" onClick={() => setProductType('variable')} type="button"><span className="product-type-icon variable">◆</span><span><small>Çeşitlendirilmiş Seçenekler</small><strong>Varyantlı Ürün</strong><p>Temel bir üründen türetilen farklı özelliklere veya varyasyonlara sahip ürünler. Müşteriler renk, beden veya diğer özelliklere göre seçim yapabilir.</p></span><b>Devam et →</b></button></section></AuthenticatedLayout>;

    if (productType === 'variable' && variantTemplates.length === 0) return <AuthenticatedLayout><Head title="Varyant şablonu gerekli" /><section className="page-heading"><span className="eyebrow">Yeni ürün</span><h1>Varyantlı Ürün</h1></section><section className="panel template-warning"><span>!</span><div><h2>Önce bir varyant şablonu oluşturmalısınız</h2><p>Varyantlı ürün ekleyebilmek için renk, beden veya benzeri seçenekleri tanımlayan en az bir aktif varyant şablonu zorunludur.</p><div className="form-actions"><Link className="button" href="/variant-templates">Varyant şablonu oluştur</Link><button className="button secondary" onClick={() => setProductType(null)} type="button">Ürün türüne dön</button></div></div></section></AuthenticatedLayout>;

    return <AuthenticatedLayout><Head title="Ürün oluştur" /><section className="page-heading heading-actions"><div><span className="eyebrow">{productType === 'variable' ? 'Varyantlı ürün' : 'Basit ürün'}</span><h1>Ürün oluştur</h1><p>Ürünü ve satılabilir SKU bilgilerini ekleyin.</p></div><button className="button secondary" onClick={() => setProductType(null)} type="button">Türü değiştir</button></section>{productType === 'variable' && <section className="panel template-selector"><label>Varyant şablonu<select value={templateId} onChange={(event) => setTemplateId(event.target.value)}>{variantTemplates.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label><p>Seçilen şablon varyant seçeneklerini belirler.</p></section>}<ProductForm brands={brands} cancelUrl="/products" categories={categories} key={`${productType}-${templateId}`} method="post" productType={productType} submitLabel="Ürünü oluştur" submitUrl="/products" variantTemplate={productType === 'variable' ? template : null} /></AuthenticatedLayout>;
}
