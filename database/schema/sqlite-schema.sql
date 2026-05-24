CREATE TABLE IF NOT EXISTS "migrations"(
  "id" integer primary key autoincrement not null,
  "migration" varchar not null,
  "batch" integer not null
);
CREATE TABLE IF NOT EXISTS "users"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "email" varchar not null,
  "email_verified_at" datetime,
  "password" varchar not null,
  "remember_token" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "two_factor_secret" text,
  "two_factor_recovery_codes" text,
  "two_factor_confirmed_at" datetime
);
CREATE UNIQUE INDEX "users_email_unique" on "users"("email");
CREATE TABLE IF NOT EXISTS "password_reset_tokens"(
  "email" varchar not null,
  "token" varchar not null,
  "created_at" datetime,
  primary key("email")
);
CREATE TABLE IF NOT EXISTS "sessions"(
  "id" varchar not null,
  "user_id" integer,
  "ip_address" varchar,
  "user_agent" text,
  "payload" text not null,
  "last_activity" integer not null,
  primary key("id")
);
CREATE INDEX "sessions_user_id_index" on "sessions"("user_id");
CREATE INDEX "sessions_last_activity_index" on "sessions"("last_activity");
CREATE TABLE IF NOT EXISTS "cache"(
  "key" varchar not null,
  "value" text not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE INDEX "cache_expiration_index" on "cache"("expiration");
CREATE TABLE IF NOT EXISTS "cache_locks"(
  "key" varchar not null,
  "owner" varchar not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE INDEX "cache_locks_expiration_index" on "cache_locks"("expiration");
CREATE TABLE IF NOT EXISTS "jobs"(
  "id" integer primary key autoincrement not null,
  "queue" varchar not null,
  "payload" text not null,
  "attempts" integer not null,
  "reserved_at" integer,
  "available_at" integer not null,
  "created_at" integer not null
);
CREATE INDEX "jobs_queue_index" on "jobs"("queue");
CREATE TABLE IF NOT EXISTS "job_batches"(
  "id" varchar not null,
  "name" varchar not null,
  "total_jobs" integer not null,
  "pending_jobs" integer not null,
  "failed_jobs" integer not null,
  "failed_job_ids" text not null,
  "options" text,
  "cancelled_at" integer,
  "created_at" integer not null,
  "finished_at" integer,
  primary key("id")
);
CREATE TABLE IF NOT EXISTS "failed_jobs"(
  "id" integer primary key autoincrement not null,
  "uuid" varchar not null,
  "connection" text not null,
  "queue" text not null,
  "payload" text not null,
  "exception" text not null,
  "failed_at" datetime not null default CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX "failed_jobs_uuid_unique" on "failed_jobs"("uuid");
CREATE TABLE IF NOT EXISTS "tenants"(
  "id" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  "data" text,
  "company_name" varchar,
  "owner_name" varchar,
  "owner_email" varchar,
  "owner_phone" varchar,
  "status" varchar not null default 'active',
  "subscription_status" varchar not null default 'trial',
  "subscription_plan" varchar,
  "settings" text,
  "last_activity_at" datetime,
  "is_default_agency" tinyint(1) not null default '0',
  "master_commission_rate" numeric not null default '0',
  "agency_number" varchar,
  "path" varchar,
  "commercial_register_path" varchar,
  "passport_path" varchar,
  "type" varchar not null default 'direct',
  primary key("id")
);
CREATE TABLE IF NOT EXISTS "domains"(
  "id" integer primary key autoincrement not null,
  "domain" varchar not null,
  "tenant_id" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("tenant_id") references "tenants"("id") on delete cascade on update cascade
);
CREATE UNIQUE INDEX "domains_domain_unique" on "domains"("domain");
CREATE TABLE IF NOT EXISTS "telescope_entries"(
  "sequence" integer primary key autoincrement not null,
  "uuid" varchar not null,
  "batch_id" varchar not null,
  "family_hash" varchar,
  "should_display_on_index" tinyint(1) not null default '1',
  "type" varchar not null,
  "content" text not null,
  "created_at" datetime
);
CREATE UNIQUE INDEX "telescope_entries_uuid_unique" on "telescope_entries"(
  "uuid"
);
CREATE INDEX "telescope_entries_batch_id_index" on "telescope_entries"(
  "batch_id"
);
CREATE INDEX "telescope_entries_family_hash_index" on "telescope_entries"(
  "family_hash"
);
CREATE INDEX "telescope_entries_created_at_index" on "telescope_entries"(
  "created_at"
);
CREATE INDEX "telescope_entries_type_should_display_on_index_index" on "telescope_entries"(
  "type",
  "should_display_on_index"
);
CREATE TABLE IF NOT EXISTS "telescope_entries_tags"(
  "entry_uuid" varchar not null,
  "tag" varchar not null,
  foreign key("entry_uuid") references "telescope_entries"("uuid") on delete cascade,
  primary key("entry_uuid", "tag")
);
CREATE INDEX "telescope_entries_tags_tag_index" on "telescope_entries_tags"(
  "tag"
);
CREATE TABLE IF NOT EXISTS "telescope_monitoring"(
  "tag" varchar not null,
  primary key("tag")
);
CREATE TABLE IF NOT EXISTS "airports"(
  "id" integer primary key autoincrement not null,
  "name" text not null,
  "city" text not null,
  "country" text not null,
  "iata_code" varchar,
  "icao_code" varchar,
  "latitude" numeric,
  "longitude" numeric,
  "elevation_ft" integer,
  "type" varchar,
  "data" text,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "airports_iata_code_index" on "airports"("iata_code");
CREATE INDEX "airports_icao_code_index" on "airports"("icao_code");
CREATE TABLE IF NOT EXISTS "landlord_users"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "email" varchar not null,
  "email_verified_at" datetime,
  "password" varchar not null,
  "remember_token" varchar,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "landlord_users_email_unique" on "landlord_users"("email");
CREATE TABLE IF NOT EXISTS "transfers"(
  "id" integer primary key autoincrement not null,
  "from_id" integer not null,
  "to_id" integer not null,
  "status" varchar check("status" in('exchange', 'transfer', 'paid', 'refund', 'gift')) not null default 'transfer',
  "status_last" varchar check("status_last" in('exchange', 'transfer', 'paid', 'refund', 'gift')),
  "deposit_id" integer not null,
  "withdraw_id" integer not null,
  "discount" numeric not null default '0',
  "fee" numeric not null default '0',
  "uuid" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  "extra" text,
  foreign key("deposit_id") references "transactions"("id") on delete cascade,
  foreign key("withdraw_id") references "transactions"("id") on delete cascade
);
CREATE UNIQUE INDEX "transfers_uuid_unique" on "transfers"("uuid");
CREATE TABLE IF NOT EXISTS "wallets"(
  "id" integer primary key autoincrement not null,
  "holder_type" varchar not null,
  "holder_id" integer not null,
  "name" varchar not null,
  "slug" varchar not null,
  "uuid" varchar not null,
  "description" varchar,
  "meta" text,
  "balance" numeric not null default '0',
  "decimal_places" integer not null default '2',
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime
);
CREATE INDEX "wallets_holder_type_holder_id_index" on "wallets"(
  "holder_type",
  "holder_id"
);
CREATE UNIQUE INDEX "wallets_holder_type_holder_id_slug_unique" on "wallets"(
  "holder_type",
  "holder_id",
  "slug"
);
CREATE INDEX "wallets_slug_index" on "wallets"("slug");
CREATE UNIQUE INDEX "wallets_uuid_unique" on "wallets"("uuid");
CREATE TABLE IF NOT EXISTS "transactions"(
  "id" integer primary key autoincrement not null,
  "payable_type" varchar not null,
  "payable_id" integer not null,
  "wallet_id" integer not null,
  "type" varchar not null,
  "amount" numeric not null,
  "confirmed" tinyint(1) not null,
  "meta" text,
  "uuid" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  foreign key("wallet_id") references "wallets"("id") on delete cascade
);
CREATE INDEX "payable_confirmed_ind" on "transactions"(
  "payable_type",
  "payable_id",
  "confirmed"
);
CREATE INDEX "payable_type_confirmed_ind" on "transactions"(
  "payable_type",
  "payable_id",
  "type",
  "confirmed"
);
CREATE INDEX "payable_type_ind" on "transactions"(
  "payable_type",
  "payable_id",
  "type"
);
CREATE INDEX "payable_type_payable_id_ind" on "transactions"(
  "payable_type",
  "payable_id"
);
CREATE INDEX "transactions_payable_type_payable_id_index" on "transactions"(
  "payable_type",
  "payable_id"
);
CREATE INDEX "transactions_type_index" on "transactions"("type");
CREATE UNIQUE INDEX "transactions_uuid_unique" on "transactions"("uuid");
CREATE INDEX "transfers_from_id_index" on "transfers"("from_id");
CREATE INDEX "transfers_to_id_index" on "transfers"("to_id");
CREATE TABLE IF NOT EXISTS "route_availability_cache"(
  "id" integer primary key autoincrement not null,
  "airline_code" varchar not null,
  "origin" varchar not null,
  "destination" varchar not null,
  "has_flights" tinyint(1) not null default '1',
  "last_seen_at" datetime,
  "last_checked_at" datetime,
  "consecutive_empty" integer not null default '0',
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "route_availability_cache_unique" on "route_availability_cache"(
  "airline_code",
  "origin",
  "destination"
);
CREATE INDEX "route_availability_cache_route_idx" on "route_availability_cache"(
  "origin",
  "destination"
);
CREATE INDEX "route_availability_cache_airline_route_idx" on "route_availability_cache"(
  "airline_code",
  "origin",
  "destination"
);
CREATE TABLE IF NOT EXISTS "flight_schedule_cache"(
  "id" integer primary key autoincrement not null,
  "airline_code" varchar not null,
  "origin" varchar not null,
  "destination" varchar not null,
  "flight_date" date not null,
  "booking_class" varchar,
  "lowest_price" numeric not null,
  "currency" varchar not null,
  "expires_at" datetime not null,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "flight_schedule_cache_unique" on "flight_schedule_cache"(
  "airline_code",
  "origin",
  "destination",
  "flight_date",
  "booking_class"
);
CREATE INDEX "flight_schedule_cache_route_date_idx" on "flight_schedule_cache"(
  "origin",
  "destination",
  "flight_date"
);
CREATE INDEX "flight_schedule_cache_airline_route_idx" on "flight_schedule_cache"(
  "airline_code",
  "origin",
  "destination"
);
CREATE INDEX "flight_schedule_cache_expires_idx" on "flight_schedule_cache"(
  "expires_at"
);
CREATE TABLE IF NOT EXISTS "landlord_settings"(
  "id" integer primary key autoincrement not null,
  "key" varchar not null,
  "value" text,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "landlord_settings_key_unique" on "landlord_settings"(
  "key"
);
CREATE TABLE IF NOT EXISTS "airport_countries"(
  "id" integer primary key autoincrement not null,
  "country_code" varchar not null,
  "country_name" varchar not null,
  "iso3_code" varchar,
  "is_active" tinyint(1) not null default '1',
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "airport_countries_country_name_index" on "airport_countries"(
  "country_name"
);
CREATE UNIQUE INDEX "airport_countries_country_code_unique" on "airport_countries"(
  "country_code"
);
CREATE UNIQUE INDEX "airport_countries_iso3_code_unique" on "airport_countries"(
  "iso3_code"
);
CREATE TABLE IF NOT EXISTS "agency_wallet_transactions"(
  "id" integer primary key autoincrement not null,
  "tenant_id" varchar not null,
  "default_agency_tenant_id" varchar,
  "type" varchar not null,
  "currency" varchar not null,
  "amount" numeric not null,
  "balance_after" numeric not null,
  "reference_type" varchar,
  "reference_id" varchar,
  "description" text,
  "admin_id" integer,
  "created_at" datetime,
  "updated_at" datetime,
  "settlement_id" integer,
  "settled_at" datetime,
  foreign key("tenant_id") references "tenants"("id") on delete cascade,
  foreign key("default_agency_tenant_id") references "tenants"("id") on delete set null
);
CREATE INDEX "agency_wallet_transactions_tenant_id_currency_index" on "agency_wallet_transactions"(
  "tenant_id",
  "currency"
);
CREATE INDEX "agency_wallet_transactions_tenant_id_type_index" on "agency_wallet_transactions"(
  "tenant_id",
  "type"
);
CREATE TABLE IF NOT EXISTS "default_agency_settings"(
  "id" integer primary key autoincrement not null,
  "default_agency_tenant_id" varchar not null,
  "master_commission_percent" numeric not null default '0',
  "allowed_airline_codes" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("default_agency_tenant_id") references "tenants"("id") on delete cascade
);
CREATE TABLE IF NOT EXISTS "agency_settlements"(
  "id" integer primary key autoincrement not null,
  "buyer_tenant_id" varchar not null,
  "default_agency_tenant_id" varchar not null,
  "currency" varchar not null,
  "total_commission" numeric not null,
  "transaction_count" integer not null,
  "period_started_at" datetime,
  "period_ended_at" datetime,
  "status" varchar not null default 'recorded',
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("buyer_tenant_id") references "tenants"("id") on delete cascade,
  foreign key("default_agency_tenant_id") references "tenants"("id") on delete cascade
);
CREATE INDEX "agency_settlements_buyer_tenant_id_default_agency_tenant_id_index" on "agency_settlements"(
  "buyer_tenant_id",
  "default_agency_tenant_id"
);
CREATE INDEX "agency_settlements_status_currency_index" on "agency_settlements"(
  "status",
  "currency"
);
CREATE INDEX "agency_wallet_transactions_type_settlement_id_index" on "agency_wallet_transactions"(
  "type",
  "settlement_id"
);
CREATE INDEX "agency_wallet_tenant_default_currency_idx" on "agency_wallet_transactions"(
  "tenant_id",
  "default_agency_tenant_id",
  "currency"
);
CREATE UNIQUE INDEX "tenants_agency_number_unique" on "tenants"(
  "agency_number"
);
CREATE TABLE IF NOT EXISTS "provider_allocations"(
  "id" integer primary key autoincrement not null,
  "network_membership_id" integer not null,
  "agency_tenant_id" varchar not null,
  "merchant_tenant_id" varchar,
  "provider_type" varchar not null,
  "provider_driver" varchar not null,
  "provider_identity" varchar not null,
  "source_provider_model" varchar not null,
  "source_provider_id" integer not null,
  "status" varchar not null default('active'),
  "commission_rate" numeric,
  "markup_rate" numeric,
  "limits" text,
  "metadata" text,
  "approved_at" datetime,
  "suspended_at" datetime,
  "removal_requested_at" datetime,
  "removal_approved_at" datetime,
  "revoked_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "is_offered_by_agency" tinyint(1) not null default '1',
  "is_enabled_by_merchant" tinyint(1) not null default '1',
  "enabled_at" datetime,
  foreign key("merchant_tenant_id") references tenants("id") on delete cascade on update no action,
  foreign key("agency_tenant_id") references tenants("id") on delete cascade on update no action,
  foreign key("network_membership_id") references network_memberships("id") on delete cascade on update no action
);
CREATE INDEX "provider_allocations_logical_provider_idx" on "provider_allocations"(
  "merchant_tenant_id",
  "provider_type",
  "provider_driver",
  "provider_identity",
  "status"
);
CREATE INDEX "provider_allocations_network_membership_id_status_index" on "provider_allocations"(
  "network_membership_id",
  "status"
);
CREATE INDEX "provider_allocations_source_idx" on "provider_allocations"(
  "agency_tenant_id",
  "source_provider_model",
  "source_provider_id"
);
CREATE TABLE IF NOT EXISTS "network_memberships"(
  "id" integer primary key autoincrement not null,
  "agency_tenant_id" varchar not null,
  "merchant_tenant_id" varchar,
  "invitation_token" varchar not null,
  "invitation_code" varchar not null,
  "status" varchar not null default('pending'),
  "expires_at" datetime,
  "accepted_at" datetime,
  "removal_requested_at" datetime,
  "removal_approved_at" datetime,
  "revoked_at" datetime,
  "created_by" integer,
  "metadata" text,
  "created_at" datetime,
  "updated_at" datetime,
  "merchant_email" varchar,
  "merchant_contact_name" varchar,
  "invited_at" datetime,
  "suspended_at" datetime,
  foreign key("merchant_tenant_id") references tenants("id") on delete cascade on update no action,
  foreign key("agency_tenant_id") references tenants("id") on delete cascade on update no action,
  foreign key("created_by") references landlord_users("id") on delete set null on update no action
);
CREATE INDEX "network_memberships_agency_tenant_id_merchant_tenant_id_index" on "network_memberships"(
  "agency_tenant_id",
  "merchant_tenant_id"
);
CREATE INDEX "network_memberships_agency_tenant_id_status_index" on "network_memberships"(
  "agency_tenant_id",
  "status"
);
CREATE UNIQUE INDEX "network_memberships_invitation_code_unique" on "network_memberships"(
  "invitation_code"
);
CREATE UNIQUE INDEX "network_memberships_invitation_token_unique" on "network_memberships"(
  "invitation_token"
);
CREATE INDEX "network_memberships_merchant_tenant_id_status_index" on "network_memberships"(
  "merchant_tenant_id",
  "status"
);
CREATE UNIQUE INDEX "tenants_path_unique" on "tenants"("path");
CREATE TABLE IF NOT EXISTS "migration_records"(
  "id" integer primary key autoincrement not null,
  "legacy_agent_id" integer not null,
  "legacy_agent_name" varchar not null,
  "legacy_agent_number" varchar,
  "tenant_id" varchar,
  "status" varchar not null default 'pending',
  "initiated_by" varchar not null,
  "options" text,
  "log" text,
  "error" text,
  "orders_migrated" integer not null default '0',
  "items_migrated" integer not null default '0',
  "customers_migrated" integer not null default '0',
  "started_at" datetime,
  "completed_at" datetime,
  "created_at" datetime,
  "updated_at" datetime
);

INSERT INTO migrations VALUES(1,'0001_01_01_000000_create_users_table',1);
INSERT INTO migrations VALUES(2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO migrations VALUES(3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO migrations VALUES(4,'2025_08_14_170933_add_two_factor_columns_to_users_table',1);
INSERT INTO migrations VALUES(5,'2019_09_15_000010_create_tenants_table',2);
INSERT INTO migrations VALUES(6,'2019_09_15_000020_create_domains_table',2);
INSERT INTO migrations VALUES(7,'2026_03_09_183832_create_telescope_entries_table',3);
INSERT INTO migrations VALUES(8,'2026_03_29_111430_create_airports_table',4);
INSERT INTO migrations VALUES(9,'2026_03_31_080214_add_management_fields_to_tenants_table',5);
INSERT INTO migrations VALUES(10,'2026_03_31_080214_create_landlord_users_table',5);
INSERT INTO migrations VALUES(18,'2018_11_06_222923_create_transactions_table',6);
INSERT INTO migrations VALUES(19,'2018_11_07_192923_create_transfers_table',6);
INSERT INTO migrations VALUES(20,'2018_11_15_124230_create_wallets_table',6);
INSERT INTO migrations VALUES(21,'2021_11_02_202021_update_wallets_uuid_table',6);
INSERT INTO migrations VALUES(22,'2023_12_30_113122_extra_columns_removed',6);
INSERT INTO migrations VALUES(23,'2023_12_30_204610_soft_delete',6);
INSERT INTO migrations VALUES(24,'2024_01_24_185401_add_extra_column_in_transfer',6);
INSERT INTO migrations VALUES(25,'2026_04_23_140000_create_route_availability_cache_table',6);
INSERT INTO migrations VALUES(26,'2026_04_23_140100_create_flight_schedule_cache_table',6);
INSERT INTO migrations VALUES(27,'2026_04_23_140200_create_landlord_settings_table',6);
INSERT INTO migrations VALUES(28,'2026_04_23_232328_create_airport_countries_table',7);
INSERT INTO migrations VALUES(29,'2026_04_26_120000_create_agency_wallet_transactions_table',8);
INSERT INTO migrations VALUES(30,'2026_04_26_120100_add_default_agency_fields_to_tenants_table',8);
INSERT INTO migrations VALUES(31,'2026_04_26_130100_create_default_agency_settings_table',8);
INSERT INTO migrations VALUES(32,'2026_04_27_090000_create_agency_settlements_table',9);
INSERT INTO migrations VALUES(33,'2026_04_27_090100_add_settlement_fields_to_agency_wallet_transactions_table',9);
INSERT INTO migrations VALUES(34,'2026_05_04_141113_create_network_memberships_table',10);
INSERT INTO migrations VALUES(35,'2026_05_04_141113_create_provider_allocations_table',10);
INSERT INTO migrations VALUES(36,'2026_05_10_131543_add_agency_number_to_tenants_table',11);
INSERT INTO migrations VALUES(37,'2026_05_10_131543_add_merchant_selection_fields_to_provider_allocations_table',11);
INSERT INTO migrations VALUES(38,'2026_05_10_131543_add_phase_one_fields_to_network_memberships_table',11);
INSERT INTO migrations VALUES(39,'2026_05_13_105940_add_path_and_documents_to_tenants_table',12);
INSERT INTO migrations VALUES(40,'2026_05_13_125331_add_type_to_tenants_table',13);
INSERT INTO migrations VALUES(41,'2026_05_22_232916_create_migration_records_table',14);
