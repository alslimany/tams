-- -------------------------------------------------------------
-- TablePlus 6.8.6(662)
--
-- https://tableplus.com/
--
-- Database: tenantmetadata-default-merchant-tPhF.sqlite
-- Generation Time: 2026-05-23 01:05:49.9560
-- -------------------------------------------------------------


DROP TABLE IF EXISTS "migrations";
CREATE TABLE "migrations" ("id" integer primary key autoincrement not null, "migration" varchar not null, "batch" integer not null);

DROP TABLE IF EXISTS "sqlite_sequence";
CREATE TABLE sqlite_sequence(name,seq);

DROP TABLE IF EXISTS "users";
CREATE TABLE "users" ("id" integer primary key autoincrement not null, "name" varchar not null, "email" varchar not null, "email_verified_at" datetime, "password" varchar not null, "remember_token" varchar, "created_at" datetime, "updated_at" datetime, "two_factor_secret" text, "two_factor_recovery_codes" text, "two_factor_confirmed_at" datetime, "role" varchar not null default 'agent', "is_active" tinyint(1) not null default '1', "last_login_at" datetime, "last_activity_at" datetime);

DROP TABLE IF EXISTS "password_reset_tokens";
CREATE TABLE "password_reset_tokens" ("email" varchar not null, "token" varchar not null, "created_at" datetime, primary key ("email"));

DROP TABLE IF EXISTS "sessions";
CREATE TABLE "sessions" ("id" varchar not null, "user_id" integer, "ip_address" varchar, "user_agent" text, "payload" text not null, "last_activity" integer not null, primary key ("id"));

DROP TABLE IF EXISTS "personal_access_tokens";
CREATE TABLE "personal_access_tokens" ("id" integer primary key autoincrement not null, "tokenable_type" varchar not null, "tokenable_id" integer not null, "name" text not null, "token" varchar not null, "abilities" text, "last_used_at" datetime, "expires_at" datetime, "created_at" datetime, "updated_at" datetime);

DROP TABLE IF EXISTS "tenant_providers";
CREATE TABLE "tenant_providers" ("id" integer primary key autoincrement not null, "provider_type" varchar not null, "airline_code" varchar not null, "airline_name" varchar not null, "account_name" varchar, "credentials" text not null, "is_active" tinyint(1) not null default '1', "created_at" datetime, "updated_at" datetime, "last_tested_at" datetime, "last_test_status" varchar, "last_test_message" text, "last_used_at" datetime, "domestic_commission_rate" numeric, "international_commission_rate" numeric, "commission_domestic" numeric not null default '0', "commission_international" numeric not null default '0');

DROP TABLE IF EXISTS "customers";
CREATE TABLE "customers" ("id" integer primary key autoincrement not null, "first_name" varchar not null, "last_name" varchar not null, "email" varchar, "phone" varchar, "passport_number" varchar, "passport_expiry" date, "created_at" datetime, "updated_at" datetime);

DROP TABLE IF EXISTS "wallets";
CREATE TABLE "wallets" ("id" integer primary key autoincrement not null, "holder_type" varchar not null, "holder_id" integer not null, "name" varchar not null, "slug" varchar not null, "uuid" varchar not null, "description" varchar, "meta" text, "balance" numeric not null default '0', "decimal_places" integer not null default '2', "deleted_at" datetime, "created_at" datetime, "updated_at" datetime);

DROP TABLE IF EXISTS "transactions";
CREATE TABLE "transactions" ("id" integer primary key autoincrement not null, "payable_type" varchar not null, "payable_id" integer not null, "wallet_id" integer not null, "type" varchar not null, "amount" numeric not null, "confirmed" tinyint(1) not null, "meta" text, "uuid" varchar not null, "deleted_at" datetime, "created_at" datetime, "updated_at" datetime, foreign key("wallet_id") references "wallets"("id") on delete cascade);

DROP TABLE IF EXISTS "transfers";
CREATE TABLE "transfers" ("id" integer primary key autoincrement not null, "from_type" varchar not null, "from_id" integer not null, "to_type" varchar not null, "to_id" integer not null, "status" varchar check ("status" in ('exchange', 'transfer', 'paid', 'refund', 'gift')) not null default 'transfer', "status_last" varchar check ("status_last" in ('exchange', 'transfer', 'paid', 'refund', 'gift')), "deposit_id" integer not null, "withdraw_id" integer not null, "discount" numeric not null default '0', "fee" numeric not null default '0', "uuid" varchar not null, "deleted_at" datetime, "created_at" datetime, "updated_at" datetime, foreign key("deposit_id") references "transactions"("id") on delete cascade, foreign key("withdraw_id") references "transactions"("id") on delete cascade);

DROP TABLE IF EXISTS "orders";
CREATE TABLE "orders" ("id" varchar not null, "owner_type" varchar not null, "owner_id" integer not null, "number" varchar not null, "status" varchar not null default 'pending', "issued_at" datetime, "due_at" datetime, "subtotal" numeric not null, "tax_total" numeric not null, "grand_total" numeric not null, "amount_paid" numeric not null default '0', "amount_refunded" numeric not null default '0', "currency" varchar not null, "payment_method" varchar not null, "payment_reference" varchar, "contact" text, "parent_id" varchar, "deleted_at" datetime, "created_at" datetime, "updated_at" datetime, "ledger_entry_id" integer, foreign key("parent_id") references "orders"("id") on delete set null, primary key ("id"));

DROP TABLE IF EXISTS "order_status_log";
CREATE TABLE "order_status_log" ("id" integer primary key autoincrement not null, "order_id" varchar not null, "old_status" varchar, "new_status" varchar not null, "user_id" integer, "comment" text, "created_at" datetime, "updated_at" datetime, foreign key("order_id") references "orders"("id") on delete cascade, foreign key("user_id") references "users"("id") on delete set null);

DROP TABLE IF EXISTS "airline_accounts";
CREATE TABLE "airline_accounts" ("id" integer primary key autoincrement not null, "tenant_provider_id" integer not null, "currency" varchar not null, "balance" numeric not null default '0', "external_reference" varchar, "created_at" datetime, "updated_at" datetime, foreign key("tenant_provider_id") references "tenant_providers"("id") on delete cascade);

DROP TABLE IF EXISTS "airline_transactions";
CREATE TABLE "airline_transactions" ("id" integer primary key autoincrement not null, "airline_account_id" integer not null, "type" varchar not null, "amount" numeric not null, "balance_after" numeric not null, "order_item_id" integer, "external_reference" varchar, "description" text, "created_at" datetime, "updated_at" datetime, foreign key("airline_account_id") references "airline_accounts"("id") on delete cascade, foreign key("order_item_id") references "order_items"("id") on delete set null);

DROP TABLE IF EXISTS "tickets";
CREATE TABLE "tickets" ("id" integer primary key autoincrement not null, "ticket_number" varchar not null, "status" varchar not null default ('issued'), "issued_at" datetime, "voided_at" datetime, "refunded_at" datetime, "raw_response" text, "created_at" datetime, "updated_at" datetime);

DROP TABLE IF EXISTS "passengers";
CREATE TABLE "passengers" ("id" integer primary key autoincrement not null, "type" varchar not null default ('adult'), "first_name" varchar not null, "last_name" varchar not null, "dob" date, "gender" varchar, "passport_number" varchar, "passport_expiry" date, "ticket_number" varchar, "created_at" datetime, "updated_at" datetime, "passport_issue_country" varchar, "nationality" varchar);

DROP TABLE IF EXISTS "order_items";
CREATE TABLE "order_items" ("id" integer primary key autoincrement not null, "order_id" varchar not null, "type" varchar not null, "product_subtype" varchar, "provider" varchar not null, "provider_reference" varchar, "ticket_number" varchar, "item_details" text not null, "price" numeric not null, "taxes" text, "total" numeric not null, "currency" varchar not null, "exchange_rate" numeric not null default ('1'), "status" varchar not null default ('issued'), "net_commission" numeric, "agent_commission" numeric, "paid" numeric not null default ('0'), "remaining" numeric not null default ('0'), "refund_parent_id" varchar, "refund_status" varchar not null default ('none'), "wallet_transaction_id" varchar, "airline_transaction_id" integer, "created_at" datetime, "updated_at" datetime, "product_type" varchar, "net_fare" numeric, "total_tax" numeric, "total_amount" numeric, "commission_percent" numeric, "commission_amount" numeric, "net_after_commission" numeric, "transaction_type" varchar, "product_details" text, "ledger_entry_id" integer, "used_master_agency_provider" tinyint(1) not null default '0', "master_commission_percent" numeric, foreign key("order_id") references orders("id") on delete cascade on update no action, foreign key("airline_transaction_id") references airline_transactions("id") on delete set null on update no action, foreign key("wallet_transaction_id") references "transactions"("uuid") on delete set null);

DROP TABLE IF EXISTS "journal_details";
CREATE TABLE "journal_details" ("journalDetailId" integer primary key autoincrement not null, "journalEntryId" integer not null, "ledgerUuid" varchar not null, "amount" varchar not null, "journalReferenceUuid" varchar);

DROP TABLE IF EXISTS "journal_entries";
CREATE TABLE "journal_entries" ("journalEntryId" integer primary key autoincrement not null, "transDate" datetime not null, "domainUuid" varchar not null, "subJournalUuid" varchar, "currency" varchar not null, "opening" integer not null, "clearing" integer not null default '0', "reviewed" integer not null, "locked" integer not null default '0', "description" varchar not null, "arguments" text not null, "language" varchar not null, "extra" text, "journalReferenceUuid" varchar, "createdBy" varchar, "updatedBy" varchar, "revision" datetime, "created_at" datetime, "updated_at" datetime);

DROP TABLE IF EXISTS "journal_references";
CREATE TABLE "journal_references" ("journalReferenceUuid" varchar not null, "domainUuid" varchar not null, "code" varchar not null, "extra" text, "revision" datetime, "created_at" datetime, "updated_at" datetime, primary key ("journalReferenceUuid"));

DROP TABLE IF EXISTS "ledger_accounts";
CREATE TABLE "ledger_accounts" ("ledgerUuid" varchar not null, "code" varchar not null, "taxCode" varchar, "parentUuid" varchar, "debit" tinyint(1) not null, "credit" tinyint(1) not null, "category" tinyint(1) not null, "closed" tinyint(1) not null, "extra" text, "flex" text, "revision" datetime, "created_at" datetime, "updated_at" datetime, primary key ("ledgerUuid"));

DROP TABLE IF EXISTS "ledger_balances";
CREATE TABLE "ledger_balances" ("id" integer primary key autoincrement not null, "ledgerUuid" varchar not null, "domainUuid" varchar not null, "currency" varchar not null, "balance" varchar not null, "created_at" datetime, "updated_at" datetime);

DROP TABLE IF EXISTS "ledger_currencies";
CREATE TABLE "ledger_currencies" ("code" varchar not null, "decimals" integer not null, "revision" datetime, "created_at" datetime, "updated_at" datetime, primary key ("code"));

DROP TABLE IF EXISTS "ledger_domains";
CREATE TABLE "ledger_domains" ("domainUuid" varchar not null, "code" varchar not null, "extra" text, "flex" text, "currencyDefault" varchar not null, "subJournals" tinyint(1) not null default '0', "revision" datetime, "created_at" datetime, "updated_at" datetime, primary key ("domainUuid"));

DROP TABLE IF EXISTS "ledger_names";
CREATE TABLE "ledger_names" ("id" integer primary key autoincrement not null, "ownerUuid" varchar not null, "language" varchar not null, "name" varchar not null, "created_at" datetime, "updated_at" datetime);

DROP TABLE IF EXISTS "ledger_reports";
CREATE TABLE "ledger_reports" ("id" integer primary key autoincrement not null, "name" varchar not null, "domainUuid" varchar not null, "currency" varchar not null, "fromDate" date, "toDate" date not null, "journalEntryId" integer not null, "reportData" text not null);

DROP TABLE IF EXISTS "sub_journals";
CREATE TABLE "sub_journals" ("subJournalUuid" varchar not null, "code" varchar not null, "extra" text, "revision" datetime, "created_at" datetime, "updated_at" datetime, primary key ("subJournalUuid"));

DROP TABLE IF EXISTS "agency_settings";
CREATE TABLE "agency_settings" ("id" integer primary key autoincrement not null, "can_use_own_airline_credentials" tinyint(1) not null default '1', "force_use_default_agency" tinyint(1) not null default '0', "default_agency_tenant_id" varchar, "master_commission_percent" numeric not null default '0', "created_at" datetime, "updated_at" datetime);

DROP TABLE IF EXISTS "tenant_insurance_providers";
CREATE TABLE "tenant_insurance_providers" ("id" integer primary key autoincrement not null, "provider_type" varchar not null default 'albaraka', "name" varchar not null default 'Al Baraka Insurance', "credentials" text, "is_active" tinyint(1) not null default '1', "commission_compulsory" numeric not null default '0', "commission_travel" numeric not null default '0', "commission_orange" numeric not null default '0', "created_at" datetime, "updated_at" datetime);

DROP TABLE IF EXISTS "tenant_insurance_provider_accounts";
CREATE TABLE "tenant_insurance_provider_accounts" ("id" integer primary key autoincrement not null, "tenant_insurance_provider_id" integer not null, "currency" varchar not null default 'LYD', "balance" numeric not null default '0', "created_at" datetime, "updated_at" datetime, foreign key("tenant_insurance_provider_id") references "tenant_insurance_providers"("id") on delete cascade);

DROP TABLE IF EXISTS "tenant_insurance_provider_transactions";
CREATE TABLE "tenant_insurance_provider_transactions" ("id" integer primary key autoincrement not null, "tenant_insurance_provider_account_id" integer not null, "type" varchar not null, "amount" numeric not null, "balance_after" numeric not null, "order_id" varchar, "order_item_id" integer, "external_reference" varchar, "description" text, "meta" text, "created_at" datetime, "updated_at" datetime, foreign key("tenant_insurance_provider_account_id") references "tenant_insurance_provider_accounts"("id") on delete cascade, foreign key("order_id") references "orders"("id") on delete set null, foreign key("order_item_id") references "order_items"("id") on delete set null);

DROP TABLE IF EXISTS "tenant_hotel_providers";
CREATE TABLE "tenant_hotel_providers" ("id" integer primary key autoincrement not null, "provider_type" varchar not null default '3t', "name" varchar not null default '3T Hotels', "credentials" text, "is_active" tinyint(1) not null default '1', "commission_hotel" numeric not null default '0', "created_at" datetime, "updated_at" datetime, "currency" varchar not null default 'LYD', "civility_codes" text);

DROP TABLE IF EXISTS "notification_logs";
CREATE TABLE "notification_logs" ("id" varchar not null, "channel" varchar not null, "event" varchar not null, "recipient" varchar not null, "message" text, "status" varchar not null, "error" varchar, "notifiable_type" varchar, "notifiable_id" varchar, "created_at" datetime, "updated_at" datetime, primary key ("id"));

DROP TABLE IF EXISTS "api_audit_logs";
CREATE TABLE "api_audit_logs" ("id" integer primary key autoincrement not null, "token_id" integer, "user_id" integer, "method" varchar not null, "path" varchar not null, "ip" varchar, "user_agent" varchar, "status_code" integer, "duration_ms" integer, "abilities" text, "created_at" datetime, "updated_at" datetime);

DROP TABLE IF EXISTS "tenant_esim_providers";
CREATE TABLE "tenant_esim_providers" ("id" integer primary key autoincrement not null, "provider_type" varchar not null default 'l2', "name" varchar not null default 'L2 Travel eSIM', "credentials" text, "is_active" tinyint(1) not null default '1', "commission_esim" numeric not null default '0', "created_at" datetime, "updated_at" datetime, "currency" varchar not null default 'USD');

DROP TABLE IF EXISTS "migrations";
CREATE TABLE "migrations" ("id" integer primary key autoincrement not null, "migration" varchar not null, "batch" integer not null);

DROP TABLE IF EXISTS "sqlite_sequence";
CREATE TABLE sqlite_sequence(name,seq);

DROP TABLE IF EXISTS "users";
CREATE TABLE "users" ("id" integer primary key autoincrement not null, "name" varchar not null, "email" varchar not null, "email_verified_at" datetime, "password" varchar not null, "remember_token" varchar, "created_at" datetime, "updated_at" datetime, "two_factor_secret" text, "two_factor_recovery_codes" text, "two_factor_confirmed_at" datetime, "role" varchar not null default 'agent', "is_active" tinyint(1) not null default '1', "last_login_at" datetime, "last_activity_at" datetime);

DROP TABLE IF EXISTS "password_reset_tokens";
CREATE TABLE "password_reset_tokens" ("email" varchar not null, "token" varchar not null, "created_at" datetime, primary key ("email"));

DROP TABLE IF EXISTS "sessions";
CREATE TABLE "sessions" ("id" varchar not null, "user_id" integer, "ip_address" varchar, "user_agent" text, "payload" text not null, "last_activity" integer not null, primary key ("id"));

DROP TABLE IF EXISTS "personal_access_tokens";
CREATE TABLE "personal_access_tokens" ("id" integer primary key autoincrement not null, "tokenable_type" varchar not null, "tokenable_id" integer not null, "name" text not null, "token" varchar not null, "abilities" text, "last_used_at" datetime, "expires_at" datetime, "created_at" datetime, "updated_at" datetime);

DROP TABLE IF EXISTS "tenant_providers";
CREATE TABLE "tenant_providers" ("id" integer primary key autoincrement not null, "provider_type" varchar not null, "airline_code" varchar not null, "airline_name" varchar not null, "account_name" varchar, "credentials" text not null, "is_active" tinyint(1) not null default '1', "created_at" datetime, "updated_at" datetime, "last_tested_at" datetime, "last_test_status" varchar, "last_test_message" text, "last_used_at" datetime, "domestic_commission_rate" numeric, "international_commission_rate" numeric, "commission_domestic" numeric not null default '0', "commission_international" numeric not null default '0');

DROP TABLE IF EXISTS "customers";
CREATE TABLE "customers" ("id" integer primary key autoincrement not null, "first_name" varchar not null, "last_name" varchar not null, "email" varchar, "phone" varchar, "passport_number" varchar, "passport_expiry" date, "created_at" datetime, "updated_at" datetime);

DROP TABLE IF EXISTS "wallets";
CREATE TABLE "wallets" ("id" integer primary key autoincrement not null, "holder_type" varchar not null, "holder_id" integer not null, "name" varchar not null, "slug" varchar not null, "uuid" varchar not null, "description" varchar, "meta" text, "balance" numeric not null default '0', "decimal_places" integer not null default '2', "deleted_at" datetime, "created_at" datetime, "updated_at" datetime);

DROP TABLE IF EXISTS "transactions";
CREATE TABLE "transactions" ("id" integer primary key autoincrement not null, "payable_type" varchar not null, "payable_id" integer not null, "wallet_id" integer not null, "type" varchar not null, "amount" numeric not null, "confirmed" tinyint(1) not null, "meta" text, "uuid" varchar not null, "deleted_at" datetime, "created_at" datetime, "updated_at" datetime, foreign key("wallet_id") references "wallets"("id") on delete cascade);

DROP TABLE IF EXISTS "transfers";
CREATE TABLE "transfers" ("id" integer primary key autoincrement not null, "from_type" varchar not null, "from_id" integer not null, "to_type" varchar not null, "to_id" integer not null, "status" varchar check ("status" in ('exchange', 'transfer', 'paid', 'refund', 'gift')) not null default 'transfer', "status_last" varchar check ("status_last" in ('exchange', 'transfer', 'paid', 'refund', 'gift')), "deposit_id" integer not null, "withdraw_id" integer not null, "discount" numeric not null default '0', "fee" numeric not null default '0', "uuid" varchar not null, "deleted_at" datetime, "created_at" datetime, "updated_at" datetime, foreign key("deposit_id") references "transactions"("id") on delete cascade, foreign key("withdraw_id") references "transactions"("id") on delete cascade);

DROP TABLE IF EXISTS "orders";
CREATE TABLE "orders" ("id" varchar not null, "owner_type" varchar not null, "owner_id" integer not null, "number" varchar not null, "status" varchar not null default 'pending', "issued_at" datetime, "due_at" datetime, "subtotal" numeric not null, "tax_total" numeric not null, "grand_total" numeric not null, "amount_paid" numeric not null default '0', "amount_refunded" numeric not null default '0', "currency" varchar not null, "payment_method" varchar not null, "payment_reference" varchar, "contact" text, "parent_id" varchar, "deleted_at" datetime, "created_at" datetime, "updated_at" datetime, "ledger_entry_id" integer, foreign key("parent_id") references "orders"("id") on delete set null, primary key ("id"));

DROP TABLE IF EXISTS "order_status_log";
CREATE TABLE "order_status_log" ("id" integer primary key autoincrement not null, "order_id" varchar not null, "old_status" varchar, "new_status" varchar not null, "user_id" integer, "comment" text, "created_at" datetime, "updated_at" datetime, foreign key("order_id") references "orders"("id") on delete cascade, foreign key("user_id") references "users"("id") on delete set null);

DROP TABLE IF EXISTS "airline_accounts";
CREATE TABLE "airline_accounts" ("id" integer primary key autoincrement not null, "tenant_provider_id" integer not null, "currency" varchar not null, "balance" numeric not null default '0', "external_reference" varchar, "created_at" datetime, "updated_at" datetime, foreign key("tenant_provider_id") references "tenant_providers"("id") on delete cascade);

DROP TABLE IF EXISTS "airline_transactions";
CREATE TABLE "airline_transactions" ("id" integer primary key autoincrement not null, "airline_account_id" integer not null, "type" varchar not null, "amount" numeric not null, "balance_after" numeric not null, "order_item_id" integer, "external_reference" varchar, "description" text, "created_at" datetime, "updated_at" datetime, foreign key("airline_account_id") references "airline_accounts"("id") on delete cascade, foreign key("order_item_id") references "order_items"("id") on delete set null);

DROP TABLE IF EXISTS "tickets";
CREATE TABLE "tickets" ("id" integer primary key autoincrement not null, "ticket_number" varchar not null, "status" varchar not null default ('issued'), "issued_at" datetime, "voided_at" datetime, "refunded_at" datetime, "raw_response" text, "created_at" datetime, "updated_at" datetime);

DROP TABLE IF EXISTS "passengers";
CREATE TABLE "passengers" ("id" integer primary key autoincrement not null, "type" varchar not null default ('adult'), "first_name" varchar not null, "last_name" varchar not null, "dob" date, "gender" varchar, "passport_number" varchar, "passport_expiry" date, "ticket_number" varchar, "created_at" datetime, "updated_at" datetime, "passport_issue_country" varchar, "nationality" varchar);

DROP TABLE IF EXISTS "order_items";
CREATE TABLE "order_items" ("id" integer primary key autoincrement not null, "order_id" varchar not null, "type" varchar not null, "product_subtype" varchar, "provider" varchar not null, "provider_reference" varchar, "ticket_number" varchar, "item_details" text not null, "price" numeric not null, "taxes" text, "total" numeric not null, "currency" varchar not null, "exchange_rate" numeric not null default ('1'), "status" varchar not null default ('issued'), "net_commission" numeric, "agent_commission" numeric, "paid" numeric not null default ('0'), "remaining" numeric not null default ('0'), "refund_parent_id" varchar, "refund_status" varchar not null default ('none'), "wallet_transaction_id" varchar, "airline_transaction_id" integer, "created_at" datetime, "updated_at" datetime, "product_type" varchar, "net_fare" numeric, "total_tax" numeric, "total_amount" numeric, "commission_percent" numeric, "commission_amount" numeric, "net_after_commission" numeric, "transaction_type" varchar, "product_details" text, "ledger_entry_id" integer, "used_master_agency_provider" tinyint(1) not null default '0', "master_commission_percent" numeric, foreign key("order_id") references orders("id") on delete cascade on update no action, foreign key("airline_transaction_id") references airline_transactions("id") on delete set null on update no action, foreign key("wallet_transaction_id") references "transactions"("uuid") on delete set null);

DROP TABLE IF EXISTS "journal_details";
CREATE TABLE "journal_details" ("journalDetailId" integer primary key autoincrement not null, "journalEntryId" integer not null, "ledgerUuid" varchar not null, "amount" varchar not null, "journalReferenceUuid" varchar);

DROP TABLE IF EXISTS "journal_entries";
CREATE TABLE "journal_entries" ("journalEntryId" integer primary key autoincrement not null, "transDate" datetime not null, "domainUuid" varchar not null, "subJournalUuid" varchar, "currency" varchar not null, "opening" integer not null, "clearing" integer not null default '0', "reviewed" integer not null, "locked" integer not null default '0', "description" varchar not null, "arguments" text not null, "language" varchar not null, "extra" text, "journalReferenceUuid" varchar, "createdBy" varchar, "updatedBy" varchar, "revision" datetime, "created_at" datetime, "updated_at" datetime);

DROP TABLE IF EXISTS "journal_references";
CREATE TABLE "journal_references" ("journalReferenceUuid" varchar not null, "domainUuid" varchar not null, "code" varchar not null, "extra" text, "revision" datetime, "created_at" datetime, "updated_at" datetime, primary key ("journalReferenceUuid"));

DROP TABLE IF EXISTS "ledger_accounts";
CREATE TABLE "ledger_accounts" ("ledgerUuid" varchar not null, "code" varchar not null, "taxCode" varchar, "parentUuid" varchar, "debit" tinyint(1) not null, "credit" tinyint(1) not null, "category" tinyint(1) not null, "closed" tinyint(1) not null, "extra" text, "flex" text, "revision" datetime, "created_at" datetime, "updated_at" datetime, primary key ("ledgerUuid"));

DROP TABLE IF EXISTS "ledger_balances";
CREATE TABLE "ledger_balances" ("id" integer primary key autoincrement not null, "ledgerUuid" varchar not null, "domainUuid" varchar not null, "currency" varchar not null, "balance" varchar not null, "created_at" datetime, "updated_at" datetime);

DROP TABLE IF EXISTS "ledger_currencies";
CREATE TABLE "ledger_currencies" ("code" varchar not null, "decimals" integer not null, "revision" datetime, "created_at" datetime, "updated_at" datetime, primary key ("code"));

DROP TABLE IF EXISTS "ledger_domains";
CREATE TABLE "ledger_domains" ("domainUuid" varchar not null, "code" varchar not null, "extra" text, "flex" text, "currencyDefault" varchar not null, "subJournals" tinyint(1) not null default '0', "revision" datetime, "created_at" datetime, "updated_at" datetime, primary key ("domainUuid"));

DROP TABLE IF EXISTS "ledger_names";
CREATE TABLE "ledger_names" ("id" integer primary key autoincrement not null, "ownerUuid" varchar not null, "language" varchar not null, "name" varchar not null, "created_at" datetime, "updated_at" datetime);

DROP TABLE IF EXISTS "ledger_reports";
CREATE TABLE "ledger_reports" ("id" integer primary key autoincrement not null, "name" varchar not null, "domainUuid" varchar not null, "currency" varchar not null, "fromDate" date, "toDate" date not null, "journalEntryId" integer not null, "reportData" text not null);

DROP TABLE IF EXISTS "sub_journals";
CREATE TABLE "sub_journals" ("subJournalUuid" varchar not null, "code" varchar not null, "extra" text, "revision" datetime, "created_at" datetime, "updated_at" datetime, primary key ("subJournalUuid"));

DROP TABLE IF EXISTS "agency_settings";
CREATE TABLE "agency_settings" ("id" integer primary key autoincrement not null, "can_use_own_airline_credentials" tinyint(1) not null default '1', "force_use_default_agency" tinyint(1) not null default '0', "default_agency_tenant_id" varchar, "master_commission_percent" numeric not null default '0', "created_at" datetime, "updated_at" datetime);

DROP TABLE IF EXISTS "tenant_insurance_providers";
CREATE TABLE "tenant_insurance_providers" ("id" integer primary key autoincrement not null, "provider_type" varchar not null default 'albaraka', "name" varchar not null default 'Al Baraka Insurance', "credentials" text, "is_active" tinyint(1) not null default '1', "commission_compulsory" numeric not null default '0', "commission_travel" numeric not null default '0', "commission_orange" numeric not null default '0', "created_at" datetime, "updated_at" datetime);

DROP TABLE IF EXISTS "tenant_insurance_provider_accounts";
CREATE TABLE "tenant_insurance_provider_accounts" ("id" integer primary key autoincrement not null, "tenant_insurance_provider_id" integer not null, "currency" varchar not null default 'LYD', "balance" numeric not null default '0', "created_at" datetime, "updated_at" datetime, foreign key("tenant_insurance_provider_id") references "tenant_insurance_providers"("id") on delete cascade);

DROP TABLE IF EXISTS "tenant_insurance_provider_transactions";
CREATE TABLE "tenant_insurance_provider_transactions" ("id" integer primary key autoincrement not null, "tenant_insurance_provider_account_id" integer not null, "type" varchar not null, "amount" numeric not null, "balance_after" numeric not null, "order_id" varchar, "order_item_id" integer, "external_reference" varchar, "description" text, "meta" text, "created_at" datetime, "updated_at" datetime, foreign key("tenant_insurance_provider_account_id") references "tenant_insurance_provider_accounts"("id") on delete cascade, foreign key("order_id") references "orders"("id") on delete set null, foreign key("order_item_id") references "order_items"("id") on delete set null);

DROP TABLE IF EXISTS "tenant_hotel_providers";
CREATE TABLE "tenant_hotel_providers" ("id" integer primary key autoincrement not null, "provider_type" varchar not null default '3t', "name" varchar not null default '3T Hotels', "credentials" text, "is_active" tinyint(1) not null default '1', "commission_hotel" numeric not null default '0', "created_at" datetime, "updated_at" datetime, "currency" varchar not null default 'LYD', "civility_codes" text);

DROP TABLE IF EXISTS "notification_logs";
CREATE TABLE "notification_logs" ("id" varchar not null, "channel" varchar not null, "event" varchar not null, "recipient" varchar not null, "message" text, "status" varchar not null, "error" varchar, "notifiable_type" varchar, "notifiable_id" varchar, "created_at" datetime, "updated_at" datetime, primary key ("id"));

DROP TABLE IF EXISTS "api_audit_logs";
CREATE TABLE "api_audit_logs" ("id" integer primary key autoincrement not null, "token_id" integer, "user_id" integer, "method" varchar not null, "path" varchar not null, "ip" varchar, "user_agent" varchar, "status_code" integer, "duration_ms" integer, "abilities" text, "created_at" datetime, "updated_at" datetime);

DROP TABLE IF EXISTS "tenant_esim_providers";
CREATE TABLE "tenant_esim_providers" ("id" integer primary key autoincrement not null, "provider_type" varchar not null default 'l2', "name" varchar not null default 'L2 Travel eSIM', "credentials" text, "is_active" tinyint(1) not null default '1', "commission_esim" numeric not null default '0', "created_at" datetime, "updated_at" datetime, "currency" varchar not null default 'USD');

INSERT INTO "migrations" ("id", "migration", "batch") VALUES
('1', '0001_01_01_000000_create_users_table', '1'),
('2', '2025_08_14_170933_add_two_factor_columns_to_users_table', '1'),
('3', '2026_02_25_112406_create_personal_access_tokens_table', '1'),
('4', '2026_02_25_122309_add_role_and_status_to_users_table', '1'),
('5', '2026_02_26_021242_create_tenant_providers_table', '1'),
('6', '2026_03_09_010605_create_customers_table', '1'),
('7', '2026_03_09_010606_create_bookings_table', '1'),
('8', '2026_03_09_010606_create_flight_segments_table', '1'),
('9', '2026_03_09_010606_create_passengers_table', '1'),
('10', '2026_03_31_080214_add_audit_fields_to_tenant_providers_table', '1'),
('11', '2026_03_31_080214_add_operational_fields_to_bookings_table', '1'),
('12', '2026_03_31_080214_create_tickets_table', '1'),
('13', '2026_04_21_000100_create_wallet_tables', '1'),
('14', '2026_04_21_000200_create_orders_table', '1'),
('15', '2026_04_21_000210_create_order_items_table', '1'),
('16', '2026_04_21_000220_create_order_status_log_table', '1'),
('17', '2026_04_21_000230_create_airline_accounts_table', '1'),
('18', '2026_04_21_000240_create_airline_transactions_table', '1'),
('19', '2026_04_21_000250_add_airline_transaction_fk_to_order_items_table', '1'),
('20', '2026_04_21_144413_add_passport_fields_to_passengers_table', '1'),
('21', '2026_04_21_183728_drop_booking_related_tables', '1'),
('22', '2026_04_23_182912_add_commission_rates_to_tenant_providers_table', '1'),
('23', '2026_04_23_232328_add_financial_commission_columns_to_tenant_providers_table', '1'),
('24', '2026_04_23_232329_align_financial_fields_on_orders_and_order_items_table', '1'),
('25', '2026_04_24_023710_ledger_create_tables_v2', '1'),
('26', '2026_04_26_130000_create_agency_settings_table', '1'),
('27', '2026_04_27_100000_add_master_agency_tracking_columns', '1'),
('28', '2026_04_27_190000_create_tenant_insurance_providers_table', '1'),
('29', '2026_05_03_120000_create_tenant_insurance_provider_accounts_table', '1'),
('30', '2026_05_03_120100_create_tenant_insurance_provider_transactions_table', '1'),
('31', '2026_05_06_140000_create_tenant_hotel_providers_table', '1'),
('32', '2026_05_10_110535_add_civility_codes_and_currency_to_tenant_hotel_providers', '1'),
('33', '2026_05_14_123644_create_notification_logs_table', '1'),
('34', '2026_05_14_134139_create_api_audit_logs_table', '1'),
('35', '2026_05_20_000001_create_tenant_esim_providers_table', '1'),
('36', '2026_05_20_133053_add_currency_to_tenant_esim_providers_table', '1');

INSERT INTO "sqlite_sequence" ("name", "seq") VALUES
('migrations', '36'),
('transactions', '0'),
('tickets', '0'),
('passengers', '0'),
('order_items', '1'),
('ledger_names', '55'),
('wallets', '1'),
('agency_settings', '1'),
('users', '1');

INSERT INTO "users" ("id", "name", "email", "email_verified_at", "password", "remember_token", "created_at", "updated_at", "two_factor_secret", "two_factor_recovery_codes", "two_factor_confirmed_at", "role", "is_active", "last_login_at", "last_activity_at") VALUES
('1', 'Reed Goodwin', 'oconner.clemmie@example.org', '2026-05-20 14:12:37', '$2y$04$eBQBmGuJddeP/c0Wq2NbpOjiGYOXXu7OmX2oyCC.84.w6vlAU6Iou', 'Eatb3Bwn0x', '2026-05-20 14:12:37', '2026-05-20 14:12:37', NULL, NULL, NULL, 'manager', '1', NULL, NULL);

INSERT INTO "wallets" ("id", "holder_type", "holder_id", "name", "slug", "uuid", "description", "meta", "balance", "decimal_places", "deleted_at", "created_at", "updated_at") VALUES
('1', 'App\Models\Tenant', 'metadata-default-merchant-tPhF', 'Operating Wallet', 'operating', '019e45bb-34d7-70d4-9667-493721be3e9d', NULL, '{"ledger_account":"1110","type":"operating"}', '0', '2', NULL, '2026-05-20 14:12:37', '2026-05-20 14:12:37');

INSERT INTO "orders" ("id", "owner_type", "owner_id", "number", "status", "issued_at", "due_at", "subtotal", "tax_total", "grand_total", "amount_paid", "amount_refunded", "currency", "payment_method", "payment_reference", "contact", "parent_id", "deleted_at", "created_at", "updated_at", "ledger_entry_id") VALUES
('019e45bb-34e4-72a2-af20-783f07fd59d5', 'App\Models\User', '1', 'ORD-DEFAULT-META', 'confirmed', NULL, NULL, '100', '0', '100', '100', '0', 'LYD', 'airline_token', NULL, NULL, NULL, NULL, '2026-05-20 14:12:37', '2026-05-20 14:12:37', NULL);

INSERT INTO "order_items" ("id", "order_id", "type", "product_subtype", "provider", "provider_reference", "ticket_number", "item_details", "price", "taxes", "total", "currency", "exchange_rate", "status", "net_commission", "agent_commission", "paid", "remaining", "refund_parent_id", "refund_status", "wallet_transaction_id", "airline_transaction_id", "created_at", "updated_at", "product_type", "net_fare", "total_tax", "total_amount", "commission_percent", "commission_amount", "net_after_commission", "transaction_type", "product_details", "ledger_entry_id", "used_master_agency_provider", "master_commission_percent") VALUES
('1', '019e45bb-34e4-72a2-af20-783f07fd59d5', 'flight_ticket', NULL, 'videcom', NULL, NULL, '{"airline_code":"YI","financial_source":"master_agency_supply","financial_provider_id":null,"financial_source_tenant_id":"metadata-default-agency-hpqr","provider_source_type":"default_agency","is_default_agency_deprecated":true,"provider_selector":"default_agency:metadata-default-agency-hpqr:1","source_agency_tenant_id":"metadata-default-agency-hpqr","source_provider_model":"App\\Models\\TenantProvider","source_provider_id":1,"default_agency_tenant_id":"metadata-default-agency-hpqr","master_commission_rate":0,"settlement_source":"default_agency_supply"}', '100', '[]', '100', 'LYD', '1', 'confirmed', '0', '0', '0', '0', NULL, 'none', NULL, NULL, '2026-05-20 14:12:37', '2026-05-20 14:12:37', NULL, '100', '0', '100', '0', '0', '100', NULL, '[]', NULL, '1', '0');

INSERT INTO "ledger_accounts" ("ledgerUuid", "code", "taxCode", "parentUuid", "debit", "credit", "category", "closed", "extra", "flex", "revision", "created_at", "updated_at") VALUES
('16140408-ff83-41c1-8792-ec8a4d336af0', '2110', NULL, '9098058a-eb85-4d20-bfb1-f7cb664d483e', '0', '1', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.181496', '2026-05-20 14:12:37.181496'),
('1a5b0dc7-284b-48f4-b13c-4c793fb999db', '3000', NULL, '6e371004-d9f5-42e5-b816-83cfaa202d5a', '0', '1', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.185588', '2026-05-20 14:12:37.185588'),
('24619c1b-efc2-44bf-97c6-2b97f44dc76c', '1310', NULL, 'eb97f772-061d-44aa-b077-3d141257a262', '1', '0', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.179016', '2026-05-20 14:12:37.179016'),
('2631ecc7-a035-441e-b23d-ecf039a45876', '1200', NULL, 'dc6d393d-9ad5-4d4c-8bab-2ad0c8288c1a', '1', '0', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.175829', '2026-05-20 14:12:37.175829'),
('2ab9f0b3-0930-4ffb-a6bf-d089a9ce7b01', '7200', NULL, '98c017db-4ae9-496e-910e-7bf2a154a198', '1', '0', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.198807', '2026-05-20 14:12:37.198807'),
('2bde1b3d-d083-47bd-9f7b-40f690981b38', '1230', NULL, '2631ecc7-a035-441e-b23d-ecf039a45876', '1', '0', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.177427', '2026-05-20 14:12:37.177427'),
('382f17d9-c7f4-4669-b75f-ad7424fbbeff', '6300', NULL, 'f919ff35-e507-4660-b6ac-c90fe7b66175', '1', '0', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.197233', '2026-05-20 14:12:37.197233'),
('38bec86e-da95-4fb6-a59d-5277dd0621ff', '2400', NULL, '76233035-0dd1-40f8-a5e7-171075170ec5', '0', '1', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.185047', '2026-05-20 14:12:37.185047'),
('3956c088-4d41-4900-9ee1-05682b93800d', '1120', NULL, '797d1f3f-57f9-42eb-a7aa-a20dd2d87834', '1', '0', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.175291', '2026-05-20 14:12:37.175291'),
('3fb03d31-78b4-4a9f-9c92-ffe4c21eaa5e', '5100', NULL, '9725a4e7-e43e-44e5-ac2d-6229d111edd4', '1', '0', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.192839', '2026-05-20 14:12:37.192839'),
('4588fd76-7459-4eac-b6be-a0b3c98f5cbf', '2120', NULL, '9098058a-eb85-4d20-bfb1-f7cb664d483e', '0', '1', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.182254', '2026-05-20 14:12:37.182254'),
('46f53ddc-a73e-46af-8cbe-85a5930b1384', '3300', NULL, '1a5b0dc7-284b-48f4-b13c-4c793fb999db', '0', '1', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.187331', '2026-05-20 14:12:37.187331'),
('47a7aef7-ef7c-46f4-87f7-ad95d8a94559', '4700', NULL, 'c9af4a5a-d882-4b8a-9acf-ea0b61024dfe', '0', '1', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.191762', '2026-05-20 14:12:37.191762'),
('51dc7302-2e0b-4a58-a987-64ac831e9184', '2140', NULL, '9098058a-eb85-4d20-bfb1-f7cb664d483e', '0', '1', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.183405', '2026-05-20 14:12:37.183405'),
('64828c69-8262-41c0-86fa-63417d370831', '6200', NULL, 'f919ff35-e507-4660-b6ac-c90fe7b66175', '1', '0', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.196688', '2026-05-20 14:12:37.196688'),
('6a38daf3-624d-4d55-bd33-b3eeea999359', '1240', NULL, '2631ecc7-a035-441e-b23d-ecf039a45876', '1', '0', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.177954', '2026-05-20 14:12:37.177954'),
('6c352b3e-724a-431e-96e1-528f088156aa', '4300', NULL, 'c9af4a5a-d882-4b8a-9acf-ea0b61024dfe', '0', '1', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.189612', '2026-05-20 14:12:37.189612'),
('6e371004-d9f5-42e5-b816-83cfaa202d5a', '', NULL, NULL, '0', '0', '1', '0', NULL, '{"rules":{"account":{"postToCategory":false},"appAttributes":[],"batch":{"allowReports":true,"limit":0},"domain":{"default":"MAIN"},"entry":{"reviewed":false},"language":{"default":"en"},"openDate":"2026-05-20 14:12:37.000000","pageSize":25,"sections":[]},"salt":"b1c8bc6bc88eabd3827b80baaae63d3a"}', NULL, '2026-05-20 14:12:37.172804', '2026-05-20 14:12:37.172804'),
('76233035-0dd1-40f8-a5e7-171075170ec5', '2000', NULL, '6e371004-d9f5-42e5-b816-83cfaa202d5a', '0', '1', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.180397', '2026-05-20 14:12:37.180397'),
('797d1f3f-57f9-42eb-a7aa-a20dd2d87834', '1100', NULL, 'dc6d393d-9ad5-4d4c-8bab-2ad0c8288c1a', '1', '0', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.174120', '2026-05-20 14:12:37.174120'),
('822266f7-7a48-4fbc-8438-eba139a0ceee', '4600', NULL, 'c9af4a5a-d882-4b8a-9acf-ea0b61024dfe', '0', '1', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.191226', '2026-05-20 14:12:37.191226'),
('869c6c03-33e5-417e-a8f8-78ac421d753f', '3100', NULL, '1a5b0dc7-284b-48f4-b13c-4c793fb999db', '0', '1', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.186133', '2026-05-20 14:12:37.186133'),
('896dde98-1412-4548-8e0d-070a52a37e7b', '5200', NULL, '9725a4e7-e43e-44e5-ac2d-6229d111edd4', '1', '0', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.193373', '2026-05-20 14:12:37.193373'),
('9098058a-eb85-4d20-bfb1-f7cb664d483e', '2100', NULL, '76233035-0dd1-40f8-a5e7-171075170ec5', '0', '1', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.180953', '2026-05-20 14:12:37.180953'),
('92c4b05f-a22a-4895-a2b2-b2fe870ff976', '2130', NULL, '9098058a-eb85-4d20-bfb1-f7cb664d483e', '0', '1', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.182851', '2026-05-20 14:12:37.182851'),
('9306c538-3641-4a4d-bf01-52aeb684b6d3', '4500', NULL, 'c9af4a5a-d882-4b8a-9acf-ea0b61024dfe', '0', '1', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.190694', '2026-05-20 14:12:37.190694'),
('952efc3b-6bbc-4a97-bef2-025059fd8a1d', '1320', NULL, 'eb97f772-061d-44aa-b077-3d141257a262', '1', '0', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.179737', '2026-05-20 14:12:37.179737'),
('95a1d934-8291-4b40-8a4f-7de972e6ea55', '4200', NULL, 'c9af4a5a-d882-4b8a-9acf-ea0b61024dfe', '0', '1', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.189062', '2026-05-20 14:12:37.189062'),
('9725a4e7-e43e-44e5-ac2d-6229d111edd4', '5000', NULL, '6e371004-d9f5-42e5-b816-83cfaa202d5a', '1', '0', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.192300', '2026-05-20 14:12:37.192300'),
('98c017db-4ae9-496e-910e-7bf2a154a198', '7000', NULL, '6e371004-d9f5-42e5-b816-83cfaa202d5a', '1', '0', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.197762', '2026-05-20 14:12:37.197762'),
('a0500c46-eebe-442b-bd62-b3f1f59a4ee9', '6100', NULL, 'f919ff35-e507-4660-b6ac-c90fe7b66175', '1', '0', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.196113', '2026-05-20 14:12:37.196113'),
('ace13340-8b77-4f4d-80f5-1b44990a4da8', '2300', NULL, '76233035-0dd1-40f8-a5e7-171075170ec5', '0', '1', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.184492', '2026-05-20 14:12:37.184492'),
('b3d5b8a6-ac34-499f-83e6-0f89396e71af', '7400', NULL, '98c017db-4ae9-496e-910e-7bf2a154a198', '1', '0', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.199330', '2026-05-20 14:12:37.199330'),
('bc4bcfca-6756-418f-9b8a-c4ed72cde1a6', '5300', NULL, '9725a4e7-e43e-44e5-ac2d-6229d111edd4', '1', '0', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.193918', '2026-05-20 14:12:37.193918'),
('c04ef6ad-103a-4cbf-8c07-8ee6cc3df4f0', '4400', NULL, 'c9af4a5a-d882-4b8a-9acf-ea0b61024dfe', '0', '1', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.190157', '2026-05-20 14:12:37.190157'),
('c256b76c-756e-4950-b116-5b52bd61bbee', '3200', NULL, '1a5b0dc7-284b-48f4-b13c-4c793fb999db', '0', '1', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.186667', '2026-05-20 14:12:37.186667'),
('c387f47f-14dd-40d1-8eac-8eeb8eb6a9a2', '1210', NULL, '2631ecc7-a035-441e-b23d-ecf039a45876', '1', '0', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.176363', '2026-05-20 14:12:37.176363'),
('c469615c-58c9-4e65-94be-a083bdc9aa4b', '7100', NULL, '98c017db-4ae9-496e-910e-7bf2a154a198', '1', '0', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.198286', '2026-05-20 14:12:37.198286'),
('c9af4a5a-d882-4b8a-9acf-ea0b61024dfe', '4000', NULL, '6e371004-d9f5-42e5-b816-83cfaa202d5a', '0', '1', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.187895', '2026-05-20 14:12:37.187895'),
('d363168f-acd7-47dd-8504-e534db719ae0', '1110', NULL, '797d1f3f-57f9-42eb-a7aa-a20dd2d87834', '1', '0', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.174734', '2026-05-20 14:12:37.174734'),
('dafb410f-63a4-41d1-96f4-6ef60aa58593', '4100', NULL, 'c9af4a5a-d882-4b8a-9acf-ea0b61024dfe', '0', '1', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.188515', '2026-05-20 14:12:37.188515'),
('dc6d393d-9ad5-4d4c-8bab-2ad0c8288c1a', '1000', NULL, '6e371004-d9f5-42e5-b816-83cfaa202d5a', '1', '0', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.173380', '2026-05-20 14:12:37.173380'),
('ddf051f2-2dca-4a9b-bfd6-93ca1e7c5b03', '2200', NULL, '76233035-0dd1-40f8-a5e7-171075170ec5', '0', '1', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.183952', '2026-05-20 14:12:37.183952'),
('e84f79eb-f703-40ec-a3be-51d60e2b58a3', '5500', NULL, '9725a4e7-e43e-44e5-ac2d-6229d111edd4', '1', '0', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.194983', '2026-05-20 14:12:37.194983'),
('e8e1650a-8582-4d19-b345-36e7a3055df3', '1220', NULL, '2631ecc7-a035-441e-b23d-ecf039a45876', '1', '0', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.176895', '2026-05-20 14:12:37.176895'),
('eb97f772-061d-44aa-b077-3d141257a262', '1300', NULL, 'dc6d393d-9ad5-4d4c-8bab-2ad0c8288c1a', '1', '0', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.178484', '2026-05-20 14:12:37.178484'),
('f919ff35-e507-4660-b6ac-c90fe7b66175', '6000', NULL, '6e371004-d9f5-42e5-b816-83cfaa202d5a', '1', '0', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.195512', '2026-05-20 14:12:37.195512'),
('fac977f2-d680-41fd-893a-8f0717cfc8cd', '5400', NULL, '9725a4e7-e43e-44e5-ac2d-6229d111edd4', '1', '0', '0', '0', NULL, NULL, NULL, '2026-05-20 14:12:37.194447', '2026-05-20 14:12:37.194447');

INSERT INTO "ledger_currencies" ("code", "decimals", "revision", "created_at", "updated_at") VALUES
('LYD', '3', NULL, '2026-05-20 14:12:37.171087', '2026-05-20 14:12:37.171087');

INSERT INTO "ledger_domains" ("domainUuid", "code", "extra", "flex", "currencyDefault", "subJournals", "revision", "created_at", "updated_at") VALUES
('50ab9444-d37f-42d8-9ddc-cf9166f0dfc9', 'MAIN', NULL, NULL, 'LYD', '0', NULL, '2026-05-20 14:12:37.171945', '2026-05-20 14:12:37.171945');

INSERT INTO "ledger_names" ("id", "ownerUuid", "language", "name", "created_at", "updated_at") VALUES
('1', '50ab9444-d37f-42d8-9ddc-cf9166f0dfc9', 'en', 'Main General Ledger', '2026-05-20 14:12:37.172332', '2026-05-20 14:12:37.172332'),
('2', '6e371004-d9f5-42e5-b816-83cfaa202d5a', 'en', 'Booknow Agency Ledger', '2026-05-20 14:12:37.173190', '2026-05-20 14:12:37.173190'),
('3', 'dc6d393d-9ad5-4d4c-8bab-2ad0c8288c1a', 'en', 'Assets', '2026-05-20 14:12:37.173859', '2026-05-20 14:12:37.173859'),
('4', '797d1f3f-57f9-42eb-a7aa-a20dd2d87834', 'en', 'Cash & Bank', '2026-05-20 14:12:37.174533', '2026-05-20 14:12:37.174533'),
('5', 'd363168f-acd7-47dd-8504-e534db719ae0', 'en', 'Agency Operating Wallet', '2026-05-20 14:12:37.175105', '2026-05-20 14:12:37.175105'),
('6', '3956c088-4d41-4900-9ee1-05682b93800d', 'en', 'Merchant Wallet', '2026-05-20 14:12:37.175647', '2026-05-20 14:12:37.175647'),
('7', '2631ecc7-a035-441e-b23d-ecf039a45876', 'en', 'Provider Prepaid Balances', '2026-05-20 14:12:37.176183', '2026-05-20 14:12:37.176183'),
('8', 'c387f47f-14dd-40d1-8eac-8eeb8eb6a9a2', 'en', 'Airline Provider Wallet', '2026-05-20 14:12:37.176714', '2026-05-20 14:12:37.176714'),
('9', 'e8e1650a-8582-4d19-b345-36e7a3055df3', 'en', 'Hotel Provider Wallet', '2026-05-20 14:12:37.177245', '2026-05-20 14:12:37.177245'),
('10', '2bde1b3d-d083-47bd-9f7b-40f690981b38', 'en', 'Insurance Provider Wallet', '2026-05-20 14:12:37.177776', '2026-05-20 14:12:37.177776'),
('11', '6a38daf3-624d-4d55-bd33-b3eeea999359', 'en', 'eSIM Provider Wallet', '2026-05-20 14:12:37.178304', '2026-05-20 14:12:37.178304'),
('12', 'eb97f772-061d-44aa-b077-3d141257a262', 'en', 'Receivables', '2026-05-20 14:12:37.178835', '2026-05-20 14:12:37.178835'),
('13', '24619c1b-efc2-44bf-97c6-2b97f44dc76c', 'en', 'Customer Receivable', '2026-05-20 14:12:37.179468', '2026-05-20 14:12:37.179468'),
('14', '952efc3b-6bbc-4a97-bef2-025059fd8a1d', 'en', 'Merchant Receivable', '2026-05-20 14:12:37.180201', '2026-05-20 14:12:37.180201'),
('15', '76233035-0dd1-40f8-a5e7-171075170ec5', 'en', 'Liabilities', '2026-05-20 14:12:37.180767', '2026-05-20 14:12:37.180767'),
('16', '9098058a-eb85-4d20-bfb1-f7cb664d483e', 'en', 'Provider Payables', '2026-05-20 14:12:37.181312', '2026-05-20 14:12:37.181312'),
('17', '16140408-ff83-41c1-8792-ec8a4d336af0', 'en', 'Airline Provider Payable', '2026-05-20 14:12:37.181980', '2026-05-20 14:12:37.181980'),
('18', '4588fd76-7459-4eac-b6be-a0b3c98f5cbf', 'en', 'Hotel Provider Payable', '2026-05-20 14:12:37.182656', '2026-05-20 14:12:37.182656'),
('19', '92c4b05f-a22a-4895-a2b2-b2fe870ff976', 'en', 'Insurance Provider Payable', '2026-05-20 14:12:37.183220', '2026-05-20 14:12:37.183220'),
('20', '51dc7302-2e0b-4a58-a987-64ac831e9184', 'en', 'eSIM Provider Payable', '2026-05-20 14:12:37.183769', '2026-05-20 14:12:37.183769'),
('21', 'ddf051f2-2dca-4a9b-bfd6-93ca1e7c5b03', 'en', 'Network Agency Payable', '2026-05-20 14:12:37.184308', '2026-05-20 14:12:37.184308'),
('22', 'ace13340-8b77-4f4d-80f5-1b44990a4da8', 'en', 'Customer Deposits', '2026-05-20 14:12:37.184858', '2026-05-20 14:12:37.184858'),
('23', '38bec86e-da95-4fb6-a59d-5277dd0621ff', 'en', 'VAT Payable', '2026-05-20 14:12:37.185408', '2026-05-20 14:12:37.185408'),
('24', '1a5b0dc7-284b-48f4-b13c-4c793fb999db', 'en', 'Equity', '2026-05-20 14:12:37.185948', '2026-05-20 14:12:37.185948'),
('25', '869c6c03-33e5-417e-a8f8-78ac421d753f', 'en', 'Agency Capital', '2026-05-20 14:12:37.186487', '2026-05-20 14:12:37.186487'),
('26', 'c256b76c-756e-4950-b116-5b52bd61bbee', 'en', 'Retained Earnings', '2026-05-20 14:12:37.187124', '2026-05-20 14:12:37.187124'),
('27', '46f53ddc-a73e-46af-8cbe-85a5930b1384', 'en', 'Current Year Profit/Loss', '2026-05-20 14:12:37.187718', '2026-05-20 14:12:37.187718'),
('28', 'c9af4a5a-d882-4b8a-9acf-ea0b61024dfe', 'en', 'Revenue', '2026-05-20 14:12:37.188314', '2026-05-20 14:12:37.188314'),
('29', 'dafb410f-63a4-41d1-96f4-6ef60aa58593', 'en', 'Airline Ticket Sales', '2026-05-20 14:12:37.188880', '2026-05-20 14:12:37.188880'),
('30', '95a1d934-8291-4b40-8a4f-7de972e6ea55', 'en', 'Hotel Booking Sales', '2026-05-20 14:12:37.189417', '2026-05-20 14:12:37.189417'),
('31', '6c352b3e-724a-431e-96e1-528f088156aa', 'en', 'Insurance Premium Sales', '2026-05-20 14:12:37.189976', '2026-05-20 14:12:37.189976'),
('32', 'c04ef6ad-103a-4cbf-8c07-8ee6cc3df4f0', 'en', 'eSIM Sales', '2026-05-20 14:12:37.190513', '2026-05-20 14:12:37.190513'),
('33', '9306c538-3641-4a4d-bf01-52aeb684b6d3', 'en', 'Service Fees & Markup', '2026-05-20 14:12:37.191048', '2026-05-20 14:12:37.191048'),
('34', '822266f7-7a48-4fbc-8438-eba139a0ceee', 'en', 'Network Commission Income', '2026-05-20 14:12:37.191574', '2026-05-20 14:12:37.191574'),
('35', '47a7aef7-ef7c-46f4-87f7-ad95d8a94559', 'en', 'Cancellation Fee Income', '2026-05-20 14:12:37.192117', '2026-05-20 14:12:37.192117'),
('36', '9725a4e7-e43e-44e5-ac2d-6229d111edd4', 'en', 'Cost of Sales', '2026-05-20 14:12:37.192656', '2026-05-20 14:12:37.192656'),
('37', '3fb03d31-78b4-4a9f-9c92-ffe4c21eaa5e', 'en', 'Airline Provider Cost', '2026-05-20 14:12:37.193195', '2026-05-20 14:12:37.193195'),
('38', '896dde98-1412-4548-8e0d-070a52a37e7b', 'en', 'Hotel Provider Cost', '2026-05-20 14:12:37.193729', '2026-05-20 14:12:37.193729'),
('39', 'bc4bcfca-6756-418f-9b8a-c4ed72cde1a6', 'en', 'Insurance Provider Cost', '2026-05-20 14:12:37.194268', '2026-05-20 14:12:37.194268'),
('40', 'fac977f2-d680-41fd-893a-8f0717cfc8cd', 'en', 'eSIM Provider Cost', '2026-05-20 14:12:37.194801', '2026-05-20 14:12:37.194801'),
('41', 'e84f79eb-f703-40ec-a3be-51d60e2b58a3', 'en', 'Merchant Wholesale Cost', '2026-05-20 14:12:37.195332', '2026-05-20 14:12:37.195332'),
('42', 'f919ff35-e507-4660-b6ac-c90fe7b66175', 'en', 'Operating Expenses', '2026-05-20 14:12:37.195867', '2026-05-20 14:12:37.195867'),
('43', 'a0500c46-eebe-442b-bd62-b3f1f59a4ee9', 'en', 'Refunds & Voids', '2026-05-20 14:12:37.196486', '2026-05-20 14:12:37.196486'),
('44', '64828c69-8262-41c0-86fa-63417d370831', 'en', 'Settlement Adjustments', '2026-05-20 14:12:37.197051', '2026-05-20 14:12:37.197051'),
('45', '382f17d9-c7f4-4669-b75f-ad7424fbbeff', 'en', 'Exchange Gain/Loss', '2026-05-20 14:12:37.197588', '2026-05-20 14:12:37.197588'),
('46', '98c017db-4ae9-496e-910e-7bf2a154a198', 'en', 'Settlement Clearing', '2026-05-20 14:12:37.198108', '2026-05-20 14:12:37.198108'),
('47', 'c469615c-58c9-4e65-94be-a083bdc9aa4b', 'en', 'Network Agency Settlement', '2026-05-20 14:12:37.198629', '2026-05-20 14:12:37.198629'),
('48', '2ab9f0b3-0930-4ffb-a6bf-d089a9ce7b01', 'en', 'Merchant Settlement Clearing', '2026-05-20 14:12:37.199152', '2026-05-20 14:12:37.199152'),
('49', 'b3d5b8a6-ac34-499f-83e6-0f89396e71af', 'en', 'Provider Reconciliation', '2026-05-20 14:12:37.199672', '2026-05-20 14:12:37.199672'),
('50', '95b47746-5570-4d72-a8d6-83a6e6d694c6', 'en', 'General', '2026-05-20 14:12:37.201810', '2026-05-20 14:12:37.201810'),
('51', '4ff1fbdb-eebf-4095-aa01-c128e5248d50', 'en', 'Airline', '2026-05-20 14:12:37.202988', '2026-05-20 14:12:37.202988'),
('52', 'b5dce2a4-73e7-45d0-9185-5af05b80d609', 'en', 'Hotel', '2026-05-20 14:12:37.204105', '2026-05-20 14:12:37.204105'),
('53', '9b1ea9d2-6fb0-470e-bf25-beb019116447', 'en', 'Insurance', '2026-05-20 14:12:37.205165', '2026-05-20 14:12:37.205165'),
('54', 'a2b9437e-794a-4793-8d6a-41c1bea59784', 'en', 'eSIM', '2026-05-20 14:12:37.206174', '2026-05-20 14:12:37.206174'),
('55', '2a4478eb-11cd-440d-963c-ffb1234564a7', 'en', 'Settlement', '2026-05-20 14:12:37.207198', '2026-05-20 14:12:37.207198');

INSERT INTO "sub_journals" ("subJournalUuid", "code", "extra", "revision", "created_at", "updated_at") VALUES
('2a4478eb-11cd-440d-963c-ffb1234564a7', 'STL', NULL, NULL, '2026-05-20 14:12:37.206833', '2026-05-20 14:12:37.206833'),
('4ff1fbdb-eebf-4095-aa01-c128e5248d50', 'AIR', NULL, NULL, '2026-05-20 14:12:37.202589', '2026-05-20 14:12:37.202589'),
('95b47746-5570-4d72-a8d6-83a6e6d694c6', 'GEN', NULL, NULL, '2026-05-20 14:12:37.201284', '2026-05-20 14:12:37.201284'),
('9b1ea9d2-6fb0-470e-bf25-beb019116447', 'INS', NULL, NULL, '2026-05-20 14:12:37.204784', '2026-05-20 14:12:37.204784'),
('a2b9437e-794a-4793-8d6a-41c1bea59784', 'ESM', NULL, NULL, '2026-05-20 14:12:37.205796', '2026-05-20 14:12:37.205796'),
('b5dce2a4-73e7-45d0-9185-5af05b80d609', 'HTL', NULL, NULL, '2026-05-20 14:12:37.203676', '2026-05-20 14:12:37.203676');

INSERT INTO "agency_settings" ("id", "can_use_own_airline_credentials", "force_use_default_agency", "default_agency_tenant_id", "master_commission_percent", "created_at", "updated_at") VALUES
('1', '0', '1', 'metadata-default-agency-hpqr', '0', '2026-05-20 14:12:37', '2026-05-20 14:12:37');

