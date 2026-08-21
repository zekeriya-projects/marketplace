import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';

type ImportRow = { id: string; original_name: string; format: string; status: string; total_rows: number; created_rows: number; updated_rows: number; failed_rows: number; safe_error_message: string | null; created_at: string };

export default function Index({ imports, canCreate }: { imports: { data: ImportRow[] }; canCreate: boolean }) {
    const form = useForm<{ format: 'excel' | 'xml'; file: File | null }>({ format: 'excel', file: null });
    const submit = (event: FormEvent) => { event.preventDefault(); form.post('/imports', { forceFormData: true, onSuccess: () => form.reset('file') }); };
    return <AuthenticatedLayout><Head title="Excel - XML Aktarım" />
        <section className="page-heading"><span className="eyebrow">Aktarım</span><h1>Excel - XML Aktarım</h1><p>Ürün, varyant, fiyat ve depo stoklarını doğrulanmış bir dosyayla merkezi kataloğa aktarın.</p></section>
        {canCreate && <form className="panel form-panel" onSubmit={submit}><h2>Yeni aktarım</h2><div className="form-row"><label>Dosya türü<select value={form.data.format} onChange={(event) => form.setData('format', event.target.value as 'excel' | 'xml')}><option value="excel">Excel / CSV</option><option value="xml">XML</option></select></label><label>Dosya<input accept={form.data.format === 'xml' ? '.xml' : '.xlsx,.xls,.csv'} type="file" onChange={(event) => form.setData('file', event.target.files?.[0] ?? null)} /></label></div>{Object.values(form.errors).map((error) => <span className="error" key={error}>{error}</span>)}<button className="button" disabled={form.processing || !form.data.file}>Aktarımı başlat</button><small>En fazla 20 MB. İşlem arka planda güvenli biçimde yürütülür.</small></form>}
        <section className="panel"><h2>Aktarım geçmişi</h2>{imports.data.length ? <div className="table-wrap"><table><thead><tr><th>Dosya</th><th>Tür</th><th>Durum</th><th>Sonuç</th><th>Tarih</th></tr></thead><tbody>{imports.data.map((item) => <tr key={item.id}><td><strong>{item.original_name}</strong>{item.safe_error_message && <small>{item.safe_error_message}</small>}</td><td>{item.format.toUpperCase()}</td><td><span className="status">{{ pending: 'Bekliyor', running: 'İşleniyor', succeeded: 'Tamamlandı', failed: 'Başarısız' }[item.status] ?? item.status}</span></td><td>{item.total_rows} satır<small>{item.created_rows} yeni · {item.updated_rows} güncel · {item.failed_rows} hatalı</small></td><td>{new Date(item.created_at).toLocaleString('tr-TR')}</td></tr>)}</tbody></table></div> : <div className="empty-state"><h3>Henüz aktarım yok</h3><p>İlk katalog dosyanızı yüklediğinizde ilerlemeyi burada görebilirsiniz.</p></div>}</section>
    </AuthenticatedLayout>;
}
