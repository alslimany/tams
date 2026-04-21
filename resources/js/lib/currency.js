import currency from 'currency.js';

export function formatMoneyValue(amount) {
    return currency(amount || 0, {
        symbol: '',
        separator: ',',
        decimal: '.',
        precision: 2,
    }).format();
}

export function formatMoney(amount, currencyCode = 'USD') {
    return `${formatMoneyValue(amount)} ${currencyCode}`;
}
