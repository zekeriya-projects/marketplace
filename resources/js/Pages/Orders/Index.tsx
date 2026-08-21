import { Head, Link, router } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import ChannelLogo from '../../Components/ChannelLogo';

type Row = { id: string; external_order_id: string; external_order_number: string | null; status: string; currency: string; total_amount: number; customer_name: string; ordered_at: string; channel: { account_name: string; name: string; code: string } };
type PageLink = { url: string | null; label: string; active: boolean };
type Props = { orders: { data: Row[]; links: PageLink[]; from: number | null; to: number | null; total: number }; accounts: { id: string; name: string; channel: string }[]; statuses: string[]; counts: Record<string, number>; filters: { channel_account: string; status: string; search: string } };
const money = (amount: number, currency: string) => new Intl.NumberFormat('tr-TR', { style: 'currency', currency }).format(amount / 100);
const labels: Record<string, string> = { pending: 'Yeni', confirmed: 'Onaylandı', processing: 'Hazırlanıyor', shipped: 'Kargoya teslim edildi', delivered: 'Teslim edildi', cancelled: 'İptal edildi', returned: 'İade edildi' };
const tabs = [{ label: 'Tümü', value: '' }, { label: 'Yeni', value: 'pending' }, { label: 'Hazırlanıyor', value: 'processing' }, { label: 'Kargolandı', value: 'shipped' }, { label: 'Teslim', value: 'delivered' }, { label: 'İptal / İade', value: 'cancelled', combined: true }];

export default function Index({ orders, accounts, counts, filters }: Props) {
    const [search, setSearch] = useState(filters.search);
    const filter = (values: Partial<Props['filters']>) => router.get('/orders', { ...filters, ...values }, { preserveState: true, replace: true });
    const submit = (event: FormEvent) => { event.preventDefault(); filter({ search }); };
    return <AuthenticatedLayout><Head title="Siparişler" />
        <section className="page-heading"><span className="eyebrow">Satış operasyonları</span><h1>Siparişler</h1><p>Bağlı tüm mağazalardan gelen siparişleri durumlarına göre yönetin.</p></section>
        <nav className="order-status-tabs" aria-label="Sipariş durumları">{tabs.map(tab => { const count = tab.combined ? (counts.cancelled ?? 0) + (counts.returned ?? 0) : tab.value ? counts[tab.value] ?? 0 : Object.values(counts).reduce((sum, value) => sum + value, 0); return <button className={(filters.status === tab.value || (tab.combined && ['cancelled', 'returned'].includes(filters.status))) ? 'active' : ''} key={tab.label} onClick={() => filter({ status: tab.value })}><span>{tab.label}</span><strong>{count}</strong></button>; })}</nav>
        <section className="panel data-table-card"><div className="data-table-toolbar"><div><strong>Tüm siparişler</strong><span>{orders.total} kayıt</span></div><form className="order-toolbar" onSubmit={submit}><input placeholder="Sipariş no veya müşteri ara" value={search} onChange={event => setSearch(event.target.value)} /><select value={filters.channel_account} onChange={event => filter({ channel_account: event.target.value })}><option value="">Tüm mağazalar</option>{accounts.map(account => <option key={account.id} value={account.id}>{account.channel} · {account.name}</option>)}</select><button className="button secondary">Ara</button></form></div>
        {orders.data.length ? <div className="table-wrap"><table className="data-table orders-table"><thead><tr><th>Pazaryeri</th><th>Sipariş no</th><th>Müşteri</th><th>Sipariş tarihi</th><th>Mağaza</th><th>Durum</th><th>Tutar</th><th /></tr></thead><tbody>{orders.data.map(order => <tr key={order.id}><td><ChannelLogo code={order.channel.code} name={order.channel.name} /></td><td><strong>{order.external_order_number ?? order.external_order_id}</strong><small>{order.external_order_id}</small></td><td>{order.customer_name || 'Müşteri bilgisi yok'}</td><td>{new Date(order.ordered_at).toLocaleString('tr-TR')}</td><td><strong>{order.channel.account_name}</strong></td><td><span className={`status ${order.status}`}>{labels[order.status] ?? order.status}</span></td><td><strong>{money(order.total_amount, order.currency)}</strong></td><td><Link className="row-detail-link" href={`/orders/${order.id}`}>Detay</Link></td></tr>)}</tbody></table></div> : <div className="empty-state"><h2>Sipariş bulunamadı</h2><p>Seçilen durum veya filtreler için sipariş bulunmuyor.</p></div>}
        {orders.total > 0 && <div className="pagination"><span>{orders.from}-{orders.to} / {orders.total}</span><nav>{orders.links.map((link, index) => link.url ? <Link className={link.active ? 'active' : ''} href={link.url} key={index} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} dangerouslySetInnerHTML={{ __html: link.label }} />)}</nav></div>}</section>
    </AuthenticatedLayout>;
}
