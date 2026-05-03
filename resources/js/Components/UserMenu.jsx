import React from 'react';
import { router, usePage } from '@inertiajs/react';
import { useTranslation } from '@/hooks/useTranslation';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/Components/ui/Button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    User,
    Settings,
    LogOut,
    Package,
    Globe,
} from 'lucide-react';

export default function UserMenu() {
    const { props } = usePage();
    const { t } = useTranslation();
    const user = props.auth?.user;
    const landlordUser = props.auth?.landlordUser;

    // Use tenant user if available, otherwise landlord user
    const currentUser = user || landlordUser;

    const handleLogout = () => {
        router.post(route('logout'));
    };

    const handleLanguageSwitch = (locale) => {
        // Use GET request with query parameter - no CSRF issues
        window.location.href = route('language.switch') + '?locale=' + locale;
    };

    const getUserInitials = (user) => {
        if (!user) return 'U';
        return `${user.first_name?.[0] || ''}${user.last_name?.[0] || ''}`.toUpperCase() || 'U';
    };

    const getUserDisplayName = (user) => {
        if (!user) return t('common.guest');
        return `${user.first_name || ''} ${user.last_name || ''}`.trim() || user.email || t('common.user');
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" className="relative h-8 w-8 rounded-full">
                    <Avatar className="h-8 w-8">
                        <AvatarImage
                            src={currentUser?.avatar}
                            alt={getUserDisplayName(currentUser)}
                        />
                        <AvatarFallback>
                            {getUserInitials(currentUser)}
                        </AvatarFallback>
                    </Avatar>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent className="w-56" align="end" forceMount>
                <DropdownMenuLabel className="font-normal">
                    <div className="flex flex-col space-y-1">
                        <p className="text-sm font-medium leading-none">
                            {getUserDisplayName(currentUser)}
                        </p>
                        <p className="text-xs leading-none text-muted-foreground">
                            {currentUser?.email}
                        </p>
                    </div>
                </DropdownMenuLabel>
                <DropdownMenuSeparator />

                {/* User Actions */}
                <DropdownMenuGroup>
                    <DropdownMenuItem asChild>
                        <a href={route('profile.edit')} className="cursor-pointer">
                            <User className="mr-2 h-4 w-4" />
                            <span>{t('common.profile')}</span>
                        </a>
                    </DropdownMenuItem>
                    <DropdownMenuItem asChild>
                        <a href={route('orders.index')} className="cursor-pointer">
                            <Package className="mr-2 h-4 w-4" />
                            <span>{t('common.my_orders')}</span>
                        </a>
                    </DropdownMenuItem>
                    <DropdownMenuItem asChild>
                        <a href={route('profile.edit')} className="cursor-pointer">
                            <Settings className="mr-2 h-4 w-4" />
                            <span>{t('common.settings')}</span>
                        </a>
                    </DropdownMenuItem>
                </DropdownMenuGroup>

                <DropdownMenuSeparator />

                {/* Language Switcher */}
                <DropdownMenuGroup>
                    <DropdownMenuLabel className="text-xs font-medium text-muted-foreground uppercase tracking-wider">
                        {t('common.language')}
                    </DropdownMenuLabel>
                    <DropdownMenuItem
                        onClick={() => handleLanguageSwitch('en')}
                        className="cursor-pointer"
                    >
                        <Globe className="mr-2 h-4 w-4" />
                        <span>English</span>
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        onClick={() => handleLanguageSwitch('ar')}
                        className="cursor-pointer"
                    >
                        <Globe className="mr-2 h-4 w-4" />
                        <span>العربية</span>
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        onClick={() => handleLanguageSwitch('fr')}
                        className="cursor-pointer"
                    >
                        <Globe className="mr-2 h-4 w-4" />
                        <span>Français</span>
                    </DropdownMenuItem>
                </DropdownMenuGroup>

                <DropdownMenuSeparator />

                {/* Logout */}
                <DropdownMenuItem
                    onClick={handleLogout}
                    className="cursor-pointer text-red-600 focus:text-red-600"
                >
                    <LogOut className="mr-2 h-4 w-4" />
                    <span>{t('common.logout')}</span>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}