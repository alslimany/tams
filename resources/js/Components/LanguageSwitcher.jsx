import { Button } from '@/Components/ui/Button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { useTranslation } from '@/hooks/useTranslation';

export default function LanguageSwitcher({ compact = false }) {
    const { locale, t } = useTranslation();

    const languages = [
        { code: 'en', name: 'English', flag: '🇺🇸' },
        { code: 'ar', name: 'العربية', flag: '🇸🇦' },
        { code: 'fr', name: 'Français', flag: '🇫🇷' },
    ];
    const selectedLanguage = languages.find((language) => language.code === locale) ?? languages[0];

    const switchLanguage = (newLocale) => {
        if (newLocale === locale) {
            return;
        }

        window.location.href = `/language/switch?locale=${newLocale}`;
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" size={compact ? 'xs' : 'sm'} className="rounded-full" aria-label={t('common.select_language')}>
                    <span aria-hidden="true">{selectedLanguage.flag}</span>
                    <span className={compact ? 'hidden text-xs sm:inline' : 'text-xs'}>{selectedLanguage.name}</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-40">
                {languages.map((language) => (
                    <DropdownMenuItem
                        key={language.code}
                        onSelect={() => switchLanguage(language.code)}
                        className="cursor-pointer justify-between"
                    >
                        <span className="flex items-center gap-2">
                            <span aria-hidden="true">{language.flag}</span>
                            <span>{language.name}</span>
                        </span>
                        {locale === language.code && <span className="text-xs text-primary">✓</span>}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
