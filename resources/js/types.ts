export type TenantSummary = {
    id: string;
    name: string;
    role: 'owner' | 'admin' | 'operator' | 'viewer';
};

export type SharedProps = {
    auth: {
        user: { id: string; name: string; email: string; is_platform_admin: boolean } | null;
        activeTenantId: string | null;
        tenants: TenantSummary[];
    };
    flash: {
        success: string | null;
        bulkPublishResult: { queued: number; already_linked: number; blocked: number; succeeded: number; failed: number } | null;
    };
};
