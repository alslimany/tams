import React from 'react';
import { Head, useForm, Link } from '@inertiajs/react';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/Components/ui/Card';
import LandlordLayout from '@/Layouts/LandlordLayout';

export default function Register() {
    const { data, setData, post, processing, errors, reset } = useForm({
        company_name: '',
        owner_name: '',
        phone: '',
        email: '',
        subdomain: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post('/register-agency');
    };

    return (
        <LandlordLayout>
            <div className="min-h-screen flex items-center justify-center bg-background p-4">
                <Head title="Register Agency" />
                
                <Card className="w-full max-w-md">
                    <CardHeader>
                        <CardTitle className="text-2xl">Register your Agency</CardTitle>
                        <CardDescription>
                            Create a new multi-tenant instance for your travel agency.
                        </CardDescription>
                    </CardHeader>
                    <form onSubmit={submit}>
                        <CardContent className="space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="company_name">Company Name</Label>
                                <Input
                                    id="company_name"
                                    type="text"
                                    value={data.company_name}
                                    onChange={(e) => setData('company_name', e.target.value)}
                                    required
                                />
                                {errors.company_name && <p className="text-sm text-destructive">{errors.company_name}</p>}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="owner_name">Owner / Admin Name</Label>
                                <Input
                                    id="owner_name"
                                    type="text"
                                    value={data.owner_name}
                                    onChange={(e) => setData('owner_name', e.target.value)}
                                />
                                {errors.owner_name && <p className="text-sm text-destructive">{errors.owner_name}</p>}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="email">Email</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    value={data.email}
                                    onChange={(e) => setData('email', e.target.value)}
                                    required
                                />
                                {errors.email && <p className="text-sm text-destructive">{errors.email}</p>}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="phone">Phone</Label>
                                <Input
                                    id="phone"
                                    type="text"
                                    value={data.phone}
                                    onChange={(e) => setData('phone', e.target.value)}
                                />
                                {errors.phone && <p className="text-sm text-destructive">{errors.phone}</p>}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="subdomain">Subdomain</Label>
                                <div className="flex items-center space-x-2">
                                    <Input
                                        id="subdomain"
                                        type="text"
                                        value={data.subdomain}
                                        onChange={(e) => setData('subdomain', e.target.value)}
                                        required
                                        className="flex-1"
                                    />
                                    <span className="text-muted-foreground">.tams.test</span>
                                </div>
                                {errors.subdomain && <p className="text-sm text-destructive">{errors.subdomain}</p>}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="password">Password</Label>
                                <Input
                                    id="password"
                                    type="password"
                                    value={data.password}
                                    onChange={(e) => setData('password', e.target.value)}
                                    required
                                />
                                {errors.password && <p className="text-sm text-destructive">{errors.password}</p>}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="password_confirmation">Confirm Password</Label>
                                <Input
                                    id="password_confirmation"
                                    type="password"
                                    value={data.password_confirmation}
                                    onChange={(e) => setData('password_confirmation', e.target.value)}
                                    required
                                />
                            </div>
                        </CardContent>
                        <CardFooter className="flex flex-col space-y-2">
                            <Button className="w-full" disabled={processing}>
                                {processing ? 'Registering...' : 'Register Agency'}
                            </Button>
                            <p className="text-sm text-center text-muted-foreground">
                                Platform admin?{' '}
                                <Link href={route('landlord.login')} className="text-primary hover:underline">
                                    Open landlord login
                                </Link>
                            </p>
                        </CardFooter>
                    </form>
                </Card>
            </div>
        </LandlordLayout>
    );
}
