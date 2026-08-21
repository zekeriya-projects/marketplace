import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { PropsWithChildren, ReactNode } from 'react';
import type { SharedProps } from '../types';

const Icon = ({ children }: { children: ReactNode }) => <svg aria-hidden="true" viewBox="0 0 24 24">{children}</svg>;
const icons = {
    home: <Icon><path d="m3 11 9-8 9 8v9a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1Z" /></Icon>,
    box: <Icon><path d="m4 7 8-4 8 4-8 4Zm0 0v10l8 4 8-4V7M12 11v10" /></Icon>,
    category: <Icon><path d="M4 5h6v6H4zM14 5h6v6h-6zM4 15h6v4H4zM14 15h6v4h-6z" /></Icon>,
    brand: <Icon><path d="M20 13 13 20 4 11V4h7l9 9Z" /><circle cx="8" cy="8" r="1" /></Icon>,
    attributes: <Icon><path d="M4 7h10M18 7h2M4 17h2M10 17h10" /><circle cx="16" cy="7" r="2" /><circle cx="8" cy="17" r="2" /></Icon>,
    template: <Icon><path d="M5 3h14v18H5zM8 7h8M8 11h8M8 15h5" /></Icon>,
    variants: <Icon><circle cx="8" cy="8" r="4" /><circle cx="16" cy="16" r="4" /><path d="m11 11 2 2" /></Icon>,
    warehouse: <Icon><path d="m3 10 9-6 9 6v10H3zM7 20v-6h10v6M7 10h.01M12 10h.01M17 10h.01" /></Icon>,
    stock: <Icon><path d="M4 19V9m6 10V5m6 14v-7m4 7H2" /></Icon>,
    orders: <Icon><path d="M6 3h12v18H6zM9 8h6M9 12h6M9 16h4" /></Icon>,
    channel: <Icon><circle cx="6" cy="12" r="3" /><circle cx="18" cy="6" r="3" /><circle cx="18" cy="18" r="3" /><path d="m9 11 6-4m-6 6 6 4" /></Icon>,
    sync: <Icon><path d="M20 7h-6V1m-10 16h6v6M19 12a7 7 0 0 0-12-5l-3 3m1 2a7 7 0 0 0 12 5l3-3" /></Icon>,
    settings: <Icon><circle cx="12" cy="12" r="3" /><path d="M19.4 15a2 2 0 0 0 .4 2.2l.1.1-2.6 2.6-.1-.1a2 2 0 0 0-2.2-.4 2 2 0 0 0-1.2 1.8V21h-3.6v-.2A2 2 0 0 0 9 19a2 2 0 0 0-2.2.4l-.1.1-2.6-2.6.1-.1A2 2 0 0 0 4.6 15a2 2 0 0 0-1.8-1.2H3v-3.6h.2A2 2 0 0 0 5 9a2 2 0 0 0-.4-2.2l-.1-.1 2.6-2.6.1.1A2 2 0 0 0 9 4.6a2 2 0 0 0 1.2-1.8V3h3.6v.2A2 2 0 0 0 15 5a2 2 0 0 0 2.2-.4l.1-.1 2.6 2.6-.1.1A2 2 0 0 0 19.4 9a2 2 0 0 0 1.8 1.2h.2v3.6h-.2A2 2 0 0 0 19.4 15Z" /></Icon>,
};

const groups = [
    { label: 'Genel', items: [{ label: 'Anasayfa', href: '/dashboard', icon: icons.home }] },
    { label: 'Katalog', collapsible: true, items: [{ label: 'Ürünler', href: '/products', icon: icons.box }, { label: 'Kategoriler', href: '/categories', icon: icons.category }, { label: 'Markalar', href: '/brands', icon: icons.brand }, { label: 'Varyant Tanımları', href: '/variant-definitions', icon: icons.attributes }, { label: 'Varyant Şablonları', href: '/variant-templates', icon: icons.template }, { label: 'Ürün Varyantları', href: '/variants', icon: icons.variants }, { label: 'Stok ve Depolar', href: '/inventory', icon: icons.warehouse }] },
    { label: 'Aktarım', collapsible: true, items: [{ label: 'Excel - XML Aktarım', href: '/imports', icon: icons.sync }, { label: 'Excel Şablonları', href: '/import-templates', icon: icons.template }] },
    { label: 'Satışlar', collapsible: true, items: [{ label: 'Tüm Siparişler', href: '/orders', icon: icons.orders }, { label: 'Yeni', href: '/orders?status=pending', icon: icons.orders, compact: true }, { label: 'Hazırlanıyor', href: '/orders?status=processing', icon: icons.orders, compact: true }, { label: 'Kargoya Teslim', href: '/orders?status=shipped', icon: icons.orders, compact: true }, { label: 'İptal / İade', href: '/orders?status=cancelled', icon: icons.orders, compact: true }, { label: 'Teslim Edilenler', href: '/orders?status=delivered', icon: icons.orders, compact: true }, { label: 'Detaylı Raporlar', href: '/reports', icon: icons.stock }] },
    { label: 'Entegrasyonlar', collapsible: true, items: [{ label: 'Pazaryeri', href: '/channels?category=marketplace', icon: icons.channel }, { label: 'E-ticaret', href: '/channels?category=ecommerce', icon: icons.channel }, { label: 'Kargo', href: '/channels?category=shipping', icon: icons.channel }] },
    { label: 'Kanal İşlemleri', collapsible: true, items: [{ label: 'Pazaryeri Ürünleri', href: '/listings', icon: icons.box }, { label: 'İşlem Günlüğü', href: '/sync', icon: icons.sync }] },
    { label: 'Yönetim', items: [{ label: 'Organizasyon', href: '/settings/organizations/current', icon: icons.settings, tenant: true }] },
];

export default function AuthenticatedLayout({ children }: PropsWithChildren) {
    const { auth, flash } = usePage<SharedProps>().props;
    const path = typeof window === 'undefined' ? '' : window.location.pathname;
    const currentUrl = typeof window === 'undefined' ? '' : `${window.location.pathname}${window.location.search}`;
    const [mobileOpen, setMobileOpen] = useState(false);
    const [openGroups, setOpenGroups] = useState<Record<string, boolean>>({ Katalog: true, Aktarım: true, Satışlar: true, Entegrasyonlar: true, 'Kanal İşlemleri': true });
    const activeTenant = auth.tenants.find((tenant) => tenant.id === auth.activeTenantId);
    const initials = auth.user?.name.split(' ').map((part) => part[0]).slice(0, 2).join('').toUpperCase();
    const switchTenant = (tenantId: string) => { if (tenantId !== auth.activeTenantId) router.post(`/settings/organizations/${tenantId}/switch`); };
    const navigationGroups = auth.user?.is_platform_admin
        ? [...groups, { label: 'SaaS Yönetimi', items: [{ label: 'Tüm Organizasyonlar', href: '/platform/organizations', icon: icons.settings }] }]
        : groups;

    return <div className="app-shell">
        <aside className={`sidebar ${mobileOpen ? 'open' : ''}`}>
            <Link className="brand" href="/dashboard"><span className="brand-mark">M</span><span>Merkez<span>io</span></span></Link>
            <nav className="sidebar-nav" aria-label="Ana menü">{navigationGroups.map((group) => { const collapsible = 'collapsible' in group && group.collapsible; const open = !collapsible || openGroups[group.label] !== false; return <section className={`nav-group ${collapsible ? 'collapsible' : ''} ${open ? 'open' : ''}`} key={group.label}>{collapsible ? <button className="nav-group-toggle" type="button" aria-expanded={open} onClick={() => setOpenGroups(current => ({ ...current, [group.label]: !open }))}><span>{group.label}</span><b>⌄</b></button> : <h2>{group.label}</h2>}<div className="nav-group-items">{group.items.map((item) => {
                const href = 'tenant' in item && item.tenant ? `/settings/organizations/${auth.activeTenantId}` : item.href;
                const active = item.href.includes('?') ? currentUrl === item.href || (item.href === '/channels?category=marketplace' && currentUrl === '/channels') : item.href === '/orders' ? path === '/orders' && !currentUrl.includes('status=') : item.href === '/dashboard' ? path === item.href : path.startsWith(item.href);
                return <Link className={`nav-link ${'compact' in item && item.compact ? 'compact' : ''} ${active ? 'active' : ''}`} href={href} key={item.label} onClick={() => setMobileOpen(false)}>{item.icon}<span>{item.label}</span>{item.label === 'İşlem Günlüğü' && <span className="nav-dot" />}</Link>;
            })}</div></section>; })}</nav>
            <div className="sidebar-footer"><div className="sidebar-help"><span>?</span><div><strong>Yardıma mı ihtiyacınız var?</strong><small>Dokümantasyonu inceleyin</small></div></div><div className="version">Marketplace SaaS <span>v0.10</span></div></div>
        </aside>
        {mobileOpen && <button aria-label="Menüyü kapat" className="sidebar-backdrop" onClick={() => setMobileOpen(false)} />}
        <div className="workspace">
            <header className="topbar"><button aria-label="Menüyü aç" className="menu-toggle" onClick={() => setMobileOpen(true)}>☰</button>
                <div className="topbar-context"><span>Çalışma alanı</span><strong>{activeTenant?.name ?? 'Organizasyon'}</strong></div>
                <button className="command-search" type="button"><span>⌕</span><span>Menüde ara...</span><kbd>⌘ K</kbd></button>
                <nav className="topbar-actions" aria-label="Hesap menüsü">
                    {auth.tenants.length > 1 && <select aria-label="Aktif organizasyon" value={auth.activeTenantId ?? ''} onChange={(event) => switchTenant(event.target.value)}>{auth.tenants.map((tenant) => <option key={tenant.id} value={tenant.id}>{tenant.name}</option>)}</select>}
                    <div className="profile"><span className="avatar">{initials}</span><div><strong>{auth.user?.name}</strong><small>{auth.user?.is_platform_admin ? 'SaaS Yöneticisi' : (activeTenant?.role ?? 'Üye')}</small></div></div>
                    <Link as="button" className="logout-link" href="/logout" method="post" title="Çıkış yap">↗</Link>
                </nav>
            </header>
            <main className="content">{flash.success && <div className="notice success">{flash.success}</div>}{children}</main>
        </div>
    </div>;
}
