import { router } from '@inertiajs/react';
import { CalendarIcon } from 'lucide-react';
import { Button } from '@/Components/ui/Button';
import { Popover, PopoverContent, PopoverTrigger } from '@/Components/ui/popover';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/Select';

function formatDate(dateStr) {
    return new Date(dateStr).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function monthPresets() {
    const now = new Date();
    const presets = [];

    for (let i = 0; i < 6; i++) {
        const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
        const from = new Date(d.getFullYear(), d.getMonth(), 1).toISOString().slice(0, 10);
        const to = new Date(d.getFullYear(), d.getMonth() + 1, 0).toISOString().slice(0, 10);
        presets.push({
            label: d.toLocaleDateString('en-US', { month: 'long', year: 'numeric' }),
            from,
            to,
        });
    }

    return presets;
}

export default function PeriodSelector({ period, routeName, routeParams = {} }) {
    const presets = monthPresets();
    const currentPreset = presets.find((p) => p.from === period.from && p.to === period.to);

    const handleChange = (value) => {
        const preset = presets.find((p) => p.from === value);
        if (!preset) return;

        router.get(
            route(routeName, routeParams),
            { from: preset.from, to: preset.to },
            { preserveState: true, replace: true },
        );
    };

    return (
        <div className="flex items-center gap-2">
            <CalendarIcon className="size-4 text-muted-foreground" />
            <Select value={currentPreset?.from ?? ''} onValueChange={handleChange}>
                <SelectTrigger className="w-48">
                    <SelectValue placeholder={`${formatDate(period.from)} – ${formatDate(period.to)}`} />
                </SelectTrigger>
                <SelectContent>
                    {presets.map((p) => (
                        <SelectItem key={p.from} value={p.from}>
                            {p.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}
