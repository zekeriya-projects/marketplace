import { Head, Link, router, useForm, usePoll } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect } from 'react';
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout';
import ChannelLogo from '../../../Components/ChannelLogo';

type Account = { id: string; name: string; status: string; last_connected_at: string | null; credentials_configured: boolean; store_url: string | null; consumer_key_hint: string | null; shipped_status: string | null; channel: { code: string; name: string } };
type ImportOperation = { id: string; status: string; context: { total_pages?: number; processed_pages?: number[]; imported?: number; failed?: number; skipped?: number; unmapped?: number } | null; created_at: string; finished_at: string | null; safe_error_message: string | null };

export default function Show({ account, canManage, latestImport, latestOrderPull }: { account: Account; canManage: boolean; latestImport: ImportOperation | null; latestOrderPull: ImportOperation | null }) {
    const poll = usePoll(3000, { only: ['latestImport', 'latestOrderPull'] }, { autoStart: false });
    useEffect(() => {
        if (latestImport?.status === 'pending' || latestImport?.status === 'running' || latestOrderPull?.status === 'pending' || latestOrderPull?.status === 'running') poll.start(); else poll.stop();
    }, [latestImport?.status, latestOrderPull?.status]);
    const form = useForm({ store_url: account.store_url ?? '', consumer_key: '', consumer_secret: '', shipped_status: account.shipped_status ?? '' });
    const save = (event: FormEvent) => { event.preventDefault(); form.put(`/channels/accounts/${account.id}/woocommerce/credentials`, { onSuccess: () => form.reset('consumer_key', 'consumer_secret') }); };
    const test = () => router.post(`/channels/accounts/${account.id}/test`);
    const startImport = () => router.post(`/channels/accounts/${account.id}/woocommerce/import-products`);
    const pullOrders = () => router.post(`/channels/accounts/${account.id}/woocommerce/pull-orders`);

    return <AuthenticatedLayout><Head title={`${account.name} · WooCommerce`} />
        <section className="page-heading heading-actions"><div><span className="eyebrow logo-eyebrow"><ChannelLogo code="woocommerce" name="WooCommerce" size="small" />Bağlantı ayarları</span><h1>{account.name}</h1><p>Kimlik bilgileri şifreli saklanır ve kaydedildikten sonra tekrar gösterilmez.</p></div><Link className="button secondary" href="/channels">Kanallara dön</Link></section>
        <div className="settings-grid">
            <section className="panel form-panel"><h2>Bağlantı durumu</h2><div><span className="status">{account.status}</span></div>
                <p>{account.last_connected_at ? `Son bağlantı: ${new Date(account.last_connected_at).toLocaleString('tr-TR')}` : 'Henüz başarılı bağlantı testi yok.'}</p>
                <p>Kimlik bilgileri: {account.credentials_configured ? `Yapılandırıldı (${account.consumer_key_hint})` : 'Yapılandırılmadı'}</p>
                {canManage && <button className="button secondary" disabled={!account.credentials_configured} onClick={test} type="button">Bağlantıyı test et</button>}
                <Link href="/sync">Senkronizasyon geçmişi</Link>
                <div className="inline-stack"><h2>Katalog aktarımı</h2>
                    {latestImport ? <p><span className="status">{latestImport.status}</span><br />{latestImport.context?.imported ?? 0} imported · {latestImport.context?.failed ?? 0} failed · {latestImport.context?.skipped ?? 0} skipped<br />Pages: {latestImport.context?.processed_pages?.length ?? 0}/{latestImport.context?.total_pages ?? 1}{latestImport.safe_error_message ? <><br />{latestImport.safe_error_message}</> : null}</p> : <p>No catalog import has run yet.</p>}
                    {canManage && <button className="button" disabled={account.status !== 'active' || latestImport?.status === 'pending' || latestImport?.status === 'running'} onClick={startImport} type="button">Ürünleri aktar</button>}
                </div>
                <div className="inline-stack"><h2>Sipariş aktarımı</h2>
                    {latestOrderPull ? <p><span className="status">{latestOrderPull.status}</span><br />{latestOrderPull.context?.imported ?? 0} imported · {latestOrderPull.context?.failed ?? 0} failed · {latestOrderPull.context?.unmapped ?? 0} unmapped{latestOrderPull.safe_error_message ? <><br />{latestOrderPull.safe_error_message}</> : null}</p> : <p>No order pull has run yet.</p>}
                    {canManage && <button className="button" disabled={account.status !== 'active' || latestOrderPull?.status === 'pending' || latestOrderPull?.status === 'running'} onClick={pullOrders} type="button">Siparişleri aktar</button>}
                </div>
            </section>
            {canManage ? <form className="panel form-panel" onSubmit={save}><h2>{account.credentials_configured ? 'Replace credentials' : 'Configure credentials'}</h2>
                <p>Generate a read/write REST API key in WooCommerce → Settings → Advanced → REST API.</p>
                <label>Store URL<input type="url" placeholder="https://store.example.com" value={form.data.store_url} onChange={(event) => form.setData('store_url', event.target.value)} /></label>
                {form.errors.store_url && <span className="error">{form.errors.store_url}</span>}
                <label>Consumer key<input autoComplete="off" placeholder="ck_…" value={form.data.consumer_key} onChange={(event) => form.setData('consumer_key', event.target.value)} /></label>
                {form.errors.consumer_key && <span className="error">{form.errors.consumer_key}</span>}
                <label>Consumer secret<input autoComplete="new-password" placeholder="cs_…" type="password" value={form.data.consumer_secret} onChange={(event) => form.setData('consumer_secret', event.target.value)} /></label>
                {form.errors.consumer_secret && <span className="error">{form.errors.consumer_secret}</span>}
                <label>Kargoya teslim durum kodu<input placeholder="Örn. wc-shipped" value={form.data.shipped_status} onChange={(event) => form.setData('shipped_status', event.target.value)} /><small>Boş bırakılırsa WooCommerce varsayılan API’sinde “Kargoya teslim edildi” seçeneği gösterilmez. Kargo eklentinizin gerçek durum kodunu girin.</small></label>
                {form.errors.shipped_status && <span className="error">{form.errors.shipped_status}</span>}
                <button className="button" disabled={form.processing}>Save credentials</button>
            </form> : <section className="panel"><h2>Credentials</h2><p>You have read-only access. Ask an owner, admin, or operator to update this connection.</p></section>}
        </div>
    </AuthenticatedLayout>;
}
