import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';

type Definition = { id: string; name: string; values: string[] };
type TemplateOption = { definition_id?: string; name: string; values: string[] };
type Template = { id: string; name: string; description: string | null; status: string; options: TemplateOption[]; variants_count: number };

export default function Index({ templates, definitions, canManage }: { templates: { data: Template[] }; definitions: Definition[]; canManage: boolean }) {
    const [editing, setEditing] = useState<Template | null>(null);
    const [drawerOpen, setDrawerOpen] = useState(false);
    const form = useForm({ name: '', description: '', status: 'active', definition_ids: [] as string[] });

    const reset = () => {
        setDrawerOpen(false);
        setEditing(null);
        form.reset();
        form.clearErrors();
    };
    const create = () => {
        setEditing(null);
        form.reset();
        form.clearErrors();
        setDrawerOpen(true);
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        editing
            ? form.put(`/variant-templates/${editing.id}`, { onSuccess: reset })
            : form.post('/variant-templates', { onSuccess: reset });
    };
    const edit = (item: Template) => {
        setEditing(item);
        form.clearErrors();
        form.setData({
            name: item.name,
            description: item.description ?? '',
            status: item.status,
            definition_ids: item.options.map((option) => option.definition_id).filter((id): id is string => Boolean(id)),
        });
        setDrawerOpen(true);
    };
    const toggle = (id: string) => form.setData('definition_ids', form.data.definition_ids.includes(id)
        ? form.data.definition_ids.filter((item) => item !== id)
        : [...form.data.definition_ids, id]);

    return <AuthenticatedLayout>
        <Head title="Varyant Şablonları" />
        <section className="page-heading heading-actions">
            <div><span className="eyebrow">Katalog</span><h1>Varyant Şablonları</h1><p>Daha önce eklediğiniz varyantlardan seçim yaparak yeniden kullanılabilir şablonlar oluşturun.</p></div>
            {canManage && <button className="button" onClick={create} type="button">Yeni şablon ekle</button>}
        </section>

        <section className="panel"><div className="table-wrap"><table><thead><tr><th>Şablon</th><th>Seçilen varyantlar</th><th>Ürün varyantı</th><th>Durum</th><th>İşlem</th></tr></thead><tbody>{templates.data.map((item) => <tr key={item.id}><td><strong>{item.name}</strong></td><td>{item.options.map((option) => `${option.name}: ${option.values.join(', ')}`).join(' · ')}</td><td>{item.variants_count}</td><td><span className="status">{item.status}</span></td><td><div className="form-actions">{canManage && <button className="button secondary small" onClick={() => edit(item)} type="button">Düzenle</button>}{canManage && <button className="danger-link" onClick={() => confirm('Şablon silinsin mi?') && router.delete(`/variant-templates/${item.id}`)} type="button">Sil</button>}</div></td></tr>)}</tbody></table></div></section>

        {canManage && drawerOpen && <>
            <button aria-label="Paneli kapat" className="drawer-backdrop" onClick={reset} type="button" />
            <aside aria-label={editing ? 'Varyant şablonunu düzenle' : 'Yeni varyant şablonu'} className="form-drawer">
                <header><div><span className="eyebrow">Varyant şablonu</span><h2>{editing ? 'Şablonu düzenle' : 'Yeni şablon ekle'}</h2><small>Şablonda kullanılacak mevcut varyantları seçin.</small></div><button aria-label="Kapat" onClick={reset} type="button">×</button></header>
                <form className="form-panel" onSubmit={submit}>
                    <div className="form-row"><label>Şablon adı<input autoFocus value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} /></label><label>Durum<select value={form.data.status} onChange={(event) => form.setData('status', event.target.value)}><option value="active">Aktif</option><option value="draft">Taslak</option><option value="archived">Arşiv</option></select></label></div>
                    <fieldset className="definition-picker"><legend>Mevcut varyantlardan seçin</legend>{definitions.map((item) => <label className="definition-choice" key={item.id}><input checked={form.data.definition_ids.includes(item.id)} onChange={() => toggle(item.id)} type="checkbox" /><span><strong>{item.name}</strong><small>{item.values.join(', ')}</small></span></label>)}{definitions.length === 0 && <p>Önce Varyantlar ekranından renk, beden gibi bir varyant ekleyin.</p>}</fieldset>
                    <label>Açıklama<textarea rows={5} value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} /></label>
                    {Object.entries(form.errors).map(([field, error]) => <span className="error" key={field}>{error}</span>)}
                    <footer><button className="button secondary" onClick={reset} type="button">Vazgeç</button><button className="button" disabled={form.processing || definitions.length === 0}>Kaydet</button></footer>
                </form>
            </aside>
        </>}
    </AuthenticatedLayout>;
}
