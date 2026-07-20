/**
 * Navigate to the locale switch endpoint for the current tenant context.
 */
export function switchLocale(locale) {
    if (!locale) {
        return;
    }

    const switchUrl = `${route('language.switch')}?locale=${encodeURIComponent(locale)}`;
    window.location.assign(switchUrl);
}

/**
 * Flatten nested translation objects into dot-notation keys.
 *
 * @param {Record<string, unknown>} source
 * @param {string} prefix
 * @returns {Record<string, string>}
 */
export function flattenTranslations(source, prefix = '') {
    if (!source || typeof source !== 'object') {
        return {};
    }

    return Object.entries(source).reduce((carry, [key, value]) => {
        const fullKey = prefix ? `${prefix}.${key}` : key;

        if (value && typeof value === 'object' && !Array.isArray(value)) {
            Object.assign(carry, flattenTranslations(value, fullKey));
        } else if (typeof value === 'string') {
            carry[fullKey] = value;
        }

        return carry;
    }, {});
}
