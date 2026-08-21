const operations: Record<string, string> = {
    connection_test: 'Bağlantı testi',
    product_import: 'Ürün içe aktarımı',
    listing_publish: 'İlan yayınlama',
    product_publish: 'Ürün yayınlama',
    inventory_push: 'Stok gönderimi',
    price_push: 'Fiyat gönderimi',
    order_pull: 'Sipariş çekme',
    order_status_push: 'Sipariş durumu gönderimi',
    order_refresh: 'Sipariş yenileme',
};

const statuses: Record<string, string> = {
    pending: 'Bekliyor',
    running: 'Çalışıyor',
    succeeded: 'Başarılı',
    failed: 'Başarısız',
    skipped: 'Atlandı',
};

const categories: Record<string, string> = {
    authentication: 'Kimlik doğrulama',
    validation: 'Doğrulama',
    rate_limited: 'Hız sınırı',
    network: 'Ağ bağlantısı',
    remote_server: 'Uzak sunucu',
    not_found: 'Bulunamadı',
    conflict: 'Çakışma',
    unknown: 'Bilinmeyen hata',
};

const errors: Record<string, string> = {
    unsafe_store_url: 'Mağaza adresi güvenlik kurallarını karşılamıyor.',
    connection_failed: 'WooCommerce mağazasına ulaşılamadı.',
    authentication_failed: 'WooCommerce API bilgilerini veya izinlerini reddetti.',
    api_not_found: 'WooCommerce REST API v3 bu mağaza adresinde bulunamadı.',
    rate_limited: 'Kanal geçici olarak istekleri sınırlandırdı.',
    remote_server_error: 'Uzak sunucuda geçici bir hata oluştu.',
    unexpected_response: 'Kanaldan beklenmeyen bir yanıt alındı.',
};

const errorCodes: Record<string, string> = {
    unsafe_store_url: 'Mağaza adresi',
    connection_failed: 'Bağlantı kurulamadı',
    authentication_failed: 'Kimlik doğrulanamadı',
    api_not_found: 'API bulunamadı',
    rate_limited: 'İstek sınırı',
    remote_server_error: 'Sunucu hatası',
    unexpected_response: 'Beklenmeyen yanıt',
};

export const syncOperationLabel = (value: string) => operations[value] ?? value.replaceAll('_', ' ');
export const syncStatusLabel = (value: string) => statuses[value] ?? value;
export const syncCategoryLabel = (value: string) => categories[value] ?? value.replaceAll('_', ' ');
export const syncErrorLabel = (code: string | null, fallback: string | null) => code && errors[code] ? errors[code] : fallback;
export const syncErrorCodeLabel = (code: string | null) => code ? (errorCodes[code] ?? 'Teknik hata') : null;
