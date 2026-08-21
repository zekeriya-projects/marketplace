import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function Register() {
    const form = useForm({ name: '', organization_name: '', email: '', password: '', password_confirmation: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/register', { onFinish: () => form.reset('password', 'password_confirmation') });
    };

    return (
        <main className="auth-shell">
            <Head title="Hesap oluştur" />
            <form className="auth-card" onSubmit={submit}>
                <div>
                    <span className="eyebrow">Marketplace SaaS</span>
                    <h1>Çalışma alanınızı oluşturun</h1>
                    <p>İlk organizasyonunuz oluşturulacak ve sahibi siz olacaksınız.</p>
                </div>
                <label>Adınız<input autoComplete="name" autoFocus value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></label>
                {form.errors.name && <span className="error">{form.errors.name}</span>}
                <label>Organizasyon adı<input value={form.data.organization_name} onChange={(e) => form.setData('organization_name', e.target.value)} /></label>
                {form.errors.organization_name && <span className="error">{form.errors.organization_name}</span>}
                <label>Email<input autoComplete="email" type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} /></label>
                {form.errors.email && <span className="error">{form.errors.email}</span>}
                <label>Şifre<input autoComplete="new-password" type="password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} /></label>
                {form.errors.password && <span className="error">{form.errors.password}</span>}
                <label>Şifreyi doğrulayın<input autoComplete="new-password" type="password" value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} /></label>
                <button className="button" disabled={form.processing} type="submit">Hesap oluştur</button>
                <p className="form-footer">Zaten kayıtlı mısınız? <Link href="/login">Giriş yapın</Link></p>
            </form>
        </main>
    );
}
