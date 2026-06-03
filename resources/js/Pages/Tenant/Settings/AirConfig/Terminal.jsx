import React, { useState, useRef, useEffect } from 'react';
import axios from 'axios';
import { Head, Link } from '@inertiajs/react';
import TenantSidebarLayout from '@/Layouts/TenantSidebarLayout';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { Badge } from '@/Components/ui/Badge';
import { ArrowLeft, Loader2, Terminal as TerminalIcon, Clock, Send } from 'lucide-react';

export default function Terminal({ provider }) {
    const [command, setCommand] = useState('');
    const [running, setRunning] = useState(false);
    const [output, setOutput] = useState(null);
    const [history, setHistory] = useState([]);
    const [historyIndex, setHistoryIndex] = useState(-1);
    const inputRef = useRef(null);
    const outputRef = useRef(null);

    useEffect(() => {
        inputRef.current?.focus();
    }, []);

    useEffect(() => {
        if (output !== null && outputRef.current) {
            outputRef.current.scrollTop = 0;
        }
    }, [output]);

    const runCommand = () => {
        const cmd = command.trim();
        if (!cmd || running) {
            return;
        }

        setRunning(true);
        setOutput(null);

        axios.post(route('settings.airlines.terminal.run', provider.id), { command: cmd }, {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
            },
        })
            .then((res) => {
                const entry = {
                    id: Date.now(),
                    command: cmd,
                    output: res.data.output ?? '',
                    duration_ms: res.data.duration_ms ?? null,
                    error: null,
                    timestamp: new Date(),
                };
                setHistory((prev) => [entry, ...prev]);
                setOutput(entry);
            })
            .catch((err) => {
                const entry = {
                    id: Date.now(),
                    command: cmd,
                    output: null,
                    duration_ms: null,
                    error: err.response?.data?.error ?? 'Command failed.',
                    timestamp: new Date(),
                };
                setHistory((prev) => [entry, ...prev]);
                setOutput(entry);
            })
            .finally(() => {
                setRunning(false);
                setCommand('');
                setHistoryIndex(-1);
                inputRef.current?.focus();
            });
    };

    const handleKeyDown = (e) => {
        if (e.key === 'Enter') {
            runCommand();
            return;
        }

        if (e.key === 'ArrowUp') {
            e.preventDefault();
            const nextIndex = Math.min(historyIndex + 1, history.length - 1);
            setHistoryIndex(nextIndex);
            setCommand(history[nextIndex]?.command ?? '');
            return;
        }

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            const nextIndex = Math.max(historyIndex - 1, -1);
            setHistoryIndex(nextIndex);
            setCommand(nextIndex === -1 ? '' : (history[nextIndex]?.command ?? ''));
        }
    };

    const loadEntry = (entry) => {
        setOutput(entry);
        inputRef.current?.focus();
    };

    return (
        <TenantSidebarLayout>
            <Head title={`Terminal — ${provider.airline_name}`} />

            <div className="flex items-center gap-3 mb-6">
                <Link href={route('settings.airlines.index')}>
                    <Button variant="ghost" size="icon">
                        <ArrowLeft className="h-4 w-4" />
                    </Button>
                </Link>
                <div>
                    <h2 className="text-3xl font-bold tracking-tight flex items-center gap-2">
                        <TerminalIcon className="h-7 w-7" />
                        VRS Terminal
                    </h2>
                    <p className="text-muted-foreground">
                        {provider.airline_name}
                        {provider.account_name && (
                            <span className="ml-2 text-xs">({provider.account_name})</span>
                        )}
                    </p>
                </div>
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-[280px_1fr] h-[calc(100vh-220px)]">
                {/* History panel */}
                <div className="flex flex-col border rounded-lg overflow-hidden">
                    <div className="px-3 py-2 border-b bg-muted/40 text-xs font-medium text-muted-foreground uppercase tracking-wide flex items-center gap-1.5">
                        <Clock className="h-3.5 w-3.5" />
                        History
                    </div>
                    <div className="flex-1 overflow-y-auto">
                        {history.length === 0 ? (
                            <p className="px-3 py-4 text-xs text-muted-foreground italic">No commands yet.</p>
                        ) : (
                            <ul className="divide-y">
                                {history.map((entry) => (
                                    <li key={entry.id}>
                                        <button
                                            type="button"
                                            onClick={() => loadEntry(entry)}
                                            className={`w-full text-left px-3 py-2.5 text-xs hover:bg-muted/50 transition-colors ${output?.id === entry.id ? 'bg-muted/70 font-medium' : ''}`}
                                        >
                                            <div className="font-mono truncate text-foreground">{entry.command}</div>
                                            <div className="flex items-center gap-2 mt-0.5">
                                                <span className="text-muted-foreground">
                                                    {entry.timestamp.toLocaleTimeString()}
                                                </span>
                                                {entry.error ? (
                                                    <Badge variant="destructive" className="text-[10px] py-0 px-1">error</Badge>
                                                ) : entry.duration_ms !== null ? (
                                                    <span className="text-muted-foreground">{entry.duration_ms}ms</span>
                                                ) : null}
                                            </div>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>

                {/* Main panel */}
                <div className="flex flex-col border rounded-lg overflow-hidden">
                    {/* Output */}
                    <div ref={outputRef} className="flex-1 overflow-y-auto bg-zinc-950 dark:bg-zinc-900 p-4 font-mono text-sm">
                        {output === null ? (
                            <div className="flex flex-col items-center justify-center h-full text-zinc-500 gap-2">
                                <TerminalIcon className="h-10 w-10 opacity-30" />
                                <span className="text-sm">Enter a VRS command below to get started.</span>
                            </div>
                        ) : output.error ? (
                            <div>
                                <div className="text-zinc-400 mb-2 text-xs">$ {output.command}</div>
                                <pre className="text-red-400 whitespace-pre-wrap break-words">{output.error}</pre>
                            </div>
                        ) : (
                            <div>
                                <div className="text-zinc-400 mb-2 text-xs flex items-center gap-3">
                                    <span>$ {output.command}</span>
                                    {output.duration_ms !== null && (
                                        <span className="text-zinc-600">{output.duration_ms}ms</span>
                                    )}
                                </div>
                                <pre className="text-green-400 whitespace-pre-wrap break-words leading-relaxed">
                                    {output.output || <span className="text-zinc-600 italic">(empty response)</span>}
                                </pre>
                            </div>
                        )}
                    </div>

                    {/* Command input */}
                    <div className="border-t bg-muted/20 p-3 flex gap-2 items-center">
                        <span className="text-muted-foreground font-mono text-sm select-none">$</span>
                        <Input
                            ref={inputRef}
                            value={command}
                            onChange={(e) => setCommand(e.target.value)}
                            onKeyDown={handleKeyDown}
                            placeholder="Type a VRS command… (↑↓ for history)"
                            disabled={running}
                            className="font-mono flex-1"
                            autoComplete="off"
                            autoCorrect="off"
                            autoCapitalize="off"
                            spellCheck={false}
                        />
                        <Button
                            type="button"
                            onClick={runCommand}
                            disabled={running || !command.trim()}
                            size="sm"
                        >
                            {running ? (
                                <Loader2 className="h-4 w-4 animate-spin text-primary-foreground" />
                            ) : (
                                <Send className="h-4 w-4" />
                            )}
                        </Button>
                    </div>
                </div>
            </div>
        </TenantSidebarLayout>
    );
}
