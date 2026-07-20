import { useState, useEffect, useMemo } from 'react';
import { usePage } from '@inertiajs/react';

import { flattenTranslations } from '@/lib/i18n';

export const useTranslation = () => {
    const { props } = usePage();
    const currentLocale = props.locale || 'en';
    const sharedTranslations = useMemo(
        () => flattenTranslations(props.translations ?? {}),
        [props.translations],
    );
    const [translations, setTranslations] = useState(sharedTranslations);
    const [loading, setLoading] = useState(Object.keys(sharedTranslations).length === 0);

    useEffect(() => {
        setTranslations(sharedTranslations);

        const loadTranslations = async () => {
            try {
                setLoading(true);
                const response = await fetch(`/lang/${currentLocale}.json?v=${encodeURIComponent(currentLocale)}`, {
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                });

                if (!response.ok) {
                    throw new Error(`Failed to fetch /lang/${currentLocale}.json`);
                }

                const payload = await response.json();
                setTranslations(payload || sharedTranslations);
            } catch (error) {
                console.warn(`Failed to load translations from /lang for locale: ${currentLocale}`, error);
                setTranslations(sharedTranslations);
            } finally {
                setLoading(false);
            }
        };

        loadTranslations();
    }, [currentLocale, sharedTranslations]);

    const t = (key, params = {}) => {
        if (loading) {
            return key; // Return key while loading
        }

        let translation = translations[key] || key;

        // Parameter replacement logic
        Object.keys(params).forEach(param => {
            translation = translation.replace(new RegExp(`:${param}`, 'g'), params[param]);
        });

        return translation;
    };

    const __ = t; // Alias for Laravel compatibility

    // Helper function to get translated airline name by IATA code
    const getAirlineName = (iataCode) => {
        if (loading) return iataCode;
        return translations[`common.airlines.${iataCode}`] || iataCode;
    };

    // Helper function to get translated currency name by code
    const getCurrencyName = (currencyCode) => {
        if (loading) return currencyCode;
        const lowerCode = currencyCode.toLowerCase();
        return translations[`common.${lowerCode}`] || currencyCode;
    };

    // Helper function to get translated cabin name
    const getCabinName = (cabinName) => {
        if (loading) return cabinName;
        const lowerCabin = cabinName.toLowerCase();
        return translations[`common.cabin_${lowerCabin}`] || cabinName;
    };

    return {
        t,
        __,
        loading,
        locale: currentLocale,
        getAirlineName,
        getCurrencyName,
        getCabinName
    };
};
