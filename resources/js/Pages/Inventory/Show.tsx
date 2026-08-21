import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';

type Warehouse = { id: string; name: string; code: string };
type Movement = { id: string; type: string; quantity_delta: number; quantity_before: number; quantity_after: number; note: string | null; created_at: string; created_by: string | null };
type PageLink = { url: string | null; label: string; active: boolean };
type Props = {
    variant: { id: string; name: string; sku: string | null; barcode: string | null; product_name: string };
    warehouse: Warehouse;
    warehouses: Warehouse[];
    stock: { quantity: number; reserved_quantity: number; available_quantity: number };
    movements: { data: Movement[]; links: PageLink[]; total: number };
    canAdjust: boolean;
};

export default function Show({ variant, warehouse, warehouses, stock, movements, canAdjust }: Props) {
    const form = useForm({ quantity_delta: '', note: '' });
    const submit = (event: FormEvent) => { event.preventDefault(); form.post(`/inventory/${variant.id}/warehouses/${warehouse.id}/adjustments`, { onSuccess: () => form.reset() }); };
    return <AuthenticatedLayout><Head title={`Inventory · ${variant.name}`} />
        <section className="page-heading heading-actions"><div><span className="eyebrow">Inventory history</span><h1>{variant.product_name} · {variant.name}</h1><p>{variant.sku ?? 'No SKU'}{variant.barcode ? ` · ${variant.barcode}` : ''}</p></div><Link className="button secondary" href={`/inventory?warehouse=${warehouse.id}`}>Back to inventory</Link></section>
        <div className="inventory-detail-grid">
            <section className="panel form-panel">
                <label>Warehouse<select value={warehouse.id} onChange={(event) => router.get(`/inventory/${variant.id}`, { warehouse: event.target.value })}>{warehouses.map((item) => <option value={item.id} key={item.id}>{item.name} ({item.code})</option>)}</select></label>
                <div className="stock-cards"><div><span>On hand</span><strong>{stock.quantity}</strong></div><div><span>Reserved</span><strong>{stock.reserved_quantity}</strong></div><div><span>Available</span><strong>{stock.available_quantity}</strong></div></div>
                {canAdjust && <form className="inline-stack adjustment-form" onSubmit={submit}><h2>Manual adjustment</h2><p>Use a positive or negative quantity change. The resulting stock cannot be negative.</p>
                    <label>Quantity change<input inputMode="numeric" placeholder="+5 or -2" value={form.data.quantity_delta} onChange={(event) => form.setData('quantity_delta', event.target.value)} /></label>
                    {form.errors.quantity_delta && <span className="error">{form.errors.quantity_delta}</span>}
                    <label>Reason<textarea rows={3} value={form.data.note} onChange={(event) => form.setData('note', event.target.value)} /></label>
                    {form.errors.note && <span className="error">{form.errors.note}</span>}
                    <button className="button" disabled={form.processing} type="submit">Record adjustment</button>
                </form>}
            </section>
            <section className="panel"><h2>Movement history</h2>{movements.data.length ? <div className="movement-list">{movements.data.map((movement) => <article className="movement" key={movement.id}><div><strong className={movement.quantity_delta > 0 ? 'positive' : 'negative'}>{movement.quantity_delta > 0 ? '+' : ''}{movement.quantity_delta}</strong><span>{movement.quantity_before} → {movement.quantity_after}</span></div><div><strong>{movement.type.replaceAll('_', ' ')}</strong><span>{movement.note}</span></div><div><span>{new Date(movement.created_at).toLocaleString()}</span><span>{movement.created_by ?? 'System'}</span></div></article>)}</div> : <div className="empty-state"><h2>No movements yet</h2><p>The first successful adjustment will appear here.</p></div>}
                {movements.total > 25 && <nav className="pagination">{movements.links.map((link, index) => link.url ? <Link className={link.active ? 'active' : ''} href={link.url} key={index} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} dangerouslySetInnerHTML={{ __html: link.label }} />)}</nav>}
            </section>
        </div>
    </AuthenticatedLayout>;
}
