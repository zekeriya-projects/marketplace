import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import ChannelLogo from '../../../Components/ChannelLogo';
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout';

type Account = { id: string; name: string; status: string; last_connected_at: string | null; credentials_configured: boolean; merchant_id: string | null; environment: 'production' | 'stage'; username_hint: string | null; channel: { code: string; name: string } };
const statusLabel = (value: string) => ({ pending: 'Bekliyor', active: 'Aktif', error: 'Hatalı' }[value] ?? value);

export default function Show({ account, canManage }: { account: Account; canManage: boolean }) {
    const form = useForm({ merchant_id: account.merchant_id ?? '', username: '', password: '', environment: account.environment });
    const save = (event: FormEvent) => { event.preventDefault(); form.put(`/channels/accounts/${account.id}/hepsiburada/credentials`, { onSuccess: () => form.reset('username', 'password') }); };
    const test = () => router.post(`/channels/accounts/${account.id}/test`);

    return <AuthenticatedLayout><Head title={`${account.name} · Hepsiburada`} />
        <section className="page-heading heading-actions"><div><span className="eyebrow logo-eyebrow"><ChannelLogo code="hepsiburada" name="Hepsiburada" size="small" />Bağlantı ayarları</span><h1>{account.name}</h1><p>Merchant ve servis bilgileri şifreli saklanır; parola kaydedildikten sonra tekrar gösterilmez.</p></div><Link className="button secondary" href="/channels?category=marketplace">Pazaryerlerine dön</Link></section>
        <div className="settings-grid"><section className="panel form-panel"><h2>Bağlantı durumu</h2><div><span className={`status ${account.status}`}>{statusLabel(account.status)}</span></div><p>{account.last_connected_at ? `Son bağlantı: ${new Date(account.last_connected_at).toLocaleString('tr-TR')}` : 'Henüz başarılı bağlantı testi yok.'}</p><p>Kimlik bilgileri: {account.credentials_configured ? `Yapılandırıldı (${account.username_hint})` : 'Yapılandırılmadı'}</p>{canManage && <button className="button secondary" disabled={!account.credentials_configured} onClick={test} type="button">Bağlantıyı test et</button>}<Link href="/sync">Senkronizasyon geçmişi</Link></section>
            {canManage ? <form className="panel form-panel" onSubmit={save}><h2>{account.credentials_configured ? 'Kimlik bilgilerini değiştir' : 'Kimlik bilgilerini yapılandır'}</h2><p>Merchant ID, kullanıcı adı ve parolayı Hepsiburada Satıcı Paneli entegrasyon bilgilerinden alın.</p><label>Ortam<select value={form.data.environment} onChange={event => form.setData('environment', event.target.value as 'production' | 'stage')}><option value="production">Canlı</option><option value="stage">Test (SIT)</option></select></label><label>Merchant ID<input value={form.data.merchant_id} onChange={event => form.setData('merchant_id', event.target.value)} /></label>{form.errors.merchant_id && <span className="error">{form.errors.merchant_id}</span>}<label>API kullanıcı adı<input autoComplete="off" value={form.data.username} onChange={event => form.setData('username', event.target.value)} /></label>{form.errors.username && <span className="error">{form.errors.username}</span>}<label>API parolası<input autoComplete="new-password" type="password" value={form.data.password} onChange={event => form.setData('password', event.target.value)} /></label>{form.errors.password && <span className="error">{form.errors.password}</span>}<button className="button" disabled={form.processing}>Kimlik bilgilerini kaydet</button></form> : <section className="panel"><h2>Kimlik bilgileri</h2><p>Salt okunur erişiminiz var. Bağlantıyı güncellemek için yetkili bir kullanıcıya başvurun.</p></section>}
        </div>
    </AuthenticatedLayout>;
}
