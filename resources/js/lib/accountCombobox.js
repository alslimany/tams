export const ACCOUNT_COMBOBOX_DROPDOWN_SELECTOR = '[data-account-combobox-dropdown]';

export function isAccountComboboxDropdownTarget(target) {
    return target instanceof Element && Boolean(target.closest(ACCOUNT_COMBOBOX_DROPDOWN_SELECTOR));
}
