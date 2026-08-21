import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import ChannelLogo from '../../Components/ChannelLogo';

type Channel = { id: string; code: string; name: string; type: string; connection_available: boolean };
type Account = { id: string; name: string; status: string; last_connected_at: string | null; listings_count: number; credentials_configured: boolean; channel: { code: string; name: string } };
type Category = 'marketplace' | 'ecommerce' | 'shipping';
type Props = { category: Category; channels: Channel[]; accounts: Account[]; canManage: boolean };

const categoryCopy: Record<Category, { eyebrow: string; title: string; description: string; empty: string }> = {
    marketplace: { eyebrow: 'Pazaryeri entegrasyonları', title: 'Pazaryerleri', description: 'Pazaryeri hesaplarınızı bağlayın; ürün, stok, fiyat ve sipariş akışlarını yönetin.', empty: 'Henüz desteklenen bir pazaryeri entegrasyonu yok.' },
    ecommerce: { eyebrow: 'E-ticaret entegrasyonları', title: 'E-ticaret siteleri', description: 'Kendi e-ticaret mağazalarınızı merkezi kataloğa bağlayın ve senkronizasyonlarını yönetin.', empty: 'Henüz desteklenen bir e-ticaret entegrasyonu yok.' },
    shipping: { eyebrow: 'Kargo entegrasyonları', title: 'Kargo', description: 'Kargo firması bağlantıları ve gönderi akışları bu alanda yönetilecek.', empty: 'Kargo entegrasyonları yakında eklenecek.' },
};

const channelDescriptions: Record<string, string> = {
    amazon: 'Amazon Türkiye ve Avrupa mağazaları için SP-API entegrasyonu. Satıcı yetkilendirmesi LWA OAuth üzerinden hazırlanıyor.',
    hepsiburada: 'Hepsiburada merchant ürün, stok, fiyat ve sipariş entegrasyonu hazırlanıyor.',
    trendyol: 'Ürün, stok, fiyat ve siparişlerinizi Trendyol ile yönetin.',
    woocommerce: 'Kendi e-ticaret mağazanızı merkezi kataloğa bağlayın.',
    ticimax: 'Ticimax mağazanızın stok ve fiyat servislerini merkezi kataloğa bağlayın.',
};

export default function Index({ category, channels, accounts, canManage }: Props) {
    const copy = categoryCopy[category];
    const [selected, setSelected] = useState<Channel | null>(null);
    const form = useForm({ channel_id: '', name: '' });
    const open = (channel: Channel) => { setSelected(channel); form.setData({ channel_id: channel.id, name: `${channel.name} mağazası` }); };
    const submit = (event: FormEvent) => { event.preventDefault(); form.post('/channels/accounts', { onSuccess: () => { form.reset(); setSelected(null); } }); };
    const accountFor = (channel: Channel) => accounts.filter(account => account.channel.code === channel.code);

    return <AuthenticatedLayout><Head title={copy.title} />
        <section className="page-heading"><span className="eyebrow">{copy.eyebrow}</span><h1>{copy.title}</h1><p>{copy.description}</p></section>
        <section className="channel-summary"><div><span>Aktif mağaza</span><strong>{accounts.filter(account => account.status === 'active').length}</strong></div><div><span>Bağlı ürün</span><strong>{accounts.reduce((sum, account) => sum + account.listings_count, 0)}</strong></div><div><span>Desteklenen kanal</span><strong>{channels.length}</strong></div></section>
        {channels.length === 0 ? <section className="panel empty-state"><h2>{copy.empty}</h2><p>Yeni sağlayıcılar eklendiğinde bu kategori altında listelenecek.</p></section> : <section className="marketplace-grid">{channels.map(channel => { const channelAccounts = accountFor(channel); return <article className={`marketplace-card ${channel.code}`} key={channel.id}><header><ChannelLogo code={channel.code} name={channel.name} size="large" /><div><h2 className="sr-only">{channel.name}</h2><p>{channelDescriptions[channel.code] ?? `${channel.name} entegrasyonunu yönetin.`}</p></div></header><div className="marketplace-account-list">{channelAccounts.map(account => <div className="marketplace-account" key={account.id}><div><strong>{account.name}</strong><small>{account.listings_count} ürün · {account.credentials_configured ? 'Bilgiler kayıtlı' : 'Kurulum bekliyor'}</small></div><span className={`status ${account.status}`}>{account.status === 'active' ? 'Aktif' : account.status === 'error' ? 'Hatalı' : 'Pasif'}</span><Link className="button secondary small" href={`/channels/accounts/${account.id}`}>Yönet</Link></div>)}</div>{channelAccounts.length === 0 && <div className="marketplace-empty"><span>{channel.connection_available ? 'Henüz mağaza bağlı değil' : 'Bağlantı altyapısı hazırlanıyor'}</span></div>}{canManage && (channel.connection_available ? <button className="marketplace-connect" onClick={() => open(channel)}>+ {channelAccounts.length ? 'Yeni mağaza bağla' : 'Entegrasyonu başlat'}</button> : <button className="marketplace-connect" type="button" disabled>Yakında</button>)}</article>; })}</section>}
        {selected && <div className="modal-layer"><button className="modal-backdrop" aria-label="Kapat" onClick={() => setSelected(null)} /><section className="modal-card" role="dialog" aria-modal="true"><header><div><span className="eyebrow">Yeni entegrasyon</span><h2>{selected.name} mağazası ekle</h2><p>Önce mağazaya panelde kullanacağınız bir ad verin. API bilgilerini sonraki adımda güvenli olarak kaydedeceksiniz.</p></div><button className="modal-close" onClick={() => setSelected(null)}>×</button></header><form className="form-panel" onSubmit={submit}><label>Mağaza adı<input autoFocus value={form.data.name} onChange={event => form.setData('name', event.target.value)} placeholder="Ana mağaza" /></label>{Object.values(form.errors).map(error => <span className="error" key={error}>{error}</span>)}<footer><button className="button secondary" type="button" onClick={() => setSelected(null)}>Vazgeç</button><button className="button" disabled={form.processing}>Devam et</button></footer></form></section></div>}
    </AuthenticatedLayout>;
}
