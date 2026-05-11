import { Link, usePage } from '@inertiajs/react';
import { Hotel, Plane, Shield } from 'lucide-react';

import { useTranslation } from '@/hooks/useTranslation';

export default function AuthSplitLayout({ children, title, description, footer, brandHref }) {
    const { tenant } = usePage().props;
    const { t } = useTranslation();

    return (
        <div className="grid min-h-dvh lg:grid-cols-2">
            <div className="relative hidden flex-col justify-between overflow-hidden bg-primary p-12 text-primary-foreground lg:flex">
                <div className="absolute inset-0 opacity-10" aria-hidden="true">
                    <svg width="100%" height="100%" xmlns="http://www.w3.org/2000/svg">
                        <defs>
                            <pattern id="auth-grid" width="60" height="60" patternUnits="userSpaceOnUse">
                                <path d="M 60 0 L 0 0 0 60" fill="none" stroke="currentColor" strokeWidth="1" />
                            </pattern>
                        </defs>
                        <rect width="100%" height="100%" fill="url(#auth-grid)" />
                    </svg>
                </div>

                <div className="absolute -end-32 -bottom-32 size-96 rounded-full bg-primary-foreground opacity-10" aria-hidden="true" />
                <div className="absolute -top-16 -start-16 size-64 rounded-full bg-primary-foreground opacity-5" aria-hidden="true" />

                <div className="relative">
                    <Link href={brandHref ?? '/'} className="inline-flex rounded focus:outline-none focus:ring-2 focus:ring-primary-foreground/70 focus:ring-offset-2 focus:ring-offset-primary">
                        <span className="text-3xl font-bold tracking-tighter">{t('brand.name')}</span>
                    </Link>
                </div>

                <div className="relative space-y-8">
                    <div className="space-y-3">
                        <h1 className="text-balance text-4xl font-bold leading-tight">
                            {t('auth_split.headline')}
                        </h1>
                        <p className="text-pretty text-lg text-primary-foreground/70">
                            {t('auth_split.description')}
                        </p>
                    </div>

                    <div className="space-y-4">
                        {[
                            { icon: Plane, label: t('auth_split.flight_booking') },
                            { icon: Hotel, label: t('auth_split.hotel_reservations') },
                            { icon: Shield, label: t('auth_split.travel_insurance') },
                        ].map(({ icon: Icon, label }) => (
                            <div key={label} className="flex items-center gap-3">
                                <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary-foreground/10">
                                    <Icon className="size-4" />
                                </div>
                                <span className="text-sm text-primary-foreground/80">{label}</span>
                            </div>
                        ))}
                    </div>
                </div>

                <div className="relative">
                    <p className="text-xs text-primary-foreground/40">
                        © {new Date().getFullYear()} {t('brand.name')}. {t('auth_split.all_rights_reserved')}
                    </p>
                </div>
            </div>

            <div className="flex flex-col items-center justify-center bg-background px-6 py-12">
                <div className="mb-10 lg:hidden">
                    <Link href={brandHref ?? '/'} className="rounded focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2">
                        <span className="text-2xl font-bold tracking-tighter">{t('brand.name')}</span>
                    </Link>
                </div>

                <div className="w-full max-w-sm space-y-8">
                    <div className="space-y-1">
                        <h2 className="text-balance text-2xl font-bold text-foreground">
                            {title ?? (tenant ? t('Welcome to :name', { name: tenant.name ?? tenant.id }) : t('Welcome'))}
                        </h2>
                        {description && <p className="text-pretty text-sm text-muted-foreground">{description}</p>}
                    </div>

                    {children}

                    {footer}
                </div>
            </div>
        </div>
    );
}
