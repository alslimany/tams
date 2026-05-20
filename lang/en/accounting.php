<?php

return [
    'dashboard' => 'Accounting Dashboard',
    'view_journal' => 'View Journal',

    // Navigation
    'nav' => [
        'section_title' => 'Accounting',
        'settings_heading' => 'Settings',
        'dashboard' => 'Dashboard',
        'overview' => 'Overview',
        'operations' => 'Operations',
        'issuance_history' => 'Issuance History',
        'issuances' => 'Issuances',
        'cancellations' => 'Cancellations',
        'ledger' => 'Ledger',
        'journal' => 'Journal',
        'journal_entries' => 'Journal Entries',
        'coa' => 'Chart of Accounts',
        'chart_of_accounts' => 'Chart of Accounts',
        'trial_balance' => 'Trial Balance',
        'reports' => 'Reports',
        'revenue' => 'Revenue',
        'gross_margin' => 'Gross Margin',
        'vat_summary' => 'VAT Summary',
        'reconciliation' => 'Reconciliation',
        'settlement' => 'Settlement',
        'wallets' => 'Wallets',
        'wallets_balances' => 'Wallet Balances',
        'all_wallets' => 'All Wallets',
        'provider_wallets' => 'Provider Wallets',
        'providers' => 'Providers',
        'preferences' => 'Preferences',
    ],

    // Issuances
    'issuances' => [
        'index' => 'Issuance History',
        'subtitle' => 'All issued products with financial details.',
        'empty' => 'No issuances found.',
        'order' => 'Order',
        'product' => 'Product',
        'provider_ref' => 'Provider Ref',
        'issued_at' => 'Issued At',
        'issued_by' => 'Issued By',
        'selling_price' => 'Selling Price',
        'cost' => 'Cost',
        'vat' => 'VAT',
        'margin' => 'Margin',
        'status' => 'Status',
        'total_count' => 'Total Issuances',
        'total_revenue' => 'Total Revenue',
        'total_cost' => 'Total Cost',
        'total_margin' => 'Total Margin',
        'all_products' => 'All Products',
        'all_statuses' => 'All Statuses',
    ],

    // Cancellations
    'cancellations' => [
        'index' => 'Cancellations',
        'subtitle' => 'Cancelled and refunded orders.',
        'empty' => 'No cancellations found.',
        'order' => 'Order',
        'product' => 'Product',
        'provider_ref' => 'Provider Ref',
        'cancelled_at' => 'Cancelled At',
        'cancelled_by' => 'Cancelled By',
        'original_price' => 'Original Price',
        'fee' => 'Cancellation Fee',
        'net_refunded' => 'Net Refunded',
        'provider_restored' => 'Provider Restored',
        'provider_not_restored' => 'Provider Not Restored',
    ],

    // Ledger
    'ledger' => [
        'account' => 'Account',
        'journal' => 'Journal',
        'coa' => 'Chart of Accounts',
        'trial-balance' => 'Trial Balance',
    ],

    // Reports
    'reports' => [
        'index' => 'Reports',
        'subtitle' => 'Financial reports and analytics.',

        // Revenue
        'revenue_title' => 'Revenue Report',
        'revenue_desc' => 'Total revenue broken down by product and period.',
        'revenue' => 'Revenue',
        'gross_revenue' => 'Gross Revenue',
        'net_revenue' => 'Net Revenue',
        'total_revenue' => 'Total Revenue',
        'total_gross' => 'Total Gross',
        'total_net' => 'Total Net',
        'monthly_trend' => 'Monthly Trend',
        'order_count' => 'Order Count',
        'order' => 'Order',
        'product' => 'Product',
        'date' => 'Date',
        'status' => 'Status',
        'revenue_desc' => 'Revenue details.',

        // Gross margin
        'gross_margin' => 'Gross Margin',
        'margin_title' => 'Gross Margin Report',
        'margin_desc' => 'Revenue minus cost of goods sold.',
        'margin_trend' => 'Margin Trend',
        'gross-margin' => 'Gross Margin',
        'gross_amount' => 'Gross Amount',
        'cost' => 'Cost',
        'margin' => 'Margin',
        'margin_pct' => 'Margin %',
        'total_cost' => 'Total Cost',

        // VAT
        'vat_title' => 'VAT Summary',
        'vat_desc' => 'VAT collected and reversed by period.',
        'vat' => 'VAT',
        'vat_amount' => 'VAT Amount',
        'vat_rate' => 'VAT Rate',
        'vat_collected' => 'VAT Collected',
        'vat_reversed' => 'VAT Reversed',
        'net_vat_payable' => 'Net VAT Payable',
        'vat_transactions' => 'VAT Transactions',
        'no_vat_transactions' => 'No VAT transactions found.',
        'filing_export' => 'Export for Filing',
        'account_2400' => 'Account 2400',

        // Reconciliation
        'reconciliation_title' => 'Reconciliation',
        'reconciliation_desc' => 'Compare wallet balances against ledger accounts.',
        'reconciliation' => 'Reconciliation',
        'wallet' => 'Wallet',
        'wallet_balance' => 'Wallet Balance',
        'ledger_account' => 'Ledger Account',
        'ledger_balance' => 'Ledger Balance',
        'difference' => 'Difference',
        'matched' => 'Matched',
        'mismatch' => 'Mismatch',
        'all_matched' => 'All balances matched.',
        'has_mismatches' => 'Mismatches detected.',
        'investigate' => 'Investigate',
        'last_run' => 'Last Run',
        'rerun' => 'Re-run',

        // Trial balance
        'trial_balance_title' => 'Trial Balance',
        'trial_balance_desc' => 'Debit and credit totals for all accounts.',

        // Aging
        'aging_title' => 'Aging Report',
        'aging_desc' => 'Outstanding receivables by age bucket.',
    ],

    // Settings
    'settings' => [
        'index' => 'Accounting Settings',
        'title' => 'Accounting Settings',
        'desc' => 'Configure accounting preferences for this agency.',
        'save' => 'Save',
        'saved' => 'Settings saved.',
        'update' => 'Update',

        // Tabs
        'tab_general' => 'General',
        'tab_revenue' => 'Revenue',
        'tab_reconciliation' => 'Reconciliation',
        'tab_thresholds' => 'Thresholds',
        'tab_close' => 'Period Close',

        // General
        'general_title' => 'General Settings',
        'general_desc' => 'Basic accounting configuration.',
        'currency' => 'Base Currency',
        'currency_note' => 'The currency used for all accounting entries.',
        'fiscal_year_start' => 'Fiscal Year Start',

        // Revenue
        'revenue_title' => 'Revenue Recognition',
        'revenue_desc' => 'Configure when revenue is recognised.',
        'recognition_trigger' => 'Recognition Trigger',
        'recognition_trigger_note' => 'The event that triggers revenue recognition.',
        'gross_net_display' => 'Display Mode',
        'gross' => 'Gross',
        'gross_note' => 'Show gross amounts before deducting costs.',
        'vat_rate' => 'VAT Rate (%)',
        'vat_reg_number' => 'VAT Registration Number',

        // Reconciliation
        'reconciliation_title' => 'Reconciliation',
        'reconciliation_desc' => 'Automated reconciliation settings.',
        'auto_reconcile_schedule' => 'Auto-Reconcile Schedule',
        'schedule_manual' => 'Manual',
        'schedule_daily' => 'Daily',
        'schedule_weekly' => 'Weekly',
        'alert_on_mismatch' => 'Alert on Mismatch',
        'alert_on_mismatch_note' => 'Send an alert when a reconciliation mismatch is detected.',
        'alert_recipients' => 'Alert Recipients',
        'alert_recipients_note' => 'Comma-separated email addresses.',

        // Thresholds
        'thresholds_title' => 'Provider Thresholds',
        'thresholds_desc' => 'Set per-provider reconciliation thresholds.',
        'per_provider_threshold' => 'Per-Provider Threshold',
        'no_providers' => 'No providers configured.',
        'provider_confirmation' => 'Provider Confirmation',

        // Period close
        'close_title' => 'Period Close',
        'close_desc' => 'Lock a period to prevent further entries.',
        'close_date' => 'Close Date',
        'close_date_set' => 'Period closed up to :date.',
        'auto_lock' => 'Auto-Lock',
        'auto_lock_note' => 'Automatically lock periods after close.',
        'confirm_close_date_title' => 'Confirm Period Close',
        'confirm_close_date_desc' => 'This will lock all entries up to the selected date. This cannot be undone.',
        'confirm_close_date_btn' => 'Confirm Close',
    ],

    // Settlement
    'settlement' => [
        'index' => 'Settlement',
        'agency' => 'Agency',
        'merchant' => 'Merchant',
        'network_subtitle' => 'Outstanding balances across the network.',
        'merchant_subtitle' => 'Outstanding merchant payables.',
        'amount' => 'Amount',
        'date' => 'Date',
        'reference' => 'Reference',
        'status' => 'Status',
        'total' => 'Total',
        'orders' => 'Orders',
        'outstanding' => 'Outstanding',
        'outstanding_receivables' => 'Outstanding Receivables',
        'total_outstanding' => 'Total Outstanding',
        'total_receivable' => 'Total Receivable',
        'total_payable' => 'Total Payable',
        'total_settled' => 'Total Settled',
        'payables' => 'Payables',
        'no_outstanding' => 'No outstanding receivables.',
        'no_payable' => 'No outstanding payables.',
        'no_batches' => 'No settlement batches found.',
        'recent_batches' => 'Recent Batches',
        'view_aging' => 'View Aging',
        'not_applicable' => 'N/A',
        'oldest_unpaid' => 'Oldest Unpaid',

        // Aging
        'aging_title' => 'Aging Report',
        'aging_subtitle' => 'Outstanding balances by age bucket.',
        'current_0_30' => '0–30 days',
        'days_31_60' => '31–60 days',
        'days_61_90' => '61–90 days',
        'days_90_plus' => '90+ days',
        'aging' => 'Aging',
    ],

    // Wallets
    'wallets' => [
        'index' => 'Wallets',
        'show' => 'Wallet Details',
    ],

    // Providers
    'providers' => [
        'index' => 'Providers',
        'show' => 'Provider Details',
    ],
];
