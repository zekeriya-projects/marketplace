import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { PropsWithChildren } from 'react';
import type { SharedProps } from '../types';

export default function PlatformLayout({ children }: PropsWithChildren) {
    const { auth, flash } = usePage<SharedProps>().props;
    const initials = auth.user?.name.split(' ').map((part) => part[0]).slice(0, 2).join('').toUpperCase();
    const [mobileOpen, setMobileOpen] = useState(false);
    const path = typeof window === 'undefined' ? '' : window.location.pathname;

    return <div className="app-shell platform-shell">
        <aside className={`sidebar ${mobileOpen ? 'open' : ''}`}>
            <Link className="brand" href="/platform/dashboard"><span className="brand-mark">M</span><span>Merkez<span>io</span></span></Link>
            <nav className="sidebar-nav" aria-label="SaaS yönetimi">
                <section className="nav-group"><h2>Platform</h2><Link className={`nav-link ${path === '/platform/dashboard' ? 'active' : ''}`} href="/platform/dashboard" onClick={() => setMobileOpen(false)}><span className="platform-nav-icon">⌂</span><span>Dashboard</span></Link><Link className={`nav-link ${path.startsWith('/platform/organizations') ? 'active' : ''}`} href="/platform/organizations" onClick={() => setMobileOpen(false)}><span className="platform-nav-icon">◇</span><span>Organizasyonlar</span></Link></section>
                <section className="nav-group"><h2>Ticari Yönetim</h2><Link className={`nav-link ${path.startsWith('/platform/plans') ? 'active' : ''}`} href="/platform/plans" onClick={() => setMobileOpen(false)}><span className="platform-nav-icon">▣</span><span>Paket Yönetimi</span></Link><Link className={`nav-link ${path.startsWith('/platform/subscriptions') ? 'active' : ''}`} href="/platform/subscriptions" onClick={() => setMobileOpen(false)}><span className="platform-nav-icon">↻</span><span>Abonelikler</span></Link></section>
            </nav>
            <div className="sidebar-footer"><div className="version">SaaS Yönetim Paneli</div></div>
        </aside>
        {mobileOpen && <button aria-label="Menüyü kapat" className="sidebar-backdrop" onClick={() => setMobileOpen(false)} />}
        <div className="workspace">
            <header className="topbar">
                <button aria-label="Menüyü aç" className="menu-toggle" onClick={() => setMobileOpen(true)}>☰</button>
                <div className="topbar-context"><span>Yönetim alanı</span><strong>SaaS Platformu</strong></div>
                <nav className="topbar-actions" aria-label="Hesap menüsü">
                    <div className="profile"><span className="avatar">{initials}</span><div><strong>{auth.user?.name}</strong><small>SaaS Yöneticisi</small></div></div>
                    <Link as="button" className="logout-link platform-logout" href="/logout" method="post" title="Çıkış yap">Çıkış</Link>
                </nav>
            </header>
            <main className="content platform-content">{flash.success && <div className="notice success">{flash.success}</div>}{children}</main>
        </div>
    </div>;
}
