import React from 'react';
import { Head, useForm } from '@inertiajs/react';
import TenantNavbarLayout from '@/Layouts/TenantNavbarLayout';
import { Button } from "@/Components/ui/Button";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/Components/ui/Card";
import { Label } from "@/Components/ui/Label";
import { RadioGroup, RadioGroupItem } from "@/Components/ui/RadioGroup";
import { toast } from "sonner";

export default function General({ settings }) {
    const { data, setData, post, processing, errors } = useForm({
        search_display_mode: settings.search_display_mode || 'per_offer',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('settings.general.update'), {
            onSuccess: () => toast.success('Settings updated successfully'),
            preserveScroll: true,
        });
    };

    return (
        <TenantNavbarLayout>
            <Head title="General Settings" />

            <div className="flex justify-between items-center mb-6">
                <div>
                    <h2 className="text-3xl font-bold tracking-tight">General Settings</h2>
                    <p className="text-muted-foreground">Manage your agency's global configurations.</p>
                </div>
            </div>

            <div className="max-w-2xl">
                <form onSubmit={submit}>
                    <Card>
                        <CardHeader>
                            <CardTitle>Search Display Mode</CardTitle>
                            <CardDescription>
                                Choose how flight search results are displayed to your agents.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            <RadioGroup
                                value={data.search_display_mode}
                                onValueChange={(val) => setData('search_display_mode', val)}
                                className="grid gap-4"
                            >
                                <div className="flex items-start space-x-3 space-y-0 text-accent-foreground p-4 border rounded-lg hover:bg-muted/50 transition-colors">
                                    <RadioGroupItem value="per_offer" id="per_offer" className="mt-1" />
                                    <div className="grid gap-1.5 leading-none">
                                        <Label htmlFor="per_offer" className="font-bold cursor-pointer">Individual Fares (Per Offer)</Label>
                                        <p className="text-sm text-muted-foreground">
                                            List each branded fare (Economy Light, Flex, etc.) as a separate selectable card.
                                        </p>
                                    </div>
                                </div>
                                <div className="flex items-start space-x-3 space-y-0 text-accent-foreground p-4 border rounded-lg hover:bg-muted/50 transition-colors">
                                    <RadioGroupItem value="per_flight" id="per_flight" className="mt-1" />
                                    <div className="grid gap-1.5 leading-none">
                                        <Label htmlFor="per_flight" className="font-bold cursor-pointer">Grouped by Flight (Per Flight)</Label>
                                        <p className="text-sm text-muted-foreground">
                                            Group all branded fares under a single flight card to save space.
                                        </p>
                                    </div>
                                </div>
                            </RadioGroup>
                            {errors.search_display_mode && <p className="text-sm text-destructive font-medium">{errors.search_display_mode}</p>}
                        </CardContent>
                        <CardFooter className="border-t px-6 py-4">
                            <Button type="submit" disabled={processing}>
                                {processing ? "Saving..." : "Save Changes"}
                            </Button>
                        </CardFooter>
                    </Card>
                </form>
            </div>
        </TenantNavbarLayout>
    );
}
