import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function Login() {
    const form = useForm({ email: '', password: '', remember: false });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    };

    return (
        <main className="auth-shell">
            <Head title="Giriş yap" />
            <form className="auth-card" onSubmit={submit}>
                <div>
                    <span className="eyebrow">Marketplace SaaS</span>
                    <h1>Tekrar hoş geldiniz</h1>
                    <p>Organizasyon çalışma alanınıza giriş yapın.</p>
                </div>
                <label>Email<input autoComplete="email" autoFocus type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} /></label>
                {form.errors.email && <span className="error">{form.errors.email}</span>}
                <label>Şifre<input autoComplete="current-password" type="password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} /></label>
                {form.errors.password && <span className="error">{form.errors.password}</span>}
                <label className="checkbox"><input type="checkbox" checked={form.data.remember} onChange={(e) => form.setData('remember', e.target.checked)} /> Beni hatırla</label>
                <button className="button" disabled={form.processing} type="submit">Giriş yap</button>
                <p className="form-footer">Hesabınız yok mu? <Link href="/register">Hesap oluşturun</Link></p>
            </form>
        </main>
    );
}
