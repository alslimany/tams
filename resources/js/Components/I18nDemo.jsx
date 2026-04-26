import React from 'react';
import { useTranslation } from '@/hooks/useTranslation';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import LanguageSwitcher from '@/Components/LanguageSwitcher';

export default function I18nDemo() {
    const { t, locale, loading } = useTranslation();

    if (loading) {
        return <div className="text-center p-4">Loading translations...</div>;
    }

    return (
        <Card className="max-w-md mx-auto">
            <CardHeader>
                <CardTitle className="flex items-center justify-between">
                    <span>{t('common.translation_example')}</span>
                    <LanguageSwitcher />
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                <div>
                    <p className="text-sm text-muted-foreground">{t('common.current_locale', { locale: locale.toUpperCase() })}</p>
                </div>

                <div>
                    <h3 className="font-semibold mb-2">{t('common.examples')}:</h3>
                    <ul className="space-y-1 text-sm">
                        <li>• {t('common.hello_user', { name: 'John Doe' })}</li>
                        <li>• {t('common.items_count', { count: 1, plural: '' })}</li>
                        <li>• {t('common.items_count', { count: 5, plural: 's' })}</li>
                        <li>• {t('common.search_flights')}</li>
                        <li>• {t('common.loading')}</li>
                    </ul>
                </div>

                <div className="pt-4 border-t">
                    <p className="text-xs text-muted-foreground">
                        This component demonstrates the static asset i18n strategy.
                        Translations are loaded dynamically based on the current locale.
                    </p>
                </div>
            </CardContent>
        </Card>
    );
}