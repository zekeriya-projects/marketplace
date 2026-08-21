import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useState, type FormEvent } from 'react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import ChannelLogo from '../../Components/ChannelLogo';
import type { Product } from '../../types/catalog';

type Account = { id: string; name: string };
type Option = { id: number; name: string };
type Attribute = {
    categoryAttribute?: { id: number; name: string };
    attribute?: { id: number; name: string };
    required?: boolean;
    allowCustom?: boolean;
    attributeValues?: Option[];
};
type Listing = { id: string; account: string; channel: string; variant: string; status: string; published_at?: string; last_synced_at?: string };

const statusLabels: Record<string, string> = { pending: 'Bekliyor', active: 'Yayında', rejected: 'Reddedildi', disabled: 'Devre dışı', error: 'Hatalı' };
const normalize = (value: string) => value.toLocaleLowerCase('tr-TR').trim();

export default function Show({ product, canUpdate, trendyolAccounts, channelListings }: { product: Product; canUpdate: boolean; trendyolAccounts: Account[]; channelListings: Listing[] }) {
    const [modalOpen, setModalOpen] = useState(false);
    const [step, setStep] = useState(1);
    const [categories, setCategories] = useState<Option[]>([]);
    const [brands, setBrands] = useState<Option[]>([]);
    const [attributes, setAttributes] = useState<Attribute[]>([]);
    const [brandSearch, setBrandSearch] = useState(product.brand_name ?? product.brand ?? '');
    const [categorySearch, setCategorySearch] = useState(product.category_name ?? '');
    const [lookupError, setLookupError] = useState('');
    const [loading, setLoading] = useState(false);
    const form = useForm({
        account_id: trendyolAccounts[0]?.id ?? '',
        variant_id: product.variants[0]?.id ?? '',
        brand_id: '',
        category_id: '',
        image_url: product.primary_image_url ?? '',
        vat_rate: String(product.vat_rate ?? 20),
        dimensional_weight: String(product.desi ?? 1),
        origin: 'TR',
        attributes: [] as { attributeId: number; attributeValueId?: number; customAttributeValue?: string }[],
        required_attribute_ids: [] as number[],
        template_name: '',
        central_category_id: product.category_id ?? '',
    });

    useEffect(() => {
        const requestedAccount = new URLSearchParams(window.location.search).get('publish');
        if (requestedAccount && trendyolAccounts.some(account => account.id === requestedAccount)) {
            form.setData('account_id', requestedAccount);
            setStep(0);
            setModalOpen(true);
        }
    }, []);

    const filteredCategories = useMemo(() => {
        const query = normalize(categorySearch);
        return query === '' ? categories : categories.filter(category => normalize(category.name).includes(query));
    }, [categories, categorySearch]);
    const requiredAttributes = attributes.filter(attribute => attribute.required);
    const completedRequiredAttributes = requiredAttributes.filter(attribute => {
        const id = (attribute.categoryAttribute ?? attribute.attribute)?.id;
        return id !== undefined && form.data.attributes.some(value => value.attributeId === id);
    }).length;
    const matchingComplete = form.data.brand_id !== '' && form.data.category_id !== '';
    const specialComplete = form.data.image_url.startsWith('https://') && completedRequiredAttributes === requiredAttributes.length;

    useEffect(() => {
        if (!modalOpen || !form.data.account_id) return;
        setLoading(true); setLookupError(''); setCategories([]); setAttributes([]);
        fetch(`/channels/accounts/${form.data.account_id}/trendyol/categories`, { headers: { Accept: 'application/json' } })
            .then(async response => { if (!response.ok) throw new Error((await response.json()).message); return response.json(); })
            .then(data => {
                const loaded = (data.categories ?? []) as Option[];
                setCategories(loaded);
                const centralCategory = normalize(product.category_name ?? '');
                const suggested = loaded.find(category => normalize(category.name.split('/').at(-1) ?? '') === centralCategory);
                if (suggested) void selectCategory(String(suggested.id));
            })
            .catch(error => setLookupError(error.message ?? 'Kategoriler yüklenemedi.'))
            .finally(() => setLoading(false));
        if (brandSearch.trim().length >= 2) void searchBrands(brandSearch, true);
    }, [modalOpen, form.data.account_id]);

    const searchBrands = async (term = brandSearch, autoSelect = false) => {
        if (term.trim().length < 2 || !form.data.account_id) return;
        setLoading(true); setLookupError('');
        try {
            const response = await fetch(`/channels/accounts/${form.data.account_id}/trendyol/brands?name=${encodeURIComponent(term)}`, { headers: { Accept: 'application/json' } });
            const data = await response.json(); if (!response.ok) throw new Error(data.message);
            const loaded = (data.brands ?? []) as Option[];
            setBrands(loaded);
            if (autoSelect) {
                const exact = loaded.find(brand => normalize(brand.name) === normalize(term));
                if (exact) form.setData('brand_id', String(exact.id));
            }
        } catch (error) { setLookupError(error instanceof Error ? error.message : 'Markalar yüklenemedi.'); }
        finally { setLoading(false); }
    };

    const selectCategory = async (categoryId: string) => {
        form.setData('category_id', categoryId); form.setData('attributes', []); setAttributes([]);
        if (!categoryId || !form.data.account_id) return;
        setLoading(true); setLookupError('');
        try {
            const response = await fetch(`/channels/accounts/${form.data.account_id}/trendyol/categories/${categoryId}/attributes`, { headers: { Accept: 'application/json' } });
            const data = await response.json(); if (!response.ok) throw new Error(data.message); const loaded = (data.attributes ?? []) as Attribute[]; setAttributes(loaded); form.setData('required_attribute_ids', loaded.filter(item => item.required).map(item => (item.categoryAttribute ?? item.attribute)?.id).filter((id): id is number => id !== undefined));
        } catch (error) { setLookupError(error instanceof Error ? error.message : 'Kategori özellikleri yüklenemedi.'); }
        finally { setLoading(false); }
    };

    const setAttribute = (attribute: Attribute, value: string) => {
        const definition = attribute.categoryAttribute ?? attribute.attribute;
        if (!definition) return;
        const remaining = form.data.attributes.filter(item => item.attributeId !== definition.id);
        if (!value) return form.setData('attributes', remaining);
        const numeric = attribute.attributeValues?.some(item => String(item.id) === value);
        form.setData('attributes', [...remaining, numeric ? { attributeId: definition.id, attributeValueId: Number(value) } : { attributeId: definition.id, customAttributeValue: value }]);
    };

    const publish = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/channels/accounts/${form.data.account_id}/trendyol/listings`, { onSuccess: () => setModalOpen(false) });
    };

    const steps = [
        { title: 'Temel', detail: 'Ürün bilgileri hazır', complete: true },
        { title: 'Eşleştirme', detail: matchingComplete ? 'Marka ve kategori hazır' : 'Marka ve kategori gerekli', complete: matchingComplete },
        { title: 'Trendyol özel', detail: specialComplete ? 'Zorunlu bilgiler hazır' : 'Eksik alanları tamamlayın', complete: specialComplete },
    ];

    return <AuthenticatedLayout><Head title={product.name} />
        <section className="page-heading heading-actions"><div className="product-show-heading">{product.primary_image_url && <img src={product.primary_image_url} alt={product.name} />}<div><span className="eyebrow">Katalog ürünü</span><h1>{product.name}</h1><p>{product.brand_name ?? product.brand ?? 'Marka yok'} · {product.status}</p></div></div><div className="form-actions"><Link className="button secondary" href="/products">Geri</Link>{canUpdate && <Link className="button" href={`/products/${product.id}/edit`}>Ürünü düzenle</Link>}</div></section>
        {product.description && <section className="panel product-show-panel"><h2>Açıklama</h2><p>{product.description}</p></section>}
        <section className="panel product-show-panel"><h2>Varyantlar</h2><div className="table-wrap"><table><thead><tr><th>Ad</th><th>SKU</th><th>Barkod</th><th>Temel fiyat</th><th>Durum</th></tr></thead><tbody>{product.variants.map(variant => <tr key={variant.id}><td><strong>{variant.name}</strong></td><td>{variant.sku ?? '—'}</td><td>{variant.barcode ?? '—'}</td><td>{variant.base_price} {variant.currency}</td><td><span className="status">{variant.status}</span></td></tr>)}</tbody></table></div></section>
        {channelListings.length > 0 && <section className="panel product-show-panel"><h2>Kanal yayınları</h2><div className="table-wrap"><table><thead><tr><th>Kanal</th><th>Hesap</th><th>Varyant</th><th>Durum</th><th>Son eşitleme</th></tr></thead><tbody>{channelListings.map(listing => <tr key={listing.id}><td>{listing.channel}</td><td>{listing.account}</td><td>{listing.variant}</td><td><span className={`status ${listing.status}`}>{statusLabels[listing.status] ?? listing.status}</span></td><td>{listing.last_synced_at ? new Date(listing.last_synced_at).toLocaleString('tr-TR') : '—'}</td></tr>)}</tbody></table></div></section>}
        {canUpdate && trendyolAccounts.length > 0 && <section className="panel product-show-panel marketplace-setup-card"><div><ChannelLogo code="trendyol" name="Trendyol" size="large" /><h2>Pazaryeri ürün ayarları</h2><p>Merkez ürün bilgilerini Trendyol kataloğuyla bir kez eşleştirin. Sonraki stok ve fiyat değişiklikleri otomatik senkronize edilir.</p></div><button className="button" type="button" onClick={() => { setStep(1); setModalOpen(true); }}>Ayarları tamamla</button></section>}

        {modalOpen && <div className="modal-layer trendyol-modal-layer"><button className="modal-backdrop" type="button" aria-label="Kapat" onClick={() => setModalOpen(false)} /><section className="modal-card trendyol-setup-modal"><header><div><span className="eyebrow">Trendyol yayın hazırlığı</span><h2>{product.name}</h2><p>Yalnızca Trendyol’un zorunlu tuttuğu bilgileri doğrulayın.</p></div><button className="modal-close" type="button" aria-label="Kapat" onClick={() => setModalOpen(false)}>×</button></header>
            <div className="setup-stepper">{steps.map((item, index) => <button type="button" key={item.title} className={`${step === index ? 'active' : ''} ${item.complete ? 'complete' : ''}`} onClick={() => setStep(index)}><span>{item.complete ? '✓' : index + 1}</span><div><strong>{item.title}</strong><small>{item.detail}</small></div></button>)}</div>
            <form onSubmit={publish}>
                {step === 0 && <div className="setup-pane"><div className="setup-summary-grid"><article><span>Merkez ürün</span><strong>{product.name}</strong><small>{product.id}</small></article><article><span>Marka</span><strong>{product.brand_name ?? product.brand ?? 'Belirtilmemiş'}</strong><small>SaaS kataloğundan</small></article><article><span>Kategori</span><strong>{product.category_name ?? 'Belirtilmemiş'}</strong><small>SaaS kataloğundan</small></article><article><span>Varyant</span><strong>{product.variants.length}</strong><small>Satılabilir seçenek</small></article></div><footer><button className="button" type="button" onClick={() => setStep(1)}>Eşleştirmeye geç</button></footer></div>}
                {step === 1 && <div className="setup-pane"><div className="mapping-context"><label>Trendyol hesabı<select value={form.data.account_id} onChange={event => form.setData('account_id', event.target.value)}>{trendyolAccounts.map(account => <option key={account.id} value={account.id}>{account.name}</option>)}</select></label><label>Yayınlanacak varyant<select value={form.data.variant_id} onChange={event => form.setData('variant_id', event.target.value)}>{product.variants.map(variant => <option key={variant.id} value={variant.id}>{variant.name}</option>)}</select></label></div>
                    <div className="mapping-block"><div className="mapping-source"><span>Ana ürün markası</span><strong>{product.brand_name ?? product.brand ?? 'Belirtilmemiş'}</strong></div><div className="mapping-arrow">→</div><div className="mapping-target"><label>Trendyol markası<input value={brandSearch} placeholder="Marka ara" onChange={event => setBrandSearch(event.target.value)} /></label><button className="button secondary small" type="button" disabled={loading || brandSearch.trim().length < 2} onClick={() => void searchBrands()}>Ara</button><select value={form.data.brand_id} onChange={event => form.setData('brand_id', event.target.value)}><option value="">Marka seçin</option>{brands.map(brand => <option key={brand.id} value={brand.id}>{brand.name}</option>)}</select></div></div>
                    <div className="mapping-block"><div className="mapping-source"><span>Ana ürün kategorisi</span><strong>{product.category_name ?? 'Belirtilmemiş'}</strong></div><div className="mapping-arrow">→</div><div className="mapping-target"><label>Trendyol kategorisi<input value={categorySearch} placeholder="Kategori ara" onChange={event => setCategorySearch(event.target.value)} /></label><select value={form.data.category_id} disabled={loading} onChange={event => void selectCategory(event.target.value)}><option value="">Kategori seçin</option>{filteredCategories.map(category => <option key={category.id} value={category.id}>{category.name}</option>)}</select></div></div>
                    {lookupError && <span className="error setup-error">{lookupError}</span>}<footer><button className="button secondary" type="button" onClick={() => setStep(0)}>Geri</button><button className="button" type="button" disabled={!matchingComplete || loading} onClick={() => setStep(2)}>Devam et</button></footer></div>}
                {step === 2 && <div className="setup-pane"><div className="special-fields-grid">{attributes.map(attribute => { const definition = attribute.categoryAttribute ?? attribute.attribute; if (!definition) return null; return <label key={definition.id}>{definition.name}{attribute.required ? ' *' : ''}{attribute.attributeValues?.length ? <select required={attribute.required} defaultValue="" onChange={event => setAttribute(attribute, event.target.value)}><option value="">Seçin</option>{attribute.attributeValues.map(option => <option key={option.id} value={option.id}>{option.name}</option>)}</select> : <input required={attribute.required} onChange={event => setAttribute(attribute, event.target.value)} />}</label>; })}<label>Ürün görseli HTTPS adresi *<input type="url" required value={form.data.image_url} placeholder="https://..." onChange={event => form.setData('image_url', event.target.value)} /></label><label>KDV oranı<select value={form.data.vat_rate} onChange={event => form.setData('vat_rate', event.target.value)}><option>0</option><option>1</option><option>10</option><option>20</option></select></label><label>Desi<input inputMode="decimal" value={form.data.dimensional_weight} onChange={event => form.setData('dimensional_weight', event.target.value)} /></label><label>Menşei<input maxLength={2} value={form.data.origin} onChange={event => form.setData('origin', event.target.value.toUpperCase())} /></label></div>
                    <label>Toplu yayın şablonu adı<input value={form.data.template_name} placeholder="Örn. Ayakkabı / Nike" onChange={event => form.setData('template_name', event.target.value)} /><small>İsteğe bağlı. Girildiğinde kategori, marka ve özel alanlar tekrar kullanılabilir.</small></label><div className="setup-completion"><span>{completedRequiredAttributes}/{requiredAttributes.length}</span><div><strong>Zorunlu özellikler</strong><small>{specialComplete ? 'Yayın için hazır' : 'Eksik alanları tamamlayın'}</small></div></div>{Object.values(form.errors).map((error, index) => <span className="error setup-error" key={index}>{error}</span>)}<footer><button className="button secondary" type="button" onClick={() => setStep(1)}>Geri</button><button className="button" disabled={form.processing || loading || !specialComplete}>Trendyol’a yayınla</button></footer></div>}
            </form>
        </section></div>}
    </AuthenticatedLayout>;
}
