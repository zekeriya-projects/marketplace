import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import ChannelLogo from '../../../Components/ChannelLogo';
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout';

type Account = { id: string; name: string; status: string; last_connected_at: string | null; credentials_configured: boolean; store_url: string | null; member_code_hint: string | null; channel: { code: string; name: string } };
const statusLabel = (value: string) => ({ pending: 'Bekliyor', active: 'Aktif', error: 'Hatalı' }[value] ?? value);

export default function Show({ account, canManage }: { account: Account; canManage: boolean }) {
    const form = useForm({ store_url: account.store_url ?? '', member_code: '' });
    const save = (event: FormEvent) => { event.preventDefault(); form.put(`/channels/accounts/${account.id}/ticimax/credentials`, { onSuccess: () => form.reset('member_code') }); };
    const test = () => router.post(`/channels/accounts/${account.id}/test`);

    return <AuthenticatedLayout><Head title={`${account.name} · Ticimax`} />
        <section className="page-heading heading-actions"><div><span className="eyebrow logo-eyebrow"><ChannelLogo code="ticimax" name="Ticimax" size="small" />Bağlantı ayarları</span><h1>{account.name}</h1><p>Mağaza adresi ve web servis üye kodu güvenli biçimde saklanır.</p></div><Link className="button secondary" href="/channels?category=ecommerce">E-ticaret kanallarına dön</Link></section>
        <div className="settings-grid"><section className="panel form-panel"><h2>Bağlantı durumu</h2><div><span className={`status ${account.status}`}>{statusLabel(account.status)}</span></div><p>{account.last_connected_at ? `Son bağlantı: ${new Date(account.last_connected_at).toLocaleString('tr-TR')}` : 'Henüz başarılı bağlantı testi yok.'}</p><p>Kimlik bilgileri: {account.credentials_configured ? `Yapılandırıldı (${account.member_code_hint})` : 'Yapılandırılmadı'}</p>{canManage && <button className="button secondary" disabled={!account.credentials_configured} onClick={test} type="button">Bağlantıyı test et</button>}<Link href="/sync">Senkronizasyon geçmişi</Link></section>
            {canManage ? <form className="panel form-panel" onSubmit={save}><h2>{account.credentials_configured ? 'Kimlik bilgilerini değiştir' : 'Kimlik bilgilerini yapılandır'}</h2><p>Ticimax panelinde Modüller → Web Servis Yönetimi alanından yalnızca gereken ürün ve sipariş yetkilerine sahip bir kod oluşturun.</p><label>Mağaza adresi<input placeholder="https://magaza.example.com" value={form.data.store_url} onChange={event => form.setData('store_url', event.target.value)} /></label>{form.errors.store_url && <span className="error">{form.errors.store_url}</span>}<label>Web servis üye kodu<input autoComplete="new-password" type="password" value={form.data.member_code} onChange={event => form.setData('member_code', event.target.value)} /></label>{form.errors.member_code && <span className="error">{form.errors.member_code}</span>}<button className="button" disabled={form.processing}>Kimlik bilgilerini kaydet</button></form> : <section className="panel"><h2>Kimlik bilgileri</h2><p>Salt okunur erişiminiz var. Bağlantıyı güncellemek için yetkili bir kullanıcıya başvurun.</p></section>}
        </div>
    </AuthenticatedLayout>;
}
