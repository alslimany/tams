import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import TenantSidebarLayout from '@/Layouts/TenantSidebarLayout';
import { Button } from '@/Components/ui/Button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/Table';
import { Badge } from '@/Components/ui/Badge';
import { Switch } from '@/Components/ui/Switch';

export default function Index({ users }) {
    const toggleStatus = (user) => {
        router.patch(route('users.toggle-status', user.id), {}, {
            preserveScroll: true,
        });
    };

    return (
        <TenantSidebarLayout>
            <Head title="Users" />
            
            <div className="flex justify-between items-center mb-6">
                <h1 className="text-2xl font-bold">User Management</h1>
                <Button asChild>
                    <Link href={route('users.create')}>Add User</Link>
                </Button>
            </div>

            <div className="bg-card rounded-md border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Name</TableHead>
                            <TableHead>Email</TableHead>
                            <TableHead>Role</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Last Login</TableHead>
                            <TableHead className="text-right">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {users.map((user) => (
                            <TableRow key={user.id}>
                                <TableCell className="font-medium">{user.name}</TableCell>
                                <TableCell>{user.email}</TableCell>
                                <TableCell>
                                    <Badge variant={user.role === 'admin' ? 'default' : user.role === 'manager' ? 'secondary' : 'outline'}>
                                        {user.role}
                                    </Badge>
                                </TableCell>
                                <TableCell>
                                    <div className="flex items-center space-x-2">
                                        <Switch 
                                            checked={user.is_active} 
                                            onCheckedChange={() => toggleStatus(user)}
                                        />
                                        <span className="text-sm text-muted-foreground">
                                            {user.is_active ? 'Active' : 'Inactive'}
                                        </span>
                                    </div>
                                </TableCell>
                                <TableCell className="text-muted-foreground">
                                    {user.last_login_at || 'Never'}
                                </TableCell>
                                <TableCell className="text-right space-x-2">
                                    <Button variant="ghost" size="sm" asChild>
                                        <Link href={route('users.edit', user.id)}>Edit</Link>
                                    </Button>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </TenantSidebarLayout>
    );
}
