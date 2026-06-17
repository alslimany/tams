import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import LandlordLayout from '@/Layouts/LandlordLayout';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/Card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/Table';
import { Search, Star, StarOff, Filter, Globe } from 'lucide-react';

const toFlagEmoji = (alpha2) =>
    [...alpha2.toUpperCase()].map((c) => String.fromCodePoint(0x1f1e6 - 65 + c.charCodeAt(0))).join('');

export default function Index({ countries, filters }) {
    const [searchFilters, setSearchFilters] = useState({
        search: filters.search || '',
        esim_featured: filters.esim_featured || '',
    });

    const handleFilterChange = (field, value) => {
        setSearchFilters(prev => ({ ...prev, [field]: value }));
    };

    const applyFilters = () => {
        router.get(route('landlord.countries.index'), searchFilters, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const clearFilters = () => {
        setSearchFilters({ search: '', esim_featured: '' });
        router.get(route('landlord.countries.index'), {}, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const handleToggleFeatured = (country) => {
        router.patch(route('landlord.countries.toggle-esim-featured', country.id), {}, {
            preserveScroll: true,
        });
    };

    return (
        <LandlordLayout>
            <Head title="Countries" />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Countries</h1>
                        <p className="text-muted-foreground">
                            Manage countries and eSIM featured destinations
                        </p>
                    </div>
                </div>

                {/* Filters */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Filter className="h-5 w-5" />
                            Filters
                        </CardTitle>
                        <CardDescription>
                            Search countries by name, alpha code, or filter by feature status
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-4 md:grid-cols-4">
                            <div className="space-y-2 md:col-span-2">
                                <label className="text-sm font-medium">Search</label>
                                <Input
                                    placeholder="Country name or code…"
                                    value={searchFilters.search}
                                    onChange={(e) => handleFilterChange('search', e.target.value)}
                                />
                            </div>
                            <div className="space-y-2">
                                <label className="text-sm font-medium">eSIM Featured</label>
                                <select
                                    className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                                    value={searchFilters.esim_featured}
                                    onChange={(e) => handleFilterChange('esim_featured', e.target.value)}
                                >
                                    <option value="">All</option>
                                    <option value="yes">Featured</option>
                                    <option value="no">Not Featured</option>
                                </select>
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

                {/* Countries Table */}
                <Card>
                    <CardHeader>
                        <CardTitle>Countries ({countries.total})</CardTitle>
                        <CardDescription>
                            All countries in the system with eSIM featured status
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-16">Flag</TableHead>
                                    <TableHead>Alpha-2</TableHead>
                                    <TableHead>Alpha-3</TableHead>
                                    <TableHead>Name (EN)</TableHead>
                                    <TableHead>Name (AR)</TableHead>
                                    <TableHead>Name (FR)</TableHead>
                                    <TableHead className="text-center">eSIM Featured</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {countries.data.map((country) => (
                                    <TableRow key={country.id}>
                                        <TableCell>
                                            <span className="text-2xl leading-none">
                                                {toFlagEmoji(country.alpha2)}
                                            </span>
                                        </TableCell>
                                        <TableCell className="font-mono text-sm font-semibold">
                                            {country.alpha2}
                                        </TableCell>
                                        <TableCell className="font-mono text-sm text-muted-foreground">
                                            {country.alpha3}
                                        </TableCell>
                                        <TableCell>{country.name_en}</TableCell>
                                        <TableCell className="text-muted-foreground">{country.name_ar}</TableCell>
                                        <TableCell className="text-muted-foreground">{country.name_fr}</TableCell>
                                        <TableCell className="text-center">
                                            <button
                                                onClick={() => handleToggleFeatured(country)}
                                                title={
                                                    country.esim_featured
                                                        ? 'Click to remove from featured'
                                                        : 'Click to add to featured'
                                                }
                                                className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold transition-colors ${
                                                    country.esim_featured
                                                        ? 'bg-amber-50 text-amber-700 hover:bg-amber-100'
                                                        : 'bg-slate-100 text-slate-500 hover:bg-slate-200'
                                                }`}
                                            >
                                                {country.esim_featured ? (
                                                    <>
                                                        <Star className="h-3 w-3 fill-current" />
                                                        Featured
                                                    </>
                                                ) : (
                                                    <>
                                                        <StarOff className="h-3 w-3" />
                                                        Off
                                                    </>
                                                )}
                                            </button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>

                        {/* Pagination */}
                        {countries.links && (
                            <div className="flex items-center justify-between pt-4">
                                <div className="text-sm text-muted-foreground">
                                    Showing {countries.from} to {countries.to} of {countries.total} results
                                </div>
                                <div className="flex items-center space-x-2">
                                    {countries.links.map((link, index) => (
                                        <Button
                                            key={index}
                                            variant={link.active ? "default" : "outline"}
                                            size="sm"
                                            disabled={link.url === null}
                                            onClick={() => link.url && router.get(link.url)}
                                        >
                                            {link.url ? (
                                                <span dangerouslySetInnerHTML={{ __html: link.label }} />
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
