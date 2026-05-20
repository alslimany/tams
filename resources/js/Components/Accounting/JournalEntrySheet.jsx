import AmountDisplay from './AmountDisplay';
import { Badge } from '@/Components/ui/badge';
import { Skeleton } from '@/Components/ui/skeleton';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/Components/ui/sheet';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';

const JOURNAL_COLORS = {
    AIR: 'bg-blue-100 text-blue-800',
    HTL: 'bg-green-100 text-green-800',
    INS: 'bg-orange-100 text-orange-800',
    ESM: 'bg-purple-100 text-purple-800',
    STL: 'bg-gray-100 text-gray-800',
    GEN: 'bg-slate-100 text-slate-800',
};

export default function JournalEntrySheet({ entry, loading = false, open, onClose }) {
    return (
        <Sheet open={open} onOpenChange={(v) => !v && onClose()}>
            <SheetContent className="w-full sm:max-w-xl overflow-y-auto">
                <SheetHeader>
                    <SheetTitle>Journal Entry</SheetTitle>
                </SheetHeader>

                {loading && (
                    <div className="mt-6 space-y-3">
                        <Skeleton className="h-4 w-1/2" />
                        <Skeleton className="h-4 w-3/4" />
                        <Skeleton className="h-32 w-full" />
                    </div>
                )}

                {!loading && !entry && (
                    <p className="mt-6 text-sm text-muted-foreground">No entry found.</p>
                )}

                {!loading && entry && (
                    <div className="mt-6 space-y-4">
                        <div className="flex items-center gap-2 flex-wrap">
                            <Badge className={JOURNAL_COLORS[entry.journal] ?? 'bg-gray-100 text-gray-800'}>
                                {entry.journal}
                            </Badge>
                            {!entry.isBalanced && (
                                <Badge variant="destructive">Unbalanced</Badge>
                            )}
                        </div>

                        <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                            <dt className="text-muted-foreground">Date</dt>
                            <dd>{entry.date}</dd>
                            <dt className="text-muted-foreground">Reference</dt>
                            <dd className="font-mono text-xs">{entry.reference}</dd>
                            <dt className="text-muted-foreground">Description</dt>
                            <dd>{entry.description}</dd>
                        </dl>

                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Account</TableHead>
                                    <TableHead className="text-right">Debit</TableHead>
                                    <TableHead className="text-right">Credit</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {entry.lines.map((line, i) => (
                                    <TableRow key={i}>
                                        <TableCell>
                                            <span className="font-mono text-xs text-muted-foreground mr-2">{line.accountCode}</span>
                                            {line.accountName}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {line.debit != null ? (
                                                <AmountDisplay amount={line.debit} currency="" decimals={3} />
                                            ) : (
                                                <span className="text-muted-foreground">—</span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {line.credit != null ? (
                                                <AmountDisplay amount={line.credit} currency="" decimals={3} />
                                            ) : (
                                                <span className="text-muted-foreground">—</span>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                                <TableRow className="font-semibold border-t-2">
                                    <TableCell>Totals</TableCell>
                                    <TableCell className="text-right">
                                        <AmountDisplay amount={entry.totalDebit} currency="" decimals={3} />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <AmountDisplay amount={entry.totalCredit} currency="" decimals={3} />
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>
                )}
            </SheetContent>
        </Sheet>
    );
}
