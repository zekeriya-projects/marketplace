export type CatalogStatus = 'draft' | 'active' | 'archived';

export type ProductVariant = {
    id?: string;
    name: string;
    sku: string | null;
    barcode: string | null;
    base_price: string;
    currency: string;
    status: CatalogStatus;
    variant_template_id?: string | null;
    option_values?: Record<string, string> | null;
};

export type Product = {
    id: string;
    name: string;
    category_id: string | null;
    brand_id: string | null;
    brand: string | null;
    description: string | null;
    status: CatalogStatus;
    short_name: string | null;
    invoice_name: string | null;
    custom_code_1: string | null;
    custom_code_2: string | null;
    compare_at_price: string | null;
    purchase_price: string | null;
    desi: string | null;
    desi_2: string | null;
    vat_rate: number;
    excise_tax_rate: string;
    communication_tax_rate: string;
    disable_external_sync: boolean;
    vat_exemption_code: string | null;
    expiration_date: string | null;
    category_name?: string | null;
    brand_name?: string | null;
    primary_image_url?: string | null;
    variants: ProductVariant[];
};

export type ProductFormData = Omit<Product, 'id'>;

export type ProductSubmission = ProductFormData & { product_type: 'simple' | 'variable'; images: File[] };

export type VariantTemplateOption = { name: string; values: string[] };
export type VariantTemplateSummary = { id: string; name: string; options: VariantTemplateOption[] };
