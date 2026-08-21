import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout';
import ChannelLogo from '../../../Components/ChannelLogo';

type Account = { id: string; name: string; status: string; last_connected_at: string | null; credentials_configured: boolean; seller_id: string | null; environment: 'production' | 'stage'; api_key_hint: string | null; channel: { code: string; name: string } };
type OrderPull = { status: string; context?: { imported?: number; failed?: number; unmapped?: number }; created_at: string; safe_error_message?: string | null } | null;
const durum = (value: string) => ({ pending: 'Bekliyor', active: 'Aktif', error: 'Hatalı', running: 'Çalışıyor', succeeded: 'Başarılı', failed: 'Başarısız' }[value] ?? value);

export default function Show({ account, canManage, latestOrderPull }: { account: Account; canManage: boolean; latestOrderPull: OrderPull }) {
    const form = useForm({ seller_id: account.seller_id ?? '', api_key: '', api_secret: '', environment: account.environment });
    const save = (event: FormEvent) => { event.preventDefault(); form.put(`/channels/accounts/${account.id}/trendyol/credentials`, { onSuccess: () => form.reset('api_key', 'api_secret') }); };
    const test = () => router.post(`/channels/accounts/${account.id}/test`);
    const pullOrders = () => router.post(`/channels/accounts/${account.id}/trendyol/pull-orders`);
    return <AuthenticatedLayout><Head title={`${account.name} · Trendyol`} />
        <section className="page-heading heading-actions"><div><span className="eyebrow logo-eyebrow"><ChannelLogo code="trendyol" name="Trendyol" size="small" />Bağlantı ayarları</span><h1>{account.name}</h1><p>Satıcı bilgileri şifreli saklanır ve kaydedildikten sonra tekrar gösterilmez.</p></div><Link className="button secondary" href="/channels">Kanallara dön</Link></section>
        <div className="settings-grid"><section className="panel form-panel"><h2>Bağlantı durumu</h2><div><span className="status">{durum(account.status)}</span></div>
            <p>{account.last_connected_at ? `Son bağlantı: ${new Date(account.last_connected_at).toLocaleString('tr-TR')}` : 'Henüz başarılı bağlantı testi yok.'}</p>
            <p>Kimlik bilgileri: {account.credentials_configured ? `Yapılandırıldı (${account.api_key_hint})` : 'Yapılandırılmadı'}</p>
            {canManage && <button className="button secondary" disabled={!account.credentials_configured} onClick={test} type="button">Bağlantıyı test et</button>}<Link href="/sync">Senkronizasyon geçmişi</Link></section>
            <section className="panel form-panel"><h2>Sipariş aktarımı</h2><p>Yeni ve değişen sipariş paketleri her beş dakikada otomatik alınır. Gerektiğinde aktarımı elle başlatabilirsiniz.</p>
                {latestOrderPull ? <div><span className="status">{durum(latestOrderPull.status)}</span><p>{latestOrderPull.context?.imported ?? 0} sipariş · {latestOrderPull.context?.unmapped ?? 0} eşleşmeyen satır · {latestOrderPull.context?.failed ?? 0} hata</p>{latestOrderPull.safe_error_message && <span className="error">{latestOrderPull.safe_error_message}</span>}</div> : <p>Henüz sipariş aktarımı yapılmadı.</p>}
                {canManage && <button className="button" disabled={account.status !== 'active' || latestOrderPull?.status === 'running' || latestOrderPull?.status === 'pending'} onClick={pullOrders} type="button">Siparişleri şimdi çek</button>}
            </section>
            {canManage ? <form className="panel form-panel" onSubmit={save}><h2>{account.credentials_configured ? 'Kimlik bilgilerini değiştir' : 'Kimlik bilgilerini yapılandır'}</h2>
                <p>Bilgileri Trendyol Satıcı Paneli → Hesap Bilgileri → Entegrasyon Bilgileri bölümünden kopyalayın.</p>
                <label>Ortam<select value={form.data.environment} onChange={(event) => form.setData('environment', event.target.value as 'production' | 'stage')}><option value="production">Canlı</option><option value="stage">Test</option></select></label>
                <label>Satıcı ID<input inputMode="numeric" value={form.data.seller_id} onChange={(event) => form.setData('seller_id', event.target.value)} /></label>{form.errors.seller_id && <span className="error">{form.errors.seller_id}</span>}
                <label>API anahtarı<input autoComplete="off" value={form.data.api_key} onChange={(event) => form.setData('api_key', event.target.value)} /></label>{form.errors.api_key && <span className="error">{form.errors.api_key}</span>}
                <label>API gizli anahtarı<input autoComplete="new-password" type="password" value={form.data.api_secret} onChange={(event) => form.setData('api_secret', event.target.value)} /></label>{form.errors.api_secret && <span className="error">{form.errors.api_secret}</span>}
                <button className="button" disabled={form.processing}>Kimlik bilgilerini kaydet</button>
            </form> : <section className="panel"><h2>Kimlik bilgileri</h2><p>Salt okunur erişiminiz var. Bağlantıyı güncellemek için yetkili bir kullanıcıya başvurun.</p></section>}
        </div>
    </AuthenticatedLayout>;
}
