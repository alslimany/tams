import React from 'react';
import { Head, useForm, Link } from '@inertiajs/react';
import TenantLayout from '@/Layouts/TenantLayout';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Select } from '@/Components/ui/Select';
import { Switch } from '@/Components/ui/Switch';
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from '@/Components/ui/Card';

export default function Edit({ user }) {
    const { data, setData, put, processing, errors } = useForm({
        name: user.name,
        email: user.email,
        role: user.role,
        is_active: user.is_active,
        password: '',
        password_confirmation: '',
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('users.update', user.id));
    };

    return (
        <TenantLayout>
            <Head title={`Edit User: ${user.name}`} />
            
            <div className="max-w-2xl mx-auto">
                <div className="mb-6">
                    <Link href={route('users.index')} className="text-sm text-muted-foreground hover:text-primary flex items-center gap-1">
                        ← Back to Users
                    </Link>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Edit User: {user.name}</CardTitle>
                    </CardHeader>
                    <form onSubmit={submit}>
                        <CardContent className="space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="name">Name</Label>
                                <Input
                                    id="name"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    required
                                />
                                {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
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
                                <Label htmlFor="role">Role</Label>
                                <Select
                                    id="role"
                                    value={data.role}
                                    onChange={(e) => setData('role', e.target.value)}
                                    required
                                >
                                    <option value="agent">Agent</option>
                                    <option value="manager">Manager</option>
                                    <option value="admin">Admin</option>
                                </Select>
                                {errors.role && <p className="text-sm text-destructive">{errors.role}</p>}
                            </div>

                            <div className="flex items-center space-x-2 py-2">
                                <Switch
                                    id="is_active"
                                    checked={data.is_active}
                                    onCheckedChange={(checked) => setData('is_active', checked)}
                                />
                                <Label htmlFor="is_active">User is Active</Label>
                            </div>

                            <div className="pt-4 border-t">
                                <h3 className="text-sm font-medium mb-4">Change Password (Leave blank to keep current)</h3>
                                <div className="space-y-4">
                                    <div className="space-y-2">
                                        <Label htmlFor="password">New Password</Label>
                                        <Input
                                            id="password"
                                            type="password"
                                            value={data.password}
                                            onChange={(e) => setData('password', e.target.value)}
                                        />
                                        {errors.password && <p className="text-sm text-destructive">{errors.password}</p>}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="password_confirmation">Confirm New Password</Label>
                                        <Input
                                            id="password_confirmation"
                                            type="password"
                                            value={data.password_confirmation}
                                            onChange={(e) => setData('password_confirmation', e.target.value)}
                                        />
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                        <CardFooter>
                            <Button className="w-full" disabled={processing}>
                                Update User
                            </Button>
                        </CardFooter>
                    </form>
                </Card>
            </div>
        </TenantLayout>
    );
}
