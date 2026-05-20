import { useState } from 'react';
import { DownloadIcon, Loader2Icon } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/Components/ui/dropdown-menu';

export default function ExportButton({ csvUrl, pdfUrl, label = 'Export' }) {
    const [loading, setLoading] = useState(false);

    const handleDownload = (url) => {
        setLoading(true);
        const a = document.createElement('a');
        a.href = url;
        a.click();
        setTimeout(() => setLoading(false), 1500);
    };

    if (!csvUrl && !pdfUrl) return null;

    if (csvUrl && !pdfUrl) {
        return (
            <Button variant="outline" size="sm" onClick={() => handleDownload(csvUrl)} disabled={loading}>
                {loading ? <Loader2Icon className="mr-2 size-4 animate-spin" /> : <DownloadIcon className="mr-2 size-4" />}
                {label}
            </Button>
        );
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" size="sm" disabled={loading}>
                    {loading ? <Loader2Icon className="mr-2 size-4 animate-spin" /> : <DownloadIcon className="mr-2 size-4" />}
                    {label}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                {csvUrl && (
                    <DropdownMenuItem onClick={() => handleDownload(csvUrl)}>
                        Export CSV
                    </DropdownMenuItem>
                )}
                {pdfUrl && (
                    <DropdownMenuItem onClick={() => handleDownload(pdfUrl)}>
                        Export PDF
                    </DropdownMenuItem>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
