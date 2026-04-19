import { ChevronDownIcon, ChevronUpIcon, EllipsisVerticalIcon } from 'lucide-react';

import { Avatar, AvatarFallback } from '@/Components/ui/avatar';
import { Button } from '@/Components/ui/Button';
import { Card, CardContent, CardHeader } from '@/Components/ui/Card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { Progress } from '@/Components/ui/progress';

const listItems = ['Share', 'Update', 'Refresh'];

const TotalEarningCard = ({ earningData, title, earning, trend, percentage, comparisonText, className }) => {
    return (
        <Card className={className}>
            <CardHeader className="flex items-center justify-between">
                <span className="text-lg font-semibold">{title}</span>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon" className="text-muted-foreground size-6 rounded-full">
                            <EllipsisVerticalIcon />
                            <span className="sr-only">Menu</span>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuGroup>
                            {listItems.map((item, index) => (
                                <DropdownMenuItem key={index}>{item}</DropdownMenuItem>
                            ))}
                        </DropdownMenuGroup>
                    </DropdownMenuContent>
                </DropdownMenu>
            </CardHeader>
            <CardContent className="flex flex-1 flex-col gap-4">
                <div className="flex flex-col gap-1">
                    <div className="flex items-center gap-2">
                        <span className="text-2xl font-semibold">${earning}</span>
                        <span className="flex items-center gap-1">
                            {trend === 'up' ? <ChevronUpIcon className="size-4" /> : <ChevronDownIcon className="size-4" />}
                            <span className="text-sm">{percentage}%</span>
                        </span>
                    </div>
                    <span className="text-muted-foreground text-sm">{comparisonText}</span>
                </div>
                <div className="flex flex-1 flex-col justify-evenly gap-4">
                    {earningData.map((earningItem, index) => (
                        <div key={index} className="flex items-center justify-between gap-2.5">
                            <div className="flex items-center justify-between gap-2.5">
                                <Avatar className="size-11 rounded-sm">
                                    <AvatarFallback className="bg-primary/10 shrink-0 rounded-sm">
                                        <img src={earningItem.img} alt={earningItem.platform} className="size-6" />
                                    </AvatarFallback>
                                </Avatar>
                                <div className="flex flex-col gap-1">
                                    <span className="font-medium">{earningItem.platform}</span>
                                    <span className="text-muted-foreground text-sm">{earningItem.technologies}</span>
                                </div>
                            </div>
                            <div className="space-y-2">
                                <p className="text-sm">{earningItem.earnings}</p>
                                <Progress value={earningItem.progressPercentage} className="w-36" />
                            </div>
                        </div>
                    ))}
                </div>
            </CardContent>
        </Card>
    );
};

export default TotalEarningCard;
