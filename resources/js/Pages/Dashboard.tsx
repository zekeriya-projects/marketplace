import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '../Layouts/AuthenticatedLayout';
import ChannelLogo from '../Components/ChannelLogo';
import { syncOperationLabel, syncStatusLabel } from '../lib/syncLabels';

type Props = {
    tenant: { id: string; name: string; slug: string };
    stats: { today_orders: number; products: number; active_channels: number; failed_operations: number };
    weeklySales: { label: string; date: string; amount: number }[];
    channels: { id: string; name: string; provider: string; code: string; status: string }[];
    recentOperations: { id: string; operation: string; status: string; account: string; channel: string; created_at: string }[];
};

const money = (amount: number) => new Intl.NumberFormat('tr-TR', { style: 'currency', currency: 'TRY', maximumFractionDigits: 0 }).format(amount / 100);

export default function Dashboard({ tenant, stats, weeklySales, channels, recentOperations }: Props) {
    const max = Math.max(...weeklySales.map((day) => day.amount), 1);
    const total = weeklySales.reduce((sum, day) => sum + day.amount, 0);
    const cards = [
        { label: 'Bugünkü Siparişler', value: stats.today_orders, note: 'Yeni sipariş', tone: 'violet', icon: '↗' },
        { label: 'Katalog', value: stats.products, note: 'Toplam ürün', tone: 'blue', icon: '◇' },
        { label: 'Aktif Kanallar', value: stats.active_channels, note: 'Bağlı hesap', tone: 'green', icon: '⌁' },
        { label: 'Hatalı İşlemler', value: stats.failed_operations, note: 'İncelenmeli', tone: 'orange', icon: '!' },
    ];

    return <AuthenticatedLayout><Head title="Anasayfa" />
        <section className="dashboard-welcome"><div><span className="eyebrow">Genel bakış</span><h1>Merhaba, {tenant.name}</h1><p>Satış kanallarınızın bugünkü durumunu tek ekrandan takip edin.</p></div><div className="dashboard-actions"><Link className="button secondary" href="/channels">Entegrasyonları Yönet</Link><Link className="button" href="/products/create">+ Yeni Ürün</Link></div></section>

        <section className="metric-grid">{cards.map((card) => <article className="metric-card" key={card.label}><span className={`metric-icon ${card.tone}`}>{card.icon}</span><div><small>{card.label}</small><strong>{card.value}</strong><span>{card.note}</span></div></article>)}</section>

        <section className="dashboard-grid">
            <article className="panel sales-panel"><div className="panel-title"><div><span className="eyebrow">Performans</span><h2>Haftalık Satış Analizi</h2></div><div className="sales-total"><span>Toplam satış</span><strong>{money(total)}</strong></div></div>
                <div className="bar-chart" role="img" aria-label="Son yedi gün satış grafiği">{weeklySales.map((day) => <div className="bar-column" key={day.date}><div className="bar-track"><span style={{ height: `${Math.max((day.amount / max) * 100, day.amount > 0 ? 8 : 2)}%` }} title={money(day.amount)} /></div><strong>{day.label}</strong><small>{day.date}</small></div>)}</div>
            </article>
            <article className="panel connection-panel"><div className="panel-title"><div><span className="eyebrow">Pazaryerleri</span><h2>Bağlantılar</h2></div><Link href="/channels">Tümünü gör</Link></div>
                {channels.length ? <div className="connection-list">{channels.map((channel) => <Link href={`/channels/accounts/${channel.id}`} key={channel.id}><ChannelLogo code={channel.code} name={channel.provider} /><div><strong>{channel.name}</strong></div><span className={`connection-state ${channel.status}`}>{channel.status}</span></Link>)}</div> : <div className="dashboard-empty"><span>⌁</span><strong>Henüz bağlantı yok</strong><p>WooCommerce veya Trendyol hesabınızı bağlayarak başlayın.</p><Link className="button small" href="/channels">Kanal Ekle</Link></div>}
            </article>
        </section>

        <section className="panel activity-panel"><div className="panel-title"><div><span className="eyebrow">Operasyon</span><h2>Son İşlemler</h2></div><Link href="/sync">İşlem günlüğü</Link></div>
            {recentOperations.length ? <div className="activity-list">{recentOperations.map((operation) => <div className="activity-row" key={operation.id}><span className={`activity-mark ${operation.status}`} /><div><strong>{syncOperationLabel(operation.operation)}</strong><small>{operation.channel} · {operation.account}</small></div><span className="status">{syncStatusLabel(operation.status)}</span><time>{new Date(operation.created_at).toLocaleString('tr-TR')}</time></div>)}</div> : <div className="empty-inline"><span>✓</span><div><strong>Her şey sakin</strong><p>İlk entegrasyon işleminiz burada görünecek.</p></div></div>}
        </section>
    </AuthenticatedLayout>;
}
