import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';

export default function Index() {
    return <AuthenticatedLayout><Head title="Excel Şablonları" />
        <section className="page-heading"><span className="eyebrow">Aktarım</span><h1>Excel Şablonları</h1><p>Doğru sütunlar, örnek ürün satırı ve alan açıklamalarıyla hazırlanmış şablonu kullanın.</p></section>
        <section className="panel heading-actions"><div><h2>Katalog aktarım şablonu</h2><p>Ürün, varyant, kategori, marka, fiyat ve stok alanlarını içerir. Para birimi ve durum alanlarında hazır seçimler bulunur.</p><small>Zorunlu alanlar: product_name ve sku.</small></div><Link className="button" href="/import-templates/catalog.xlsx">Excel şablonunu indir</Link></section>
        <section className="panel"><h2>XML yapısı</h2><p>XML aktarımı için kök eleman altında <code>&lt;product&gt;</code> veya <code>&lt;item&gt;</code> kayıtları kullanın. Alan adları Excel sütunlarıyla aynıdır.</p><pre>{`<products>\n  <product>\n    <product_name>Örnek Ürün</product_name>\n    <sku>ORN-001</sku>\n    <price>199.90</price>\n    <currency>TRY</currency>\n    <stock>10</stock>\n  </product>\n</products>`}</pre></section>
    </AuthenticatedLayout>;
}
