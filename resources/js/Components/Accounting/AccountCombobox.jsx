import React from 'react';
import { createPortal } from 'react-dom';
import { CheckIcon, ChevronDownIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

function accountLabel(account) {
    return `${account.code} — ${account.name}`;
}

export default function AccountCombobox({
    value,
    onChange,
    accounts,
    placeholder = 'Search account…',
    className,
    id,
    open: controlledOpen,
    onOpenChange,
}) {
    const [uncontrolledOpen, setUncontrolledOpen] = React.useState(false);
    const isControlled = controlledOpen !== undefined;
    const open = isControlled ? controlledOpen : uncontrolledOpen;

    const setOpen = React.useCallback(
        (nextOpen) => {
            if (isControlled) {
                onOpenChange?.(nextOpen);
            } else {
                setUncontrolledOpen(nextOpen);
            }
        },
        [isControlled, onOpenChange],
    );

    const [query, setQuery] = React.useState('');
    const [dropdownStyle, setDropdownStyle] = React.useState(null);
    const [highlightedIndex, setHighlightedIndex] = React.useState(-1);
    const inputRef = React.useRef(null);
    const containerRef = React.useRef(null);
    const dropdownRef = React.useRef(null);
    const listRef = React.useRef(null);

    const selectedAccount = React.useMemo(
        () => accounts.find((acc) => acc.code === value) ?? null,
        [accounts, value],
    );

    const filtered = React.useMemo(() => {
        if (!query.trim()) {
            return accounts;
        }
        const lower = query.toLowerCase();
        return accounts.filter(
            (acc) =>
                acc.code.toLowerCase().includes(lower) ||
                acc.name.toLowerCase().includes(lower),
        );
    }, [accounts, query]);

    const displayValue = open
        ? query
        : selectedAccount
          ? accountLabel(selectedAccount)
          : '';

    React.useEffect(() => {
        if (open && filtered.length > 0) {
            setHighlightedIndex((current) =>
                current >= filtered.length ? filtered.length - 1 : current,
            );
        } else if (filtered.length === 0) {
            setHighlightedIndex(-1);
        }
    }, [filtered, open]);

    React.useEffect(() => {
        if (highlightedIndex < 0 || !listRef.current) {
            return;
        }
        listRef.current.children[highlightedIndex]?.scrollIntoView({
            block: 'nearest',
        });
    }, [highlightedIndex]);

    const updateDropdownPosition = React.useCallback(() => {
        if (!inputRef.current) {
            return;
        }
        const rect = inputRef.current.getBoundingClientRect();
        setDropdownStyle({
            position: 'fixed',
            top: rect.bottom + 4,
            left: rect.left,
            width: Math.max(rect.width, 280),
            zIndex: 9999,
        });
    }, []);

    const closeDropdown = React.useCallback(() => {
        setOpen(false);
        setQuery('');
        setHighlightedIndex(-1);
        setDropdownStyle(null);
    }, [setOpen]);

    const selectAccount = React.useCallback(
        (account) => {
            onChange(account.code);
            closeDropdown();
        },
        [onChange, closeDropdown],
    );

    React.useEffect(() => {
        if (!open) {
            return;
        }

        const handlePointerDownOutside = (event) => {
            const target = event.target;
            if (!(target instanceof Node)) {
                return;
            }

            if (containerRef.current?.contains(target)) {
                return;
            }

            if (dropdownRef.current?.contains(target)) {
                return;
            }

            closeDropdown();
        };

        document.addEventListener('pointerdown', handlePointerDownOutside, true);

        return () => document.removeEventListener('pointerdown', handlePointerDownOutside, true);
    }, [open, closeDropdown]);

    React.useLayoutEffect(() => {
        if (!open) {
            return;
        }
        updateDropdownPosition();
        const handleReposition = () => updateDropdownPosition();
        window.addEventListener('scroll', handleReposition, true);
        window.addEventListener('resize', handleReposition);
        return () => {
            window.removeEventListener('scroll', handleReposition, true);
            window.removeEventListener('resize', handleReposition);
        };
    }, [open, updateDropdownPosition, filtered.length]);

    const openDropdown = () => {
        updateDropdownPosition();
        setOpen(true);
        setQuery('');
        setHighlightedIndex(accounts.length > 0 ? 0 : -1);
    };

    const handleKeyDown = (event) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            if (!open) {
                openDropdown();
                return;
            }
            setHighlightedIndex((current) => {
                if (filtered.length === 0) {
                    return -1;
                }
                return current >= filtered.length - 1 ? 0 : current + 1;
            });
            return;
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            if (!open) {
                openDropdown();
                return;
            }
            setHighlightedIndex((current) => {
                if (filtered.length === 0) {
                    return -1;
                }
                return current <= 0 ? filtered.length - 1 : current - 1;
            });
            return;
        }

        if (event.key === 'Enter') {
            if (!open) {
                return;
            }
            event.preventDefault();
            if (highlightedIndex >= 0 && filtered[highlightedIndex]) {
                selectAccount(filtered[highlightedIndex]);
            }
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            closeDropdown();
        }
    };

    const stopDropdownPointerEvent = (event) => {
        event.stopPropagation();
    };

    const dropdown =
        open && dropdownStyle ? (
            <div
                ref={dropdownRef}
                data-account-combobox-dropdown=""
                style={dropdownStyle}
                className="pointer-events-auto"
                onPointerDown={stopDropdownPointerEvent}
                onMouseDown={stopDropdownPointerEvent}
                onWheel={stopDropdownPointerEvent}
            >
                <ul
                    ref={listRef}
                    id={id ? `${id}-listbox` : undefined}
                    role="listbox"
                    className="max-h-60 overflow-y-auto overscroll-contain rounded-md border bg-popover p-1 text-popover-foreground shadow-md"
                >
                    {filtered.length === 0 ? (
                        <li className="px-3 py-6 text-center text-xs text-muted-foreground">
                            No accounts found.
                        </li>
                    ) : (
                        filtered.map((account, index) => {
                            const isSelected = value === account.code;
                            const isHighlighted = index === highlightedIndex;

                            return (
                                <li
                                    key={account.code}
                                    role="option"
                                    aria-selected={isSelected}
                                    className={cn(
                                        'flex cursor-pointer items-center justify-between gap-2 rounded-sm px-2 py-2 text-sm',
                                        isHighlighted
                                            ? 'bg-accent text-accent-foreground'
                                            : 'hover:bg-accent hover:text-accent-foreground',
                                    )}
                                    onMouseEnter={() => setHighlightedIndex(index)}
                                    onMouseDown={(event) => {
                                        event.preventDefault();
                                        event.stopPropagation();
                                        selectAccount(account);
                                    }}
                                >
                                    <span className="min-w-0 truncate">
                                        <span className="mr-2 font-mono text-xs text-muted-foreground">
                                            {account.code}
                                        </span>
                                        {account.name}
                                    </span>
                                    {isSelected && <CheckIcon className="size-4 shrink-0" />}
                                </li>
                            );
                        })
                    )}
                </ul>
            </div>
        ) : null;

    return (
        <div ref={containerRef} className={cn('relative', className)}>
            <div className="relative">
                <input
                    ref={inputRef}
                    id={id}
                    type="text"
                    autoComplete="off"
                    role="combobox"
                    aria-expanded={open}
                    aria-autocomplete="list"
                    aria-controls={id ? `${id}-listbox` : undefined}
                    placeholder={placeholder}
                    value={displayValue}
                    className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 pr-8 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                    onFocus={openDropdown}
                    onChange={(event) => {
                        setQuery(event.target.value);
                        if (!open) {
                            setOpen(true);
                        }
                        setHighlightedIndex(0);
                        updateDropdownPosition();
                    }}
                    onKeyDown={handleKeyDown}
                />
                <ChevronDownIcon className="pointer-events-none absolute top-2.5 right-2.5 size-4 text-muted-foreground" />
            </div>

            {typeof document !== 'undefined' && dropdown
                ? createPortal(dropdown, document.body)
                : null}
        </div>
    );
}
