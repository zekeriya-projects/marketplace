import { Head, Link } from '@inertiajs/react';
import PlatformLayout from '../../Layouts/PlatformLayout';

type Props = {
    stats: { organizations: number; activeOrganizations: number; users: number; activeSubscriptions: number; products: number; orders: number; channels: number };
    recentTenants: Array<{ id: string; name: string; slug: string; status: string; subscription_plan: string; subscription_status: string; users_count: number }>;
};

export default function Dashboard({ stats, recentTenants }: Props) {
    const cards = [
        ['Organizasyonlar', stats.organizations, `${stats.activeOrganizations} aktif`],
        ['Platform kullanıcıları', stats.users, 'Merchant hesapları'],
        ['Aktif abonelikler', stats.activeSubscriptions, 'Ödeme sistemi hariç'],
        ['Toplam ürün', stats.products, `${stats.orders} sipariş · ${stats.channels} kanal`],
    ];
    return <PlatformLayout><Head title="SaaS Dashboard" />
        <section className="dashboard-welcome"><div><span className="eyebrow">SaaS yönetimi</span><h1>Platform dashboard</h1><p>Organizasyon, kullanıcı ve abonelik durumlarının genel görünümü.</p></div><Link className="button" href="/platform/organizations">Organizasyon ekle</Link></section>
        <section className="metric-grid">{cards.map(([label, value, note]) => <article className="metric-card" key={label}><span className="metric-icon violet">◇</span><div><small>{label}</small><strong>{value}</strong><span>{note}</span></div></article>)}</section>
        <section className="panel"><div className="panel-title"><div><span className="eyebrow">Son kayıtlar</span><h2>Yeni organizasyonlar</h2></div><Link href="/platform/organizations">Tümünü gör</Link></div><div className="member-list">{recentTenants.map((tenant) => <Link className="member" href={`/platform/organizations/${tenant.id}`} key={tenant.id}><div><strong>{tenant.name}</strong><span>{tenant.slug} · {tenant.users_count} üye</span></div><span className="status">{tenant.subscription_plan} / {tenant.subscription_status}</span></Link>)}</div></section>
    </PlatformLayout>;
}
