import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import PlatformLayout from '../../Layouts/PlatformLayout';

type Tenant = {
    id: string; name: string; slug: string; status: 'active' | 'disabled';
    subscription_plan: string;
    subscription_status: 'trialing' | 'active' | 'past_due' | 'suspended' | 'cancelled';
    users_count: number; products_count: number; channel_accounts_count: number; orders_count: number;
};

type Props = { tenants: { data: Tenant[]; current_page: number; last_page: number; prev_page_url: string | null; next_page_url: string | null }; plans: Array<{code:string;name:string}> };

export default function Tenants({ tenants, plans }: Props) {
    const [creating, setCreating] = useState(false);
    const form = useForm({ name: '', slug: '', owner_email: '', subscription_plan: 'trial', subscription_status: 'trialing' });
    const submit = (event: FormEvent) => { event.preventDefault(); form.post('/platform/organizations', { onSuccess: () => setCreating(false) }); };
    const update = (tenant: Tenant, changes: Partial<Tenant>) => router.put(`/platform/organizations/${tenant.id}`, {
        status: tenant.status,
        subscription_plan: tenant.subscription_plan,
        subscription_status: tenant.subscription_status,
        ...changes,
    }, { preserveScroll: true });

    return <PlatformLayout>
        <Head title="SaaS Organizasyonları" />
        <section className="page-heading heading-actions"><div><span className="eyebrow">SaaS yönetimi</span><h1>Organizasyonlar ve abonelikler</h1><p>Platform müşterilerinin erişim ve abonelik durumlarını yönetin.</p></div><button className="button" type="button" onClick={() => setCreating(true)}>+ Organizasyon ekle</button></section>
        <section className="panel table-wrap"><table>
            <thead><tr><th>Organizasyon</th><th>Üye</th><th>Ürün</th><th>Kanal</th><th>Sipariş</th><th>Plan</th><th>Abonelik</th><th>Erişim</th></tr></thead>
            <tbody>{tenants.data.map((tenant) => <tr key={tenant.id}>
                <td><Link href={`/platform/organizations/${tenant.id}`}><strong>{tenant.name}</strong><small>{tenant.slug}</small></Link></td>
                <td>{tenant.users_count}</td><td>{tenant.products_count}</td><td>{tenant.channel_accounts_count}</td><td>{tenant.orders_count}</td>
                <td><select aria-label={`${tenant.name} planı`} value={tenant.subscription_plan} onChange={(event) => update(tenant, { subscription_plan: event.target.value as Tenant['subscription_plan'] })}>{plans.map(plan=><option value={plan.code} key={plan.code}>{plan.name}</option>)}</select></td>
                <td><select aria-label={`${tenant.name} aboneliği`} value={tenant.subscription_status} onChange={(event) => update(tenant, { subscription_status: event.target.value as Tenant['subscription_status'] })}><option value="trialing">Deneme</option><option value="active">Aktif</option><option value="past_due">Ödeme gecikmiş</option><option value="suspended">Askıda</option><option value="cancelled">İptal</option></select></td>
                <td><select aria-label={`${tenant.name} erişimi`} value={tenant.status} onChange={(event) => update(tenant, { status: event.target.value as Tenant['status'] })}><option value="active">Aktif</option><option value="disabled">Devre dışı</option></select></td>
            </tr>)}</tbody>
        </table>{tenants.data.length === 0 && <div className="empty-state">Organizasyon bulunamadı.</div>}</section>
        {tenants.last_page > 1 && <div className="pagination"><span>Sayfa {tenants.current_page} / {tenants.last_page}</span><nav>{tenants.prev_page_url && <a href={tenants.prev_page_url}>Önceki</a>}{tenants.next_page_url && <a href={tenants.next_page_url}>Sonraki</a>}</nav></div>}
        {creating && <><button className="drawer-backdrop" aria-label="Kapat" onClick={() => setCreating(false)} /><aside className="form-drawer"><header><div><span className="eyebrow">Yeni müşteri</span><h2>Organizasyon oluştur</h2><small>Owner olarak mevcut, platform yöneticisi olmayan bir kullanıcı atanır.</small></div><button onClick={() => setCreating(false)}>×</button></header><form className="form-panel" onSubmit={submit}><label>Ad<input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></label>{form.errors.name && <span className="error">{form.errors.name}</span>}<label>Slug<input value={form.data.slug} onChange={(e) => form.setData('slug', e.target.value)} /></label>{form.errors.slug && <span className="error">{form.errors.slug}</span>}<label>Owner e-posta<input type="email" value={form.data.owner_email} onChange={(e) => form.setData('owner_email', e.target.value)} /></label>{form.errors.owner_email && <span className="error">{form.errors.owner_email}</span>}<label>Plan<select value={form.data.subscription_plan} onChange={(e) => form.setData('subscription_plan', e.target.value)}>{plans.map(plan=><option value={plan.code} key={plan.code}>{plan.name}</option>)}</select></label><label>Abonelik<select value={form.data.subscription_status} onChange={(e) => form.setData('subscription_status', e.target.value)}><option value="trialing">Deneme</option><option value="active">Aktif</option></select></label><footer><button className="button secondary" type="button" onClick={() => setCreating(false)}>Vazgeç</button><button className="button" disabled={form.processing}>Oluştur</button></footer></form></aside></>}
    </PlatformLayout>;
}
