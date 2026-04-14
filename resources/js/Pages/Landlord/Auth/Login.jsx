import React from 'react';
import { Head, useForm } from '@inertiajs/react';
import LandlordLayout from '@/Layouts/LandlordLayout';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Button } from '@/Components/ui/Button';

export default function Login() {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (event) => {
        event.preventDefault();
        post(route('landlord.login.store'));
    };

    return (
        <LandlordLayout>
            <Head title="Landlord Login" />

            <div className="min-h-screen flex items-center justify-center p-4">
                <Card className="w-full max-w-md">
                    <CardHeader>
                        <CardTitle>Landlord Console</CardTitle>
                        <CardDescription>Sign in to manage agencies, subscriptions, and platform operations.</CardDescription>
                    </CardHeader>
                    <form onSubmit={submit}>
                        <CardContent className="space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="email">Email</Label>
                                <Input id="email" type="email" value={data.email} onChange={(event) => setData('email', event.target.value)} />
                                {errors.email && <p className="text-sm text-destructive">{errors.email}</p>}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="password">Password</Label>
                                <Input id="password" type="password" value={data.password} onChange={(event) => setData('password', event.target.value)} />
                                {errors.password && <p className="text-sm text-destructive">{errors.password}</p>}
                            </div>
                        </CardContent>
                        <CardFooter>
                            <Button className="w-full" disabled={processing}>
                                {processing ? 'Signing in...' : 'Sign in'}
                            </Button>
                        </CardFooter>
                    </form>
                </Card>
            </div>
        </LandlordLayout>
    );
}
