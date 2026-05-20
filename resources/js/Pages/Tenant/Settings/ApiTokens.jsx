import { useState, useEffect } from 'react';
import { Head, useForm, usePage, router } from '@inertiajs/react';
import { KeyRoundIcon, PlusIcon, TrashIcon, CopyIcon, CheckIcon, ShieldCheckIcon } from 'lucide-react';
import TenantSidebarLayout from '@/Layouts/TenantSidebarLayout';
import { Button } from '@/Components/ui/Button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Badge } from '@/Components/ui/Badge';
import { Label } from '@/Components/ui/Label';
import { Input } from '@/Components/ui/Input';
import { Checkbox } from '@/Components/ui/Checkbox';
import { useTranslation } from '@/hooks/useTranslation';
import { toast } from 'sonner';

const ABILITY_LABELS = {
    read: { label: 'Read', description: 'View orders, wallet, reports, reference data' },
    write: { label: 'Write', description: 'Search, select, and book flights, hotels, insurance' },
    issue: { label: 'Issue', description: 'Issue, void, and refund tickets and policies' },
    report: { label: 'Report', description: 'Access dashboard and financial reports' },
};

function CopyButton({ text }) {
    const [copied, setCopied] = useState(false);

    function copy() {
        navigator.clipboard.writeText(text).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        });
    }

    return (
        <Button variant="outline" size="sm" onClick={copy} className="gap-1.5">
            {copied ? <CheckIcon className="size-3.5 text-green-600" /> : <CopyIcon className="size-3.5" />}
            {copied ? 'Copied' : 'Copy'}
        </Button>
    );
}

function NewTokenBanner({ token, onDismiss }) {
    return (
        <div className="mb-6 rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-800 dark:bg-green-950">
            <div className="flex items-start gap-3">
                <ShieldCheckIcon className="mt-0.5 size-5 shrink-0 text-green-600" />
                <div className="flex-1 min-w-0">
                    <p className="font-semibold text-green-800 dark:text-green-200">Token created — copy it now</p>
                    <p className="mt-0.5 text-sm text-green-700 dark:text-green-300">
                        This token will not be shown again. Store it securely.
                    </p>
                    <div className="mt-3 flex items-center gap-2">
                        <code className="flex-1 truncate rounded bg-white px-3 py-1.5 text-sm font-mono border border-green-200 dark:bg-green-900 dark:border-green-700">
                            {token}
                        </code>
                        <CopyButton text={token} />
                    </div>
                </div>
                <button
                    onClick={onDismiss}
                    className="text-green-600 hover:text-green-800 text-lg leading-none"
                    aria-label="Dismiss"
                >
                    ×
                </button>
            </div>
        </div>
    );
}

export default function ApiTokens({ tokens, availableAbilities }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const [newToken, setNewToken] = useState(props.flash?.newToken ?? null);

    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        abilities: ['read'],
    });

    useEffect(() => {
        if (props.flash?.newToken) {
            setNewToken(props.flash.newToken);
        }
    }, [props.flash?.newToken]);

    function toggleAbility(ability) {
        setData('abilities', data.abilities.includes(ability)
            ? data.abilities.filter((a) => a !== ability)
            : [...data.abilities, ability],
        );
    }

    function submit(e) {
        e.preventDefault();
        post(route('settings.api-tokens.store'), {
            preserveScroll: true,
            onSuccess: () => {
                reset('name');
                setData('abilities', ['read']);
            },
        });
    }

    function revoke(tokenId, tokenName) {
        if (!confirm(`Revoke token "${tokenName}"? This cannot be undone.`)) {
            return;
        }
        router.delete(route('settings.api-tokens.destroy', tokenId), {
            preserveScroll: true,
            onSuccess: () => toast.success('Token revoked.'),
        });
    }

    return (
        <TenantSidebarLayout>
            <Head title="API Tokens" />

            <div className="mb-6 flex items-center justify-between">
                <div>
                    <h2 className="text-3xl font-bold tracking-tight">API Tokens</h2>
                    <p className="text-muted-foreground">
                        Create scoped tokens to integrate your systems with the Booknow API.
                    </p>
                </div>
            </div>

            {newToken && (
                <NewTokenBanner token={newToken} onDismiss={() => setNewToken(null)} />
            )}

            <div className="grid gap-6 lg:grid-cols-2">
                {/* Create token form */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <PlusIcon className="size-4" />
                            New Token
                        </CardTitle>
                        <CardDescription>
                            Give the token a descriptive name and select only the abilities it needs.
                        </CardDescription>
                    </CardHeader>
                    <form onSubmit={submit}>
                        <CardContent className="space-y-5">
                            <div className="space-y-1.5">
                                <Label htmlFor="token-name">Token Name</Label>
                                <Input
                                    id="token-name"
                                    placeholder="e.g. Mobile App, CRM Integration"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                />
                                {errors.name && (
                                    <p className="text-sm text-destructive">{errors.name}</p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label>Abilities</Label>
                                <div className="space-y-2">
                                    {availableAbilities.map((ability) => (
                                        <label
                                            key={ability}
                                            className="flex cursor-pointer items-start gap-3 rounded-lg border p-3 hover:bg-muted/50 transition-colors"
                                        >
                                            <Checkbox
                                                checked={data.abilities.includes(ability)}
                                                onCheckedChange={() => toggleAbility(ability)}
                                                className="mt-0.5"
                                            />
                                            <div>
                                                <p className="font-medium capitalize">{ABILITY_LABELS[ability]?.label ?? ability}</p>
                                                <p className="text-xs text-muted-foreground">
                                                    {ABILITY_LABELS[ability]?.description}
                                                </p>
                                            </div>
                                        </label>
                                    ))}
                                </div>
                                {errors.abilities && (
                                    <p className="text-sm text-destructive">{errors.abilities}</p>
                                )}
                            </div>
                        </CardContent>
                        <CardFooter className="border-t px-6 py-4">
                            <Button type="submit" disabled={processing || data.abilities.length === 0}>
                                {processing ? 'Creating…' : 'Create Token'}
                            </Button>
                        </CardFooter>
                    </form>
                </Card>

                {/* Existing tokens */}
                <div className="space-y-3">
                    <h3 className="text-sm font-semibold text-muted-foreground uppercase tracking-wide">
                        Active Tokens ({tokens.length})
                    </h3>

                    {tokens.length === 0 ? (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-12 text-center">
                                <KeyRoundIcon className="mb-3 size-8 text-muted-foreground/50" />
                                <p className="text-sm text-muted-foreground">No API tokens yet.</p>
                                <p className="text-xs text-muted-foreground mt-1">
                                    Create a token to start integrating with the API.
                                </p>
                            </CardContent>
                        </Card>
                    ) : (
                        tokens.map((token) => (
                            <Card key={token.id}>
                                <CardContent className="flex items-start justify-between gap-4 pt-4 pb-4">
                                    <div className="min-w-0 flex-1">
                                        <p className="font-medium truncate">{token.name}</p>
                                        <div className="mt-1.5 flex flex-wrap gap-1">
                                            {(token.abilities ?? []).map((ability) => (
                                                <Badge key={ability} variant="secondary" className="text-xs capitalize">
                                                    {ability === '*' ? 'Full Access' : ability}
                                                </Badge>
                                            ))}
                                        </div>
                                        <p className="mt-2 text-xs text-muted-foreground">
                                            Created {new Date(token.created_at).toLocaleDateString()}
                                            {token.last_used_at && (
                                                <> · Last used {new Date(token.last_used_at).toLocaleDateString()}</>
                                            )}
                                        </p>
                                    </div>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="shrink-0 text-destructive hover:text-destructive hover:bg-destructive/10"
                                        onClick={() => revoke(token.id, token.name)}
                                    >
                                        <TrashIcon className="size-4" />
                                    </Button>
                                </CardContent>
                            </Card>
                        ))
                    )}
                </div>
            </div>
        </TenantSidebarLayout>
    );
}
