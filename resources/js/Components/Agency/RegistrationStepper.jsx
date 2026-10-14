import { Check } from 'lucide-react';

import { cn } from '@/Components/lib/utils';
import { useTranslation } from '@/hooks/useTranslation';

/**
 * Horizontal 4-step indicator for the agency registration wizard.
 *
 * Circles are connected by rules; the active step is named in the caption
 * below so labels never crowd the narrow auth column.
 */
export default function RegistrationStepper({ current, steps }) {
    const { t } = useTranslation();

    return (
        <div className="space-y-2">
            <ol className="flex items-center">
                {steps.map((step, index) => {
                    const number = index + 1;
                    const isComplete = number < current;
                    const isCurrent = number === current;

                    return (
                        <li
                            key={step.key}
                            className={cn('flex min-w-0 flex-1 items-center', index === 0 && 'flex-none')}
                            aria-current={isCurrent ? 'step' : undefined}
                        >
                            {index > 0 && <span aria-hidden="true" className="h-px flex-1 bg-border" />}

                            <span
                                title={t(step.label)}
                                className={cn(
                                    'flex size-7 shrink-0 items-center justify-center rounded-full border text-xs font-medium',
                                    isComplete && 'border-primary bg-primary text-primary-foreground',
                                    isCurrent && 'border-primary text-primary ring-4 ring-primary/15',
                                    !isComplete && !isCurrent && 'border-border text-muted-foreground',
                                )}
                            >
                                {isComplete ? <Check className="size-3.5" aria-hidden="true" /> : number}
                            </span>
                        </li>
                    );
                })}
            </ol>

            <p className="text-center text-xs text-muted-foreground">
                {t('landlord.auth.steps.step_of', {
                    current,
                    total: steps.length,
                })}
                {' · '}
                <span className="font-medium text-foreground">{t(steps[current - 1].label)}</span>
            </p>
        </div>
    );
}
