import { ArrowDownToLineIcon, ArrowRightLeftIcon, ArrowUpFromLineIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

const TYPE_CONFIG = {
    receive: {
        label: 'Receive',
        icon: ArrowDownToLineIcon,
        className: 'bg-green-50 text-green-700 dark:bg-green-950 dark:text-green-400',
    },
    deliver: {
        label: 'Deliver',
        icon: ArrowUpFromLineIcon,
        className: 'bg-orange-50 text-orange-700 dark:bg-orange-950 dark:text-orange-400',
    },
    transfer: {
        label: 'Transfer',
        icon: ArrowRightLeftIcon,
        className: 'bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-400',
    },
};

export default function MovementTypeBadge({ type }) {
    const config = TYPE_CONFIG[type] ?? {
        label: type,
        icon: ArrowRightLeftIcon,
        className: 'bg-muted text-muted-foreground',
    };
    const Icon = config.icon;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
                config.className,
            )}
        >
            <Icon className="size-3" />
            {config.label}
        </span>
    );
}
