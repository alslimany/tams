import { Link } from '@inertiajs/react';
import { TrendingDownIcon, TrendingUpIcon, MinusIcon } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Badge } from '@/Components/ui/Badge';
import { cn } from '@/lib/utils';
import AmountDisplay from './AmountDisplay';

export default function AccountingKpiCard({
    title,
    value,
    currency = 'LYD',
    trend,
    icon,
    linkTo,
    variant = 'default',
    isAmount = true,
}) {
    const TrendIcon =
        trend?.direction === 'up'
            ? TrendingUpIcon
            : trend?.direction === 'down'
              ? TrendingDownIcon
              : MinusIcon;

    const trendColor =
        trend?.direction === 'up'
            ? 'text-green-600'
            : trend?.direction === 'down'
              ? 'text-red-600'
              : 'text-muted-foreground';

    const cardClass = cn(
        'transition-shadow hover:shadow-md',
        variant === 'warning' && 'border-amber-300 bg-amber-50',
        variant === 'danger' && 'border-red-300 bg-red-50',
    );

    const content = (
        <Card className={cardClass}>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">{title}</CardTitle>
                {icon && <div className="text-muted-foreground">{icon}</div>}
            </CardHeader>
            <CardContent>
                <div className="text-2xl font-bold">
                    {isAmount && typeof value === 'number' ? (
                        <AmountDisplay amount={value} currency={currency} />
                    ) : (
                        value
                    )}
                </div>
                {trend && (
                    <div className={cn('mt-1 flex items-center gap-1 text-xs', trendColor)}>
                        <TrendIcon className="size-3" />
                        <span>{trend.pct}% vs last period</span>
                    </div>
                )}
            </CardContent>
        </Card>
    );

    if (linkTo) {
        return (
            <Link href={linkTo} className="block">
                {content}
            </Link>
        );
    }

    return content;
}
