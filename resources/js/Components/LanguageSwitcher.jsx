import React from 'react';
import { router } from '@inertiajs/react';
import { Button } from '@/Components/ui/Button';
import { useTranslation } from '@/hooks/useTranslation';

export default function LanguageSwitcher() {
    const { locale } = useTranslation();

    const languages = [
        { code: 'en', name: 'English', flag: '🇺🇸' },
        { code: 'ar', name: 'العربية', flag: '🇸🇦' },
    ];

    const switchLanguage = (newLocale) => {
        // This would typically make an API call to change the user's locale preference
        // For now, we'll just demonstrate the concept
        console.log(`Switching to locale: ${newLocale}`);

        // In a real implementation, you might:
        // 1. Make an API call to update user's locale preference
        // 2. Update the app's locale state
        // 3. Reload the page or update Inertia props
    };

    return (
        <div className="flex items-center gap-2">
            {languages.map((lang) => (
                <Button
                    key={lang.code}
                    variant={locale === lang.code ? 'default' : 'outline'}
                    size="sm"
                    onClick={() => switchLanguage(lang.code)}
                    className="flex items-center gap-2"
                >
                    <span>{lang.flag}</span>
                    <span className="text-xs">{lang.name}</span>
                </Button>
            ))}
        </div>
    );
}