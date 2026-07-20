import { usePage } from '@inertiajs/react';

import { DirectionProvider } from '@/Components/ui/direction';

export default function LocaleDirectionProvider({ children }) {
    const { locale } = usePage().props;
    const currentLocale = locale || 'en';
    const direction = currentLocale === 'ar' ? 'rtl' : 'ltr';

    return (
        <DirectionProvider dir={direction}>
            <div dir={direction} className={direction === 'rtl' ? 'rtl' : undefined}>
                {children}
            </div>
        </DirectionProvider>
    );
}
