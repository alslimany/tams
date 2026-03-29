import React, { useState, useEffect, useRef } from 'react';
import axios from 'axios';
import { Plane } from "lucide-react";

export function AsyncAirportSelect({ value, onChange, placeholder, id, className, isDestination = false }) {
    const [query, setQuery] = useState(value || '');
    const [options, setOptions] = useState([]);
    const [isOpen, setIsOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const wrapperRef = useRef(null);

    useEffect(() => {
        function handleClickOutside(event) {
            if (wrapperRef.current && !wrapperRef.current.contains(event.target)) {
                setIsOpen(false);
            }
        }
        document.addEventListener("mousedown", handleClickOutside);
        return () => document.removeEventListener("mousedown", handleClickOutside);
    }, [wrapperRef]);

    useEffect(() => {
        const fetchOptions = async () => {
            if (!query || query.length < 2) {
                setOptions([]);
                return;
            }
            setLoading(true);
            try {
                // Determine base URL, assuming Inertia's Ziggy `route()` is available globally
                const url = typeof route !== 'undefined' 
                    ? route('api.airports.search', { q: query }) 
                    : `/api/airports/search?q=${encodeURIComponent(query)}`;
                    
                const { data } = await axios.get(url);
                setOptions(data);
            } catch (error) {
                console.error("Failed to fetch airports", error);
            } finally {
                setLoading(false);
            }
        };

        const timeoutId = setTimeout(() => {
            fetchOptions();
        }, 300);

        return () => clearTimeout(timeoutId);
    }, [query]);

    useEffect(() => {
        if (value && value !== query && !isOpen) {
           setQuery(value);
        }
    }, [value, isOpen, query]);

    return (
        <div ref={wrapperRef} className={`relative ${className || ''}`}>
            <input
                id={id}
                type="text"
                autoComplete="off"
                placeholder={placeholder}
                value={query}
                onChange={(e) => {
                    setQuery(e.target.value);
                    setIsOpen(true);
                }}
                onFocus={() => setIsOpen(true)}
                className="flex h-12 w-full rounded-md border border-input bg-background px-3 py-2 pl-10 text-lg font-bold uppercase tracking-widest ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
            />
            <Plane className={`absolute left-3 top-3.5 h-5 w-5 text-muted-foreground ${isDestination ? 'rotate-90' : ''}`} />
            
            {isOpen && (query.length >= 2) && (
                <div className="absolute z-50 w-full mt-1 bg-white border rounded-md shadow-lg max-h-60 overflow-auto">
                    {loading ? (
                        <div className="p-3 text-sm text-gray-500">Searching...</div>
                    ) : options.length > 0 ? (
                        <ul>
                            {options.map((option) => (
                                <li
                                    key={option.id}
                                    className="px-4 py-2 hover:bg-gray-100 cursor-pointer text-sm"
                                    onClick={() => {
                                        const code = option.iata_code || option.icao_code || option.name;
                                        setQuery(code);
                                        onChange({ target: { value: code } });
                                        setIsOpen(false);
                                    }}
                                >
                                    <div className="font-bold text-gray-900">{option.iata_code || option.icao_code}</div>
                                    <div className="text-gray-600 truncate">{option.name}, {option.city}, {option.country}</div>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <div className="p-3 text-sm text-gray-500">No airports found.</div>
                    )}
                </div>
            )}
        </div>
    );
}
