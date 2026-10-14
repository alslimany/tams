import { Head, useForm } from '@inertiajs/react';
import { useState, useMemo, useEffect } from 'react';
import { ArrowLeft, ArrowRight, Check, Clock, Copy, ExternalLink, Loader2 } from 'lucide-react';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Progress } from '@/Components/ui/progress';
import RegistrationStepper from '@/Components/Agency/RegistrationStepper';
import AuthSplitLayout from '@/Layouts/AuthSplitLayout';
import { useTranslation } from '@/hooks/useTranslation';

const STEP_AGENCY = 1;
const STEP_ACCOUNT = 2;
const STEP_CREATING = 3;
const STEP_DONE = 4;

const STEPS = [
    { key: 'agency', label: 'landlord.auth.steps.agency' },
    { key: 'account', label: 'landlord.auth.steps.account' },
    { key: 'creating', label: 'landlord.auth.steps.creating' },
    { key: 'complete', label: 'landlord.auth.steps.complete' },
];

const AGENCY_FIELDS = ['company_name', 'city_iata', 'commercial_register', 'passport'];

const stepForErrors = (fieldErrors) =>
    AGENCY_FIELDS.some((field) => fieldErrors[field]) ? STEP_AGENCY : STEP_ACCOUNT;

export default function Register({ centralDomain, airports, registration }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        company_name: '',
        owner_name: '',
        phone: '',
        email: '',
        city_iata: '',
        password: '',
        password_confirmation: '',
        commercial_register: null,
        passport: null,
    });

    const [step, setStep] = useState(() => (registration ? STEP_DONE : STEP_AGENCY));
    const [airportSearch, setAirportSearch] = useState('');
    const [cityError, setCityError] = useState('');
    const [progress, setProgress] = useState(0);
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        if (registration) {
            setProgress(100);
            setStep(STEP_DONE);
        }
    }, [registration]);

    useEffect(() => {
        if (step !== STEP_CREATING) {
            return undefined;
        }

        setProgress(6);

        const timer = setInterval(() => {
            setProgress((value) => (value >= 92 ? value : value + Math.max(1, (92 - value) / 10)));
        }, 400);

        return () => clearInterval(timer);
    }, [step]);

    const filteredAirports = useMemo(() => {
        const q = airportSearch.toLowerCase();
        if (!q) return airports;
        return airports.filter(
                (a) =>
                    a.city.toLowerCase().includes(q) ||
                    a.country.toLowerCase().includes(q),
            );
    }, [airports, airportSearch]);

    const selectedAirport = airports.find((a) => a.iata_code === data.city_iata);

    const submit = (e) => {
        e.preventDefault();

        if (step === STEP_AGENCY) {
            if (!data.city_iata) {
                setCityError(t('landlord.auth.city_required'));
                return;
            }

            setCityError('');
            clearErrors(AGENCY_FIELDS);
            setStep(STEP_ACCOUNT);

            return;
        }

        setStep(STEP_CREATING);

        post('/register-agency', {
            onError: (fieldErrors) => setStep(stepForErrors(fieldErrors)),
        });
    };

    const startOver = () => {
        reset();
        setAirportSearch('');
        setCityError('');
        setProgress(0);
        setCopied(false);
        setStep(STEP_AGENCY);
    };

    const copyWorkspaceUrl = async () => {
        if (!registration?.workspaceUrl || !navigator.clipboard) return;
        await navigator.clipboard.writeText(registration.workspaceUrl);
        setCopied(true);
    };

    const isWorking = step === STEP_CREATING;
    const isDone = step === STEP_DONE;

    const layoutTitle = isDone
        ? t('landlord.auth.finish.title')
        : isWorking
            ? t('landlord.auth.progress.title')
            : t('landlord.auth.register_title');

    const layoutDescription = isDone
        ? t('landlord.auth.finish.description')
        : isWorking
            ? t('landlord.auth.progress.description')
            : t('landlord.auth.register_description');

    return (
        <AuthSplitLayout
            brandHref={route('agency.register')}
            title={layoutTitle}
            description={layoutDescription}
            footer={
                isDone ? (
                    <p className="text-center text-sm text-muted-foreground text-pretty">
                        {t('landlord.auth.success.email_notice')}
                    </p>
                ) : (
                    <p className="text-center text-sm text-muted-foreground text-pretty">
                        {t('landlord.auth.already_have_workspace')}
                    </p>
                )
            }
        >
            <Head
                title={
                    isDone
                        ? t('landlord.auth.finish.head_title')
                        : isWorking
                            ? t('landlord.auth.progress.head_title')
                            : t('landlord.auth.register_head_title')
                }
            />

            <div className="space-y-6">
                <RegistrationStepper current={step} steps={STEPS} />

                {step === STEP_CREATING && (
                    <div className="space-y-4 rounded-xl border bg-muted/30 p-5 text-center">
                        <Loader2 className="mx-auto size-8 animate-spin text-primary" aria-hidden="true" />
                        <div className="space-y-1.5">
                            <p className="text-sm font-medium text-foreground">
                                {t('landlord.auth.progress.title')}
                            </p>
                            <p className="text-pretty text-sm text-muted-foreground">
                                {t('landlord.auth.progress.description')}
                            </p>
                        </div>
                        <Progress value={progress} aria-label={t('landlord.auth.progress.title')} />
                        <p className="text-xs text-muted-foreground">
                            {t('landlord.auth.progress.keep_open')}
                        </p>
                    </div>
                )}

                {isDone && registration && (
                    <div className="space-y-5">
                        <div className="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-700 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-300">
                            <Clock className="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                            <p className="text-sm text-pretty">{t('landlord.auth.success.under_review')}</p>
                        </div>

                        <dl className="space-y-3">
                            <div className="rounded-lg border bg-muted/30 p-3">
                                <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                    {t('landlord.auth.finish.tenant_name')}
                                </dt>
                                <dd className="mt-1 break-words text-sm font-medium text-foreground">
                                    {registration.agencyName}
                                </dd>
                            </div>

                            <div className="rounded-lg border bg-muted/30 p-3">
                                <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                    {t('landlord.auth.finish.owner_email')}
                                </dt>
                                <dd className="mt-1 break-words text-sm font-medium text-foreground">
                                    {registration.ownerEmail}
                                </dd>
                            </div>

                            <div className="rounded-lg border bg-muted/30 p-3">
                                <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                    {t('landlord.auth.finish.tenant_url')}
                                </dt>
                                <dd className="mt-1 break-all text-sm font-medium text-foreground">
                                    {registration.workspaceUrl}
                                </dd>
                            </div>
                        </dl>

                        <div className="flex flex-col gap-2 sm:flex-row">
                            <Button
                                type="button"
                                variant="outline"
                                className="flex-1"
                                onClick={copyWorkspaceUrl}
                            >
                                {copied ? (
                                    <Check className="size-4" aria-hidden="true" />
                                ) : (
                                    <Copy className="size-4" aria-hidden="true" />
                                )}
                                {copied ? t('landlord.auth.copied') : t('landlord.auth.success.copy_workspace_url')}
                            </Button>

                            <a
                                href={registration.workspaceUrl}
                                target="_blank"
                                rel="noreferrer"
                                className="inline-flex flex-1 items-center justify-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                            >
                                <ExternalLink className="size-4" aria-hidden="true" />
                                {t('landlord.auth.success.open_workspace')}
                            </a>
                        </div>

                        <Button type="button" variant="ghost" className="w-full" onClick={startOver}>
                            {t('landlord.auth.success.register_another')}
                        </Button>
                    </div>
                )}

                {!isWorking && !isDone && (
                    <form onSubmit={submit} className="space-y-5">
                        {step === STEP_AGENCY && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2 sm:col-span-2">
                                    <Label htmlFor="company_name">{t('landlord.auth.company_name')}</Label>
                                    <Input
                                        id="company_name"
                                        type="text"
                                        value={data.company_name}
                                        onChange={(e) => setData('company_name', e.target.value)}
                                        autoFocus
                                        required
                                    />
                                    {errors.company_name && <p className="text-sm text-destructive">{errors.company_name}</p>}
                                </div>

                                <div className="space-y-2 sm:col-span-2">
                                    <Label>{t('landlord.auth.agency_city')}</Label>
                                    <Input
                                        type="text"
                                        placeholder={t('landlord.auth.agency_city_search_placeholder')}
                                        value={airportSearch}
                                        onChange={(e) => {
                                            setAirportSearch(e.target.value);
                                            setData('city_iata', '');
                                            setCityError('');
                                        }}
                                    />
                                    {airportSearch && !selectedAirport && (
                                        <div className="max-h-48 overflow-y-auto rounded-md border bg-popover shadow-md">
                                            {filteredAirports.length === 0 ? (
                                                <p className="px-3 py-2 text-sm text-muted-foreground">{t('common.no_results')}</p>
                                            ) : (
                                                filteredAirports.slice(0, 50).map((airport) => (
                                                    <button
                                                        key={airport.iata_code}
                                                        type="button"
                                                        className="flex w-full flex-col px-3 py-2 text-left hover:bg-accent"
                                                        onClick={() => {
                                                            setData('city_iata', airport.iata_code);
                                                            setAirportSearch(airport.city);
                                                            setCityError('');
                                                        }}
                                                    >
                                                        <span className="font-medium">{airport.city}</span>
                                                        <span className="text-xs text-muted-foreground">{airport.country}</span>
                                                    </button>
                                                ))
                                            )}
                                        </div>
                                    )}
                                    {selectedAirport && (
                                        <p className="text-xs text-muted-foreground">
                                            {selectedAirport.city}, {selectedAirport.country}
                                        </p>
                                    )}
                                    {(cityError || errors.city_iata) && (
                                        <p className="text-sm text-destructive">{cityError || errors.city_iata}</p>
                                    )}
                                </div>

                                <div className="space-y-2 sm:col-span-2">
                                    <Label htmlFor="commercial_register">{t('landlord.auth.commercial_register')}</Label>
                                    <Input
                                        id="commercial_register"
                                        type="file"
                                        accept=".pdf,.jpg,.jpeg,.png"
                                        onChange={(e) => setData('commercial_register', e.target.files[0])}
                                        required
                                    />
                                    <p className="text-xs text-muted-foreground">{t('landlord.auth.commercial_register_hint')}</p>
                                    {errors.commercial_register && <p className="text-sm text-destructive">{errors.commercial_register}</p>}
                                </div>

                                <div className="space-y-2 sm:col-span-2">
                                    <Label htmlFor="passport">{t('landlord.auth.passport')}</Label>
                                    <Input
                                        id="passport"
                                        type="file"
                                        accept=".pdf,.jpg,.jpeg,.png"
                                        onChange={(e) => setData('passport', e.target.files[0])}
                                        required
                                    />
                                    <p className="text-xs text-muted-foreground">{t('landlord.auth.passport_hint')}</p>
                                    {errors.passport && <p className="text-sm text-destructive">{errors.passport}</p>}
                                </div>
                            </div>
                        )}

                        {step === STEP_ACCOUNT && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="owner_name">{t('landlord.auth.owner_name')}</Label>
                                    <Input
                                        id="owner_name"
                                        type="text"
                                        value={data.owner_name}
                                        onChange={(e) => setData('owner_name', e.target.value)}
                                        autoFocus
                                    />
                                    {errors.owner_name && <p className="text-sm text-destructive">{errors.owner_name}</p>}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="phone">{t('common.phone')}</Label>
                                    <Input
                                        id="phone"
                                        type="text"
                                        value={data.phone}
                                        onChange={(e) => setData('phone', e.target.value)}
                                    />
                                    {errors.phone && <p className="text-sm text-destructive">{errors.phone}</p>}
                                </div>

                                <div className="space-y-2 sm:col-span-2">
                                    <Label htmlFor="email">{t('common.email')}</Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        value={data.email}
                                        onChange={(e) => setData('email', e.target.value)}
                                        autoComplete="username"
                                        required
                                    />
                                    {errors.email && <p className="text-sm text-destructive">{errors.email}</p>}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="password">{t('common.password')}</Label>
                                    <Input
                                        id="password"
                                        type="password"
                                        value={data.password}
                                        onChange={(e) => setData('password', e.target.value)}
                                        autoComplete="new-password"
                                        required
                                    />
                                    {errors.password && <p className="text-sm text-destructive">{errors.password}</p>}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="password_confirmation">{t('common.confirm_password')}</Label>
                                    <Input
                                        id="password_confirmation"
                                        type="password"
                                        value={data.password_confirmation}
                                        onChange={(e) => setData('password_confirmation', e.target.value)}
                                        autoComplete="new-password"
                                        required
                                    />
                                    {errors.password_confirmation && <p className="text-sm text-destructive">{errors.password_confirmation}</p>}
                                </div>
                            </div>
                        )}

                        <div className="flex gap-3">
                            {step === STEP_ACCOUNT && (
                                <Button type="button" variant="outline" onClick={() => setStep(STEP_AGENCY)}>
                                    <ArrowLeft className="size-4" aria-hidden="true" />
                                    {t('landlord.auth.step_back')}
                                </Button>
                            )}

                            <Button type="submit" className="flex-1" disabled={processing}>
                                {step === STEP_AGENCY ? (
                                    <>
                                        {t('landlord.auth.step_next')}
                                        <ArrowRight className="size-4" aria-hidden="true" />
                                    </>
                                ) : (
                                    t('landlord.auth.register_agency')
                                )}
                            </Button>
                        </div>
                    </form>
                )}
            </div>
        </AuthSplitLayout>
    );
}
