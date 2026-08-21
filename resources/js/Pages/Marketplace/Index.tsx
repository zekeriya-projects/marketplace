import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import ChannelLogo from '../../Components/ChannelLogo';

type Listing = { id: string; status: string; external_product_id: string | null; external_variant_id: string | null; external_sku: string | null; external_barcode: string | null; channel_price_amount: number | null; currency: string | null; available: number; published_at: string | null; last_synced_at: string | null; product: { id: string; name: string }; variant: { id: string; name: string; base_price_amount: number; currency: string } | null; account: { id: string; name: string; channel: { name: string; code: string } } };
type LinkItem = { url: string | null; label: string; active: boolean };
type Props = { listings: { data: Listing[]; links: LinkItem[]; from: number | null; to: number | null; total: number }; counts: Record<string, number>; accounts: { id: string; name: string; channel: string }[]; filters: { search: string; status: string; account: string } };
const labels: Record<string, string> = { active: 'Satışta', pending: 'Hazırlanıyor', rejected: 'Reddedildi', error: 'Hatalı', disabled: 'Durduruldu', unmapped: 'Eşleşmemiş' };
const money = (amount: number | null, currency: string | null) => amount === null || !currency ? '—' : new Intl.NumberFormat('tr-TR', { style: 'currency', currency }).format(amount / 100);

export default function Index({ listings, counts, accounts, filters }: Props) {
    const [search, setSearch] = useState(filters.search);
    const apply = (values: Partial<Props['filters']>) => router.get('/listings', { ...filters, ...values }, { preserveState: true, replace: true });
    const submit = (event: FormEvent) => { event.preventDefault(); apply({ search }); };
    const cards = ['active', 'pending', 'error', 'rejected', 'disabled', 'unmapped'];

    return <AuthenticatedLayout><Head title="Pazaryeri Ürünleri" />
        <section className="page-heading heading-actions"><div><span className="eyebrow">Kanal operasyonları</span><h1>Pazaryeri Ürünleri</h1><p>WooCommerce ve Trendyol yayınlarını, stoklarını ve eşleşme durumlarını tek ekrandan izleyin.</p></div><Link className="button" href="/products">Ürünlere git</Link></section>
        <div className="listing-metric-grid">{cards.map(status => <button type="button" className={`listing-metric ${filters.status === status ? 'active' : ''}`} key={status} onClick={() => apply({ status: filters.status === status ? '' : status })}><span>{labels[status]}</span><strong>{counts[status] ?? 0}</strong><small>kanal kaydı</small></button>)}</div>
        <section className="panel"><form className="marketplace-filters" onSubmit={submit}><input value={search} onChange={event => setSearch(event.target.value)} placeholder="Ürün, varyant, SKU veya barkod ara" /><select value={filters.account} onChange={event => apply({ account: event.target.value })}><option value="">Tüm kanal hesapları</option>{accounts.map(account => <option key={account.id} value={account.id}>{account.channel} · {account.name}</option>)}</select><button className="button secondary">Filtrele</button></form>
            {listings.data.length ? <div className="table-wrap"><table><thead><tr><th>Ürün / Varyant</th><th>Kanal</th><th>SKU / Barkod</th><th>Stok</th><th>Kanal fiyatı</th><th>Durum</th><th>Son senkronizasyon</th></tr></thead><tbody>{listings.data.map(listing => <tr key={listing.id}><td><Link href={`/products/${listing.product.id}`}><strong>{listing.product.name}</strong><small>{listing.variant?.name ?? 'Ana ürün'}</small></Link></td><td><ChannelLogo code={listing.account.channel.code} name={listing.account.channel.name} /><small>{listing.account.name}</small></td><td>{listing.external_sku ?? '—'}<small>{listing.external_barcode ?? 'Barkod yok'}</small></td><td><strong>{listing.available}</strong></td><td>{money(listing.channel_price_amount ?? listing.variant?.base_price_amount ?? null, listing.currency ?? listing.variant?.currency ?? null)}</td><td><span className={`status ${listing.status}`}>{labels[listing.status] ?? listing.status}</span></td><td>{listing.last_synced_at ? new Date(listing.last_synced_at).toLocaleString('tr-TR') : '—'}</td></tr>)}</tbody></table></div> : <div className="empty-state"><h2>Kanal ürünü bulunamadı</h2><p>Ürünleri WooCommerce veya Trendyol’a yayınladığınızda eşlemeler burada görünür.</p></div>}
            {listings.total > 0 && <div className="pagination"><span>{listings.total} kayıttan {listings.from}-{listings.to}</span><nav>{listings.links.map((link, index) => link.url ? <Link className={link.active ? 'active' : ''} href={link.url} key={index} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} dangerouslySetInnerHTML={{ __html: link.label }} />)}</nav></div>}
        </section>
    </AuthenticatedLayout>;
}
