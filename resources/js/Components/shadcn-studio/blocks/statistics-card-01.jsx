import { Card, CardContent, CardHeader } from '@/Components/ui/Card';

import { cn } from '@/lib/utils';

const StatisticsCard = ({ icon, value, title, changePercentage, className }) => {
    return (
        <Card className={cn('gap-4', className)}>
            <CardHeader className="flex items-center">
                <div className="bg-primary/10 text-primary flex size-8 shrink-0 items-center justify-center rounded-md">{icon}</div>
                <span className="text-2xl">{value}</span>
            </CardHeader>
            <CardContent className="flex flex-col gap-2">
                <span className="font-semibold">{title}</span>
                <p className="space-x-2">
                    <span className="text-sm">{changePercentage}</span>
                    <span className="text-muted-foreground text-sm">than last week</span>
                </p>
            </CardContent>
        </Card>
    );
};

export default StatisticsCard;
