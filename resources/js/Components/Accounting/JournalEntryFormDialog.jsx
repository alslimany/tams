import React from 'react';
import { router } from '@inertiajs/react';
import { FileText, Paperclip, Plus, Trash2, X } from 'lucide-react';
import AccountCombobox from '@/Components/Accounting/AccountCombobox';
import { Alert, AlertDescription } from '@/Components/ui/alert';
import { Button } from '@/Components/ui/Button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/Components/ui/Dialog';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/Select';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/Table';

const emptyLine = { accountCode: undefined, debit: '', credit: '' };

const ACCEPTED_ATTACHMENT_TYPES =
    'image/jpeg,image/png,image/webp,image/gif,application/pdf,.pdf,.jpg,.jpeg,.png,.webp,.gif';

const selectableAccounts = (accounts) =>
    (accounts ?? []).filter((acc) => acc.code && String(acc.code).trim() !== '');

function todayDateString() {
    const date = new Date();
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function formatFileSize(bytes) {
    if (!bytes) return '';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export default function JournalEntryFormDialog({
    open,
    onClose,
    mode,
    entry,
    accounts,
    journalOptions,
    formEntryId,
}) {
    const [transDate, setTransDate] = React.useState('');
    const [description, setDescription] = React.useState('');
    const [journal, setJournal] = React.useState('GEN');
    const [lines, setLines] = React.useState([{ ...emptyLine }, { ...emptyLine }]);
    const [error, setError] = React.useState('');
    const [submitting, setSubmitting] = React.useState(false);
    const [attachmentFile, setAttachmentFile] = React.useState(null);
    const [existingAttachment, setExistingAttachment] = React.useState(null);
    const [removeAttachment, setRemoveAttachment] = React.useState(false);
    const [activeAccountLine, setActiveAccountLine] = React.useState(null);
    const fileInputRef = React.useRef(null);

    const isEdit = mode === 'edit';

    const accountOptions = React.useMemo(
        () => selectableAccounts(accounts),
        [accounts],
    );

    React.useEffect(() => {
        if (!open) return;
        setAttachmentFile(null);
        setRemoveAttachment(false);
        setActiveAccountLine(null);

        if (isEdit && entry) {
            setTransDate(entry.date || todayDateString());
            setDescription(entry.description || '');
            setJournal(entry.journal || 'GEN');
            setExistingAttachment(entry.attachment ?? null);
            setLines(
                entry.lines.map((line) => ({
                    accountCode: line.accountCode || '',
                    debit: line.debit != null ? String(line.debit) : '',
                    credit: line.credit != null ? String(line.credit) : '',
                })),
            );
        } else {
            setTransDate(todayDateString());
            setDescription('');
            setJournal('GEN');
            setExistingAttachment(null);
            setLines([{ ...emptyLine }, { ...emptyLine }]);
        }
        setError('');
        setSubmitting(false);
    }, [open, isEdit, entry]);

    function updateLine(index, field, value) {
        setLines((prev) =>
            prev.map((line, i) => {
                if (i !== index) return line;
                const next = { ...line, [field]: value };
                if (field === 'debit' && value !== '') {
                    next.credit = '';
                }
                if (field === 'credit' && value !== '') {
                    next.debit = '';
                }
                return next;
            }),
        );
    }

    function addLine() {
        setLines((prev) => [...prev, { ...emptyLine }]);
    }

    function removeLine(index) {
        if (lines.length <= 2) return;
        setLines((prev) => prev.filter((_, i) => i !== index));
    }

    function handleAttachmentChange(e) {
        const file = e.target.files?.[0];
        if (!file) return;

        const allowed = [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
            'application/pdf',
        ];
        if (!allowed.includes(file.type)) {
            setError('Attachment must be an image (JPEG, PNG, WebP, GIF) or PDF.');
            e.target.value = '';
            return;
        }

        setError('');
        setAttachmentFile(file);
        setRemoveAttachment(false);
    }

    function clearAttachment() {
        if (attachmentFile) {
            setAttachmentFile(null);
            setRemoveAttachment(false);
        } else if (existingAttachment) {
            setRemoveAttachment(true);
        }
        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    }

    function handleSubmit(e) {
        e.preventDefault();
        setError('');
        setSubmitting(true);

        const parsedLines = lines.map((line) => ({
            accountCode: line.accountCode,
            debit: line.debit !== '' ? parseFloat(line.debit) : null,
            credit: line.credit !== '' ? parseFloat(line.credit) : null,
        }));

        for (let i = 0; i < parsedLines.length; i++) {
            const l = parsedLines[i];
            if (!l.accountCode) {
                setError(`Line ${i + 1}: Please select an account.`);
                setSubmitting(false);
                return;
            }
        }

        const linesWithAmount = parsedLines.filter(
            (l) => l.debit !== null || l.credit !== null,
        );
        if (linesWithAmount.length < 2) {
            setError('A journal entry requires at least two lines with amounts.');
            setSubmitting(false);
            return;
        }

        const totalDebit = linesWithAmount.reduce(
            (sum, l) => sum + (l.debit || 0),
            0,
        );
        const totalCredit = linesWithAmount.reduce(
            (sum, l) => sum + (l.credit || 0),
            0,
        );

        if (Math.abs(totalDebit - totalCredit) > 0.001) {
            setError(
                `Debits (${totalDebit.toFixed(3)}) must equal credits (${totalCredit.toFixed(3)}). Difference: ${Math.abs(totalDebit - totalCredit).toFixed(3)}`,
            );
            setSubmitting(false);
            return;
        }

        const payload = {
            transDate,
            description,
            journal,
            lines: linesWithAmount.map((l) => ({
                accountCode: l.accountCode,
                debit: l.debit,
                credit: l.credit,
            })),
        };

        if (attachmentFile) {
            payload.attachment = attachmentFile;
        }

        if (isEdit && removeAttachment) {
            payload.remove_attachment = true;
        }

        const options = {
            forceFormData: Boolean(attachmentFile),
            onSuccess: () => {
                setSubmitting(false);
                onClose();
            },
            onError: (err) => {
                setError(
                    typeof err === 'string'
                        ? err
                        : err?.message || 'An error occurred.',
                );
                setSubmitting(false);
            },
        };

        if (isEdit) {
            router.post(
                route('accounting.ledger.journal.update', formEntryId),
                { ...payload, _method: 'put' },
                { ...options, forceFormData: options.forceFormData || removeAttachment },
            );
        } else {
            router.post(
                route('accounting.ledger.journal.store'),
                payload,
                options,
            );
        }
    }

    const runningDebit = lines.reduce(
        (sum, l) => sum + (parseFloat(l.debit) || 0),
        0,
    );
    const runningCredit = lines.reduce(
        (sum, l) => sum + (parseFloat(l.credit) || 0),
        0,
    );
    const isBalanced =
        lines.length >= 2 && Math.abs(runningDebit - runningCredit) < 0.001;

    const showExistingAttachment =
        existingAttachment && !removeAttachment && !attachmentFile;

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent
                className="flex max-h-[92vh] w-[calc(100vw-2rem)] max-w-5xl flex-col gap-0 overflow-hidden p-0 sm:w-full [&>button]:hidden"
            >
                <div className="flex items-start justify-between gap-4 border-b px-6 py-4">
                    <div className="min-w-0">
                        <DialogTitle>
                            {isEdit ? 'Edit Journal Entry' : 'New Journal Entry'}
                        </DialogTitle>
                        <DialogDescription className="mt-1">
                            Enter header details and accounting lines. Debits must
                            equal credits.
                        </DialogDescription>
                    </div>
                    <div className="flex shrink-0 gap-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            form="journal-entry-form"
                            disabled={submitting}
                        >
                            {submitting ? 'Saving…' : isEdit ? 'Update Entry' : 'Save Entry'}
                        </Button>
                    </div>
                </div>

                <form
                    id="journal-entry-form"
                    onSubmit={handleSubmit}
                    className="flex-1 overflow-y-auto px-6 py-5"
                >
                    {error && (
                        <Alert variant="destructive" className="mb-5">
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    )}

                    <div className="space-y-6">
                        <section className="rounded-lg border">
                            <div className="border-b bg-muted/40 px-4 py-2.5 text-sm font-medium">
                                Entry Details
                            </div>
                            <div className="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-3">
                                <div className="space-y-1.5">
                                    <Label htmlFor="je-date">Posting Date</Label>
                                    <Input
                                        id="je-date"
                                        type="date"
                                        value={transDate}
                                        onChange={(e) => setTransDate(e.target.value)}
                                        required
                                    />
                                </div>

                                <div className="space-y-1.5">
                                    <Label htmlFor="je-journal">Journal Type</Label>
                                    <Select
                                        value={journal}
                                        onValueChange={setJournal}
                                        disabled={isEdit}
                                    >
                                        <SelectTrigger id="je-journal" className="w-full">
                                            <SelectValue placeholder="Select journal type…" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {journalOptions.map((opt) => (
                                                <SelectItem
                                                    key={opt.value}
                                                    value={opt.value}
                                                >
                                                    {opt.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="space-y-1.5 sm:col-span-2 lg:col-span-1">
                                    <Label htmlFor="je-attachment">
                                        Attachment{' '}
                                        <span className="font-normal text-muted-foreground">
                                            (optional)
                                        </span>
                                    </Label>
                                    {showExistingAttachment ? (
                                        <div className="flex items-center gap-2 rounded-md border bg-muted/20 px-3 py-2">
                                            <FileText className="size-4 shrink-0 text-muted-foreground" />
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm">
                                                    {existingAttachment.original_name}
                                                </p>
                                                {existingAttachment.size && (
                                                    <p className="text-xs text-muted-foreground">
                                                        {formatFileSize(
                                                            existingAttachment.size,
                                                        )}
                                                    </p>
                                                )}
                                            </div>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon-sm"
                                                onClick={clearAttachment}
                                                aria-label="Remove attachment"
                                            >
                                                <X className="size-4" />
                                            </Button>
                                        </div>
                                    ) : attachmentFile ? (
                                        <div className="flex items-center gap-2 rounded-md border bg-muted/20 px-3 py-2">
                                            <Paperclip className="size-4 shrink-0 text-muted-foreground" />
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm">
                                                    {attachmentFile.name}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {formatFileSize(attachmentFile.size)}
                                                </p>
                                            </div>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon-sm"
                                                onClick={clearAttachment}
                                                aria-label="Remove attachment"
                                            >
                                                <X className="size-4" />
                                            </Button>
                                        </div>
                                    ) : (
                                        <div className="relative">
                                            <Input
                                                ref={fileInputRef}
                                                id="je-attachment"
                                                type="file"
                                                accept={ACCEPTED_ATTACHMENT_TYPES}
                                                onChange={handleAttachmentChange}
                                                className="cursor-pointer file:mr-3 file:rounded-md file:border-0 file:bg-muted file:px-3 file:py-1 file:text-sm file:font-medium"
                                            />
                                        </div>
                                    )}
                                    <p className="text-xs text-muted-foreground">
                                        Images or PDF only, max 5 MB.
                                    </p>
                                </div>

                                <div className="space-y-1.5 sm:col-span-2 lg:col-span-3">
                                    <Label htmlFor="je-description">Description</Label>
                                    <textarea
                                        id="je-description"
                                        value={description}
                                        onChange={(e) => setDescription(e.target.value)}
                                        required
                                        rows={2}
                                        className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                    />
                                </div>
                            </div>
                        </section>

                        <section className="rounded-lg border">
                            <div className="border-b bg-muted/40 px-4 py-2.5">
                                <span className="text-sm font-medium">
                                    Accounting Entries
                                </span>
                            </div>

                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow className="hover:bg-transparent">
                                            <TableHead className="w-12 text-center">
                                                No
                                            </TableHead>
                                            <TableHead className="min-w-[220px]">
                                                Account
                                            </TableHead>
                                            <TableHead className="w-36 text-right">
                                                Debit
                                            </TableHead>
                                            <TableHead className="w-36 text-right">
                                                Credit
                                            </TableHead>
                                            <TableHead className="w-12" />
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {lines.map((line, index) => (
                                            <TableRow key={index}>
                                                <TableCell className="text-center text-muted-foreground">
                                                    {index + 1}
                                                </TableCell>
                                                <TableCell className="py-2">
                                                    <AccountCombobox
                                                        id={`je-account-${index}`}
                                                        accounts={accountOptions}
                                                        value={line.accountCode || ''}
                                                        open={activeAccountLine === index}
                                                        onOpenChange={(isOpen) =>
                                                            setActiveAccountLine(
                                                                isOpen ? index : null,
                                                            )
                                                        }
                                                        onChange={(code) =>
                                                            updateLine(
                                                                index,
                                                                'accountCode',
                                                                code,
                                                            )
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell className="py-2">
                                                    <Input
                                                        type="number"
                                                        step="0.001"
                                                        min="0"
                                                        value={line.debit}
                                                        onChange={(e) =>
                                                            updateLine(
                                                                index,
                                                                'debit',
                                                                e.target.value,
                                                            )
                                                        }
                                                        disabled={
                                                            line.credit !== ''
                                                        }
                                                        className="h-9 text-right tabular-nums"
                                                        placeholder="0.000"
                                                    />
                                                </TableCell>
                                                <TableCell className="py-2">
                                                    <Input
                                                        type="number"
                                                        step="0.001"
                                                        min="0"
                                                        value={line.credit}
                                                        onChange={(e) =>
                                                            updateLine(
                                                                index,
                                                                'credit',
                                                                e.target.value,
                                                            )
                                                        }
                                                        disabled={
                                                            line.debit !== ''
                                                        }
                                                        className="h-9 text-right tabular-nums"
                                                        placeholder="0.000"
                                                    />
                                                </TableCell>
                                                <TableCell className="py-2">
                                                    {lines.length > 2 && (
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon-sm"
                                                            onClick={() =>
                                                                removeLine(index)
                                                            }
                                                            className="text-muted-foreground hover:text-destructive"
                                                            aria-label="Remove line"
                                                        >
                                                            <Trash2 className="size-4" />
                                                        </Button>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                    <TableFooter>
                                        <TableRow className="hover:bg-transparent">
                                            <TableCell
                                                colSpan={2}
                                                className="text-right font-medium"
                                            >
                                                Totals
                                            </TableCell>
                                            <TableCell className="text-right font-mono font-medium tabular-nums">
                                                {runningDebit.toFixed(3)}
                                            </TableCell>
                                            <TableCell className="text-right font-mono font-medium tabular-nums">
                                                {runningCredit.toFixed(3)}
                                            </TableCell>
                                            <TableCell>
                                                {lines.length >= 2 && (
                                                    <span
                                                        className={`text-xs font-medium ${
                                                            isBalanced
                                                                ? 'text-green-600 dark:text-green-400'
                                                                : 'text-amber-600 dark:text-amber-400'
                                                        }`}
                                                    >
                                                        {isBalanced
                                                            ? 'Balanced'
                                                            : 'Unbalanced'}
                                                    </span>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    </TableFooter>
                                </Table>
                            </div>

                            <div className="border-t px-4 py-3">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={addLine}
                                >
                                    <Plus className="mr-1 size-3.5" />
                                    Add Line
                                </Button>
                            </div>
                        </section>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
