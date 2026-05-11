import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { useTranslation } from '@/hooks/useTranslation';
import LandlordLayout from '@/Layouts/LandlordLayout';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/Card';
import { Badge } from '@/Components/ui/Badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/Table';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { Search, Plus, MoreHorizontal, Edit, Trash2, Eye, Filter } from 'lucide-react';

export default function Index({ airports, filters }) {
    const { t } = useTranslation();
    const [searchFilters, setSearchFilters] = useState({
        iata_code: filters.iata_code || '',
        country: filters.country || '',
        city: filters.city || '',
    });

    const handleFilterChange = (field, value) => {
        setSearchFilters(prev => ({ ...prev, [field]: value }));
    };

    const applyFilters = () => {
        router.get(route('landlord.airports.index'), searchFilters, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const clearFilters = () => {
        setSearchFilters({ iata_code: '', country: '', city: '' });
        router.get(route('landlord.airports.index'), {}, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const handleDelete = (airport) => {
        if (confirm(`Are you sure you want to delete airport ${airport.iata_code}?`)) {
            router.delete(route('landlord.airports.destroy', airport.id));
        }
    };

    return (
        <LandlordLayout>
            <Head title="Airport Management" />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Airport Management</h1>
                        <p className="text-muted-foreground">
                            Manage airports with multi-language support
                        </p>
                    </div>
                    <Button asChild>
                        <Link href={route('landlord.airports.create')}>
                            <Plus className="mr-2 h-4 w-4" />
                            Add Airport
                        </Link>
                    </Button>
                </div>

                {/* Filters */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Filter className="h-5 w-5" />
                            Filters
                        </CardTitle>
                        <CardDescription>
                            Filter airports by IATA code, country, or city
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-4 md:grid-cols-4">
                            <div className="space-y-2">
                                <label className="text-sm font-medium">IATA Code</label>
                                <Input
                                    placeholder="e.g., JFK"
                                    value={searchFilters.iata_code}
                                    onChange={(e) => handleFilterChange('iata_code', e.target.value)}
                                />
                            </div>
                            <div className="space-y-2">
                                <label className="text-sm font-medium">Country</label>
                                <Input
                                    placeholder="e.g., United States"
                                    value={searchFilters.country}
                                    onChange={(e) => handleFilterChange('country', e.target.value)}
                                />
                            </div>
                            <div className="space-y-2">
                                <label className="text-sm font-medium">City</label>
                                <Input
                                    placeholder="e.g., New York"
                                    value={searchFilters.city}
                                    onChange={(e) => handleFilterChange('city', e.target.value)}
                                />
                            </div>
                            <div className="flex items-end gap-2">
                                <Button onClick={applyFilters} className="flex-1">
                                    <Search className="mr-2 h-4 w-4" />
                                    Search
                                </Button>
                                <Button variant="outline" onClick={clearFilters}>
                                    Clear
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Airports Table */}
                <Card>
                    <CardHeader>
                        <CardTitle>Airports ({airports.total})</CardTitle>
                        <CardDescription>
                            A list of all airports in the system
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>IATA</TableHead>
                                    <TableHead>ICAO</TableHead>
                                    <TableHead>Name (EN)</TableHead>
                                    <TableHead>City (EN)</TableHead>
                                    <TableHead>Country (EN)</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {airports.data.map((airport) => (
                                    <TableRow key={airport.id}>
                                        <TableCell className="font-medium">
                                            <Badge variant="outline">{airport.iata_code}</Badge>
                                        </TableCell>
                                        <TableCell>
                                            {airport.icao_code && (
                                                <Badge variant="secondary">{airport.icao_code}</Badge>
                                            )}
                                        </TableCell>
                                        <TableCell>{airport.name?.en}</TableCell>
                                        <TableCell>{airport.city?.en}</TableCell>
                                        <TableCell>{airport.country?.en}</TableCell>
                                        <TableCell>
                                            <Badge variant="outline">{airport.type || 'Unknown'}</Badge>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button variant="ghost" size="sm">
                                                        <MoreHorizontal className="h-4 w-4" />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    <DropdownMenuItem asChild>
                                                        <Link href={route('landlord.airports.show', airport.id)}>
                                                            <Eye className="mr-2 h-4 w-4" />
                                                            View
                                                        </Link>
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem asChild>
                                                        <Link href={route('landlord.airports.edit', airport.id)}>
                                                            <Edit className="mr-2 h-4 w-4" />
                                                            Edit
                                                        </Link>
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem
                                                        onClick={() => handleDelete(airport)}
                                                        className="text-destructive focus:text-destructive"
                                                    >
                                                        <Trash2 className="mr-2 h-4 w-4" />
                                                        Delete
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>

                        {/* Pagination */}
                        {airports.links && (
                            <div className="flex items-center justify-between pt-4">
                                <div className="text-sm text-muted-foreground">
                                    Showing {airports.from} to {airports.to} of {airports.total} results
                                </div>
                                <div className="flex items-center space-x-2">
                                    {airports.links.map((link, index) => (
                                        <Button
                                            key={index}
                                            variant={link.active ? "default" : "outline"}
                                            size="sm"
                                            asChild={link.url !== null}
                                            disabled={link.url === null}
                                            onClick={() => link.url && router.get(link.url)}
                                        >
                                            {link.url ? (
                                                <a href={link.url} dangerouslySetInnerHTML={{ __html: link.label }} />
                                            ) : (
                                                <span dangerouslySetInnerHTML={{ __html: link.label }} />
                                            )}
                                        </Button>
                                    ))}
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </LandlordLayout>
    );
}
