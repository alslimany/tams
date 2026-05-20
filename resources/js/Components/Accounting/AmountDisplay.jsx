import { cn } from '@/lib/utils';

export default function AmountDisplay({
    amount,
    currency = 'LYD',
    decimals = 3,
    colorize = false,
    className,
}) {
    const formatted = new Intl.NumberFormat('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(Math.abs(amount));

    return (
        <span
            className={cn(
                'tabular-nums',
                colorize && amount > 0 && 'text-green-600',
                colorize && amount < 0 && 'text-red-600',
                colorize && amount === 0 && 'text-muted-foreground',
                className,
            )}
        >
            {currency} {formatted}
        </span>
    );
}
