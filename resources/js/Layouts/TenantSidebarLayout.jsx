import TenantLayout from '@/Layouts/TenantLayout';

export default function TenantSidebarLayout({ children }) {
    return (
        <TenantLayout>
            {children}
        </TenantLayout>
    );
}