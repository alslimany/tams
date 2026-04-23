import * as React from 'react';
import { ChevronLeftIcon, ChevronRightIcon } from 'lucide-react';
import { DayPicker, getDefaultClassNames } from 'react-day-picker';

import { cn } from '@/lib/utils';
import { buttonVariants } from '@/Components/ui/Button';

function Calendar({ className, classNames, showOutsideDays = true, components, ...props }) {
    const defaultClassNames = getDefaultClassNames();
    const incomingComponents = components || {};

    return (
        <DayPicker
            showOutsideDays={showOutsideDays}
            className={cn('p-3', className)}
            classNames={{
                root: cn(defaultClassNames.root, 'w-fit'),
                months: cn(defaultClassNames.months, 'flex flex-col gap-2 sm:flex-row'),
                month: cn(defaultClassNames.month, 'space-y-4'),
                month_caption: cn(defaultClassNames.month_caption, 'relative flex items-center justify-center pt-1'),
                caption_label: cn(defaultClassNames.caption_label, 'text-sm font-medium'),
                nav: cn(defaultClassNames.nav, 'flex items-center gap-1'),
                button_previous: cn(
                    defaultClassNames.button_previous,
                    buttonVariants({ variant: 'outline' }),
                    'h-7 w-7 bg-transparent p-0 opacity-50 hover:opacity-100',
                ),
                button_next: cn(
                    defaultClassNames.button_next,
                    buttonVariants({ variant: 'outline' }),
                    'h-7 w-7 bg-transparent p-0 opacity-50 hover:opacity-100',
                ),
                month_grid: cn(defaultClassNames.month_grid, 'w-full border-collapse space-y-1'),
                weekdays: cn(defaultClassNames.weekdays, 'flex'),
                weekday: cn(defaultClassNames.weekday, 'w-8 rounded-md text-[0.8rem] font-normal text-muted-foreground'),
                week: cn(defaultClassNames.week, 'mt-2 flex w-full'),
                day: cn(defaultClassNames.day, 'h-8 w-8 p-0 text-center text-sm'),
                day_button: cn(defaultClassNames.day_button, buttonVariants({ variant: 'ghost' }), 'h-8 w-8 p-0 font-normal aria-selected:opacity-100'),
                selected: 'bg-primary text-primary-foreground hover:bg-primary hover:text-primary-foreground focus:bg-primary focus:text-primary-foreground',
                today: 'bg-accent text-accent-foreground',
                outside: 'text-muted-foreground opacity-50 aria-selected:bg-accent/50 aria-selected:text-muted-foreground aria-selected:opacity-30',
                disabled: 'text-muted-foreground opacity-50',
                range_middle: 'aria-selected:bg-accent aria-selected:text-accent-foreground',
                range_start: 'aria-selected:bg-primary aria-selected:text-primary-foreground',
                range_end: 'aria-selected:bg-primary aria-selected:text-primary-foreground',
                hidden: 'invisible',
                ...classNames,
            }}
            components={{
                ...incomingComponents,
                Chevron: ({ orientation, className: chevronClassName, ...iconProps }) => {
                    if (orientation === 'left') {
                        return <ChevronLeftIcon className={cn('h-4 w-4', chevronClassName)} {...iconProps} />;
                    }

                    return <ChevronRightIcon className={cn('h-4 w-4', chevronClassName)} {...iconProps} />;
                },
            }}
            {...props}
        />
    );
}
Calendar.displayName = 'Calendar';

export { Calendar };
