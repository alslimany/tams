<?php

namespace App\Services\Accounting;

use Abivia\Ledger\Models\JournalEntry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JournalEntryAttachmentService
{
    /** @var list<string> */
    public const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'application/pdf',
    ];

    public const MAX_SIZE_KB = 5120;

    public function store(JournalEntry $entry, UploadedFile $file): array
    {
        $this->deleteStoredFile($entry);

        $extension = $file->getClientOriginalExtension() ?: $file->extension();
        $filename = Str::uuid()->toString().'.'.strtolower($extension);
        $path = $file->storeAs(
            $this->directoryFor($entry->journalEntryId),
            $filename,
            'local'
        );

        return [
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => $file->getSize(),
        ];
    }

    public function remove(JournalEntry $entry): void
    {
        $this->deleteStoredFile($entry);
    }

    public function attachmentFromExtra(JournalEntry $entry): ?array
    {
        $extra = $this->decodeExtra($entry);

        if (! isset($extra['attachment']['path'])) {
            return null;
        }

        return $extra['attachment'];
    }

    public function mergeAttachmentIntoExtra(JournalEntry $entry, array $attachment): array
    {
        $extra = $this->decodeExtra($entry);
        $extra['attachment'] = $attachment;

        return $extra;
    }

    public function removeAttachmentFromExtra(JournalEntry $entry): array
    {
        $extra = $this->decodeExtra($entry);
        unset($extra['attachment']);

        return $extra;
    }

    public function download(JournalEntry $entry): StreamedResponse
    {
        $attachment = $this->attachmentFromExtra($entry);

        abort_unless(
            $attachment !== null && Storage::disk('local')->exists($attachment['path']),
            404,
            'Attachment not found.'
        );

        return Storage::disk('local')->download(
            $attachment['path'],
            $attachment['original_name'] ?? 'attachment'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeExtra(JournalEntry $entry): array
    {
        if ($entry->extra === null || $entry->extra === '') {
            return [];
        }

        $decoded = json_decode($entry->extra, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function deleteStoredFile(JournalEntry $entry): void
    {
        $attachment = $this->attachmentFromExtra($entry);

        if ($attachment !== null && Storage::disk('local')->exists($attachment['path'])) {
            Storage::disk('local')->delete($attachment['path']);
        }
    }

    private function directoryFor(int $journalEntryId): string
    {
        return 'accounting/journal-entries/'.tenant('id').'/'.$journalEntryId;
    }
}
