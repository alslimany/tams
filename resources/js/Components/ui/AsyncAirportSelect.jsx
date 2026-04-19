import React, { useState, useEffect, useRef } from 'react';
import axios from 'axios';
import { CheckIcon, ChevronDownIcon, PlaneIcon } from 'lucide-react';

import { Input } from '@/Components/ui/Input';
import { cn } from '@/lib/utils';

export function AsyncAirportSelect({ value, onChange, placeholder, id, className, isDestination = false }) {
    const [query, setQuery] = useState(value || '');
    const [options, setOptions] = useState([]);
    const [isOpen, setIsOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [highlightedIndex, setHighlightedIndex] = useState(-1);
    const wrapperRef = useRef(null);
    const listRef = useRef(null);

    const getAirportLabel = (option) => {
        const optionName = typeof option.name === 'string' ? option.name : option.name?.en || option.name?.ar || '';
        const optionCity = typeof option.city === 'string' ? option.city : option.city?.en || option.city?.ar || '';
        const optionCountry = typeof option.country === 'string' ? option.country : option.country?.en || option.country?.ar || '';

        return `${option.iata_code} - ${optionName}${optionCity ? `, ${optionCity}` : ''}${optionCountry ? `, ${optionCountry}` : ''}`;
    };

    const emitValue = (airportCode) => {
        onChange({ target: { value: airportCode } });
    };

    const selectOption = (option) => {
        const airportCode = option.iata_code || option.icao_code || option.name;

        setQuery(airportCode);
        emitValue(airportCode);
        setIsOpen(false);
        setHighlightedIndex(-1);
    };

    const fetchAirports = async (searchQuery = '') => {
        setLoading(true);

        try {
            const url = typeof route !== 'undefined'
                ? route('api.airports.search', { q: searchQuery })
                : `/api/airports/search?q=${encodeURIComponent(searchQuery)}`;

            const { data } = await axios.get(url);
            setOptions(data);
            setHighlightedIndex(data.length > 0 ? 0 : -1);
        } catch (error) {
            console.error('Failed to fetch airports', error);
            setOptions([]);
            setHighlightedIndex(-1);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        const handleClickOutside = (event) => {
            if (wrapperRef.current && !wrapperRef.current.contains(event.target)) {
                setIsOpen(false);
                setHighlightedIndex(-1);
            }
        };

        document.addEventListener('mousedown', handleClickOutside);

        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, [wrapperRef]);

    useEffect(() => {
        const timeoutId = setTimeout(() => {
            if (!isOpen) {
                return;
            }

            if (query.length === 0) {
                fetchAirports('');

                return;
            }

            if (query.length < 2) {
                setOptions([]);
                setHighlightedIndex(-1);

                return;
            }

            fetchAirports(query);
        }, 300);

        return () => clearTimeout(timeoutId);
    }, [query, isOpen]);

    useEffect(() => {
        if (value !== query && !isOpen) {
            setQuery(value);
        }
    }, [value, isOpen, query]);

    useEffect(() => {
        if (!isOpen || highlightedIndex < 0) {
            return;
        }

        const activeItem = listRef.current?.querySelector(`[data-index='${highlightedIndex}']`);

        if (activeItem) {
            activeItem.scrollIntoView({ block: 'nearest' });
        }
    }, [highlightedIndex, isOpen]);

    const handleInputFocus = () => {
        setIsOpen(true);

        if (options.length === 0) {
            fetchAirports('');
        }
    };

    const handleInputChange = (event) => {
        const nextQuery = event.target.value.toUpperCase();

        setQuery(nextQuery);
        emitValue(nextQuery);
        setIsOpen(true);
    };

    const handleInputKeyDown = (event) => {
        if (!isOpen) {
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setHighlightedIndex((currentIndex) => {
                if (options.length === 0) {
                    return -1;
                }

                return currentIndex >= options.length - 1 ? 0 : currentIndex + 1;
            });

            return;
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            setHighlightedIndex((currentIndex) => {
                if (options.length === 0) {
                    return -1;
                }

                return currentIndex <= 0 ? options.length - 1 : currentIndex - 1;
            });

            return;
        }

        if (event.key === 'Escape') {
            setIsOpen(false);
            setHighlightedIndex(-1);

            return;
        }

        if (event.key === 'Enter') {
            event.preventDefault();

            const highlightedOption = highlightedIndex >= 0 ? options[highlightedIndex] : null;

            if (highlightedOption) {
                selectOption(highlightedOption);

                return;
            }

            const typedCode = query.trim().toUpperCase();

            if (typedCode.length === 3) {
                const exactMatch = options.find((option) => option.iata_code?.toUpperCase() === typedCode);

                if (exactMatch) {
                    selectOption(exactMatch);

                    return;
                }

                emitValue(typedCode);
                setIsOpen(false);
                setHighlightedIndex(-1);
            }
        }
    };

    return (
        <div ref={wrapperRef} className={cn('relative', className)}>
            <Input
                id={id}
                autoComplete="off"
                placeholder={placeholder}
                value={query}
                onChange={handleInputChange}
                onFocus={handleInputFocus}
                onKeyDown={handleInputKeyDown}
                className="pl-9 pr-9 uppercase"
            />
            <PlaneIcon className={`absolute left-3 top-2.5 h-4 w-4 text-muted-foreground ${isDestination ? 'rotate-90' : ''}`} />
            <ChevronDownIcon className="absolute right-3 top-2.5 h-4 w-4 text-muted-foreground" />

            {isOpen && (
                <div ref={listRef} className="absolute z-50 mt-1 max-h-72 w-full overflow-auto rounded-md border bg-popover p-1 text-popover-foreground shadow-md">
                    {loading ? (
                        <div className="p-3 text-sm text-muted-foreground">Searching airports...</div>
                    ) : options.length > 0 ? (
                        <ul>
                            {options.map((option, index) => {
                                const isSelected = query.toUpperCase() === option.iata_code?.toUpperCase();

                                return (
                                <li
                                    key={option.id}
                                    data-index={index}
                                    className={cn(
                                        'flex cursor-pointer items-start justify-between rounded-sm px-2 py-2 text-sm',
                                        highlightedIndex === index ? 'bg-accent text-accent-foreground' : 'hover:bg-accent hover:text-accent-foreground',
                                    )}
                                    onMouseDown={(event) => event.preventDefault()}
                                    onMouseEnter={() => setHighlightedIndex(index)}
                                    onClick={() => selectOption(option)}
                                >
                                    <div className="min-w-0">
                                        <p className="font-medium">{option.iata_code || option.icao_code}</p>
                                        <p className="truncate text-xs text-muted-foreground">{getAirportLabel(option)}</p>
                                    </div>
                                    {isSelected && <CheckIcon className="mt-0.5 h-4 w-4 shrink-0" />}
                                </li>
                                );
                            })}
                        </ul>
                    ) : (
                        <div className="p-3 text-sm text-muted-foreground">No airports found.</div>
                    )}
                </div>
            )}
        </div>
    );
}
