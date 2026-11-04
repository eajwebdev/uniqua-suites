# Data Dictionary

Generated from the migrated database, so it matches the schema exactly. It describes structure only and contains no records.

- Columns ending `_cents` hold pesos as whole centavos. Money is never a floating-point number.
- `_at` columns are UTC timestamps. Plain `DATE` columns are local Asia/Manila dates.
- Unit codes are strings, so a leading zero such as `0918` is kept.
- `room_secrets`, `external_calendar_feeds.url` and `users.two_factor_secret` are encrypted with `APP_KEY`.

## Catalog

### `properties`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `name` | varchar(255) |  |  |  |  |
| `slug` | varchar(255) |  | UNI |  |  |
| `location_label` | varchar(255) |  |  |  |  |
| `public_notes` | text | yes |  |  |  |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `room_types`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `name` | varchar(255) |  | UNI |  |  |
| `slug` | varchar(255) |  | UNI |  |  |

### `rooms`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `property_id` | bigint(20) unsigned |  | MUL |  | properties |
| `room_type_id` | bigint(20) unsigned |  | MUL |  | room_types |
| `name` | varchar(255) |  |  |  |  |
| `slug` | varchar(255) |  | UNI |  |  |
| `unit_code` | varchar(255) |  |  |  |  |
| `floor` | varchar(255) | yes |  |  |  |
| `bedrooms` | tinyint(3) unsigned |  |  |  |  |
| `ac_count` | tinyint(3) unsigned |  |  |  |  |
| `bathrooms` | tinyint(3) unsigned |  |  |  |  |
| `has_balcony` | tinyint(1) |  |  | `0` |  |
| `included_guests` | tinyint(3) unsigned |  |  |  |  |
| `max_guests` | tinyint(3) unsigned |  |  |  |  |
| `description` | text | yes |  |  |  |
| `inclusions` | text | yes |  |  |  |
| `policies` | text | yes |  |  |  |
| `operational_notes` | text | yes |  |  |  |
| `catalog_status` | varchar(255) |  |  | `proposed` |  |
| `is_published` | tinyint(1) |  | MUL | `0` |  |
| `operational_status` | varchar(255) |  |  | `active` |  |
| `housekeeping_status` | varchar(255) |  |  | `ready` |  |
| `parking_eligible` | tinyint(1) |  |  | `1` |  |
| `active_from` | date | yes |  |  |  |
| `active_to` | date | yes |  |  |  |
| `catalog_approved_by` | bigint(20) unsigned | yes | MUL |  | users |
| `catalog_approved_at` | timestamp | yes |  |  |  |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |
| `min_nights` | smallint(5) unsigned | yes |  |  |  |
| `max_advance_days` | smallint(5) unsigned | yes |  |  |  |

### `room_images`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `room_id` | bigint(20) unsigned |  | MUL |  | rooms |
| `path` | varchar(255) |  |  |  |  |
| `alt_text` | varchar(255) |  |  |  |  |
| `position` | smallint(5) unsigned |  |  | `0` |  |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `amenities`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `name` | varchar(255) |  | UNI |  |  |

### `amenity_room`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `room_id` | bigint(20) unsigned |  | PRI |  | rooms |
| `amenity_id` | bigint(20) unsigned |  | PRI |  | amenities |

### `room_blocks`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `room_id` | bigint(20) unsigned |  | MUL |  | rooms |
| `start_date` | date |  |  |  |  |
| `end_date` | date |  |  |  |  |
| `kind` | varchar(255) |  |  |  |  |
| `reason` | varchar(255) |  |  |  |  |
| `external_uid` | varchar(255) | yes |  |  |  |
| `external_calendar_feed_id` | bigint(20) unsigned | yes | MUL |  |  |
| `created_by` | bigint(20) unsigned | yes | MUL |  | users |
| `released_at` | timestamp | yes |  |  |  |
| `released_by` | bigint(20) unsigned | yes | MUL |  | users |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `room_status_events`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `room_id` | bigint(20) unsigned |  | MUL |  | rooms |
| `dimension` | varchar(255) |  |  |  |  |
| `from_status` | varchar(255) | yes |  |  |  |
| `to_status` | varchar(255) |  |  |  |  |
| `note` | varchar(255) | yes |  |  |  |
| `user_id` | bigint(20) unsigned | yes | MUL |  | users |
| `created_at` | timestamp |  |  | `current_timestamp()` |  |

### `room_listings`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `room_id` | bigint(20) unsigned |  | MUL |  | rooms |
| `provider` | varchar(60) |  | MUL |  |  |
| `listing_id` | varchar(120) |  |  |  |  |
| `url` | varchar(500) | yes |  |  |  |
| `created_by` | bigint(20) unsigned | yes | MUL |  | users |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `room_secrets`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `room_id` | bigint(20) unsigned |  | UNI |  | rooms |
| `network_name` | text | yes |  |  |  |
| `password` | text | yes |  |  |  |
| `troubleshooting` | text | yes |  |  |  |
| `contact_person` | text | yes |  |  |  |
| `last_verified_on` | date | yes |  |  |  |
| `updated_by` | bigint(20) unsigned | yes | MUL |  | users |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `rate_rules`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `room_id` | bigint(20) unsigned |  | MUL |  | rooms |
| `effective_from` | date |  |  |  |  |
| `effective_to` | date | yes |  |  |  |
| `weekday_cents` | bigint(20) unsigned |  |  |  |  |
| `weekend_cents` | bigint(20) unsigned |  |  |  |  |
| `source_note` | varchar(255) | yes |  |  |  |
| `created_by` | bigint(20) unsigned | yes | MUL |  | users |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `rate_overrides`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `room_id` | bigint(20) unsigned |  | MUL |  | rooms |
| `booking_source_id` | bigint(20) unsigned | yes | MUL |  | booking_sources |
| `date_from` | date |  |  |  |  |
| `date_to` | date |  |  |  |  |
| `nightly_cents` | bigint(20) unsigned |  |  |  |  |
| `label` | varchar(255) |  |  |  |  |
| `created_by` | bigint(20) unsigned | yes | MUL |  | users |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `tax_rules`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `name` | varchar(80) |  |  |  |  |
| `rate_basis_points` | int(10) unsigned |  |  |  |  |
| `effective_from` | date |  | MUL |  |  |
| `effective_to` | date | yes |  |  |  |
| `created_by` | bigint(20) unsigned | yes | MUL |  | users |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

## Bookings

### `guests`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `full_name` | varchar(255) |  | MUL |  |  |
| `phone` | varchar(255) | yes |  |  |  |
| `email` | varchar(255) | yes |  |  |  |
| `messenger_handle` | varchar(255) | yes |  |  |  |
| `company` | varchar(255) | yes |  |  |  |
| `notes` | text | yes |  |  |  |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `booking_sources`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `code` | varchar(255) |  | UNI |  |  |
| `name` | varchar(255) |  |  |  |  |
| `is_active` | tinyint(1) |  |  | `1` |  |
| `is_legacy_only` | tinyint(1) |  |  | `0` |  |

### `bookings`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `reference` | varchar(255) |  | UNI |  |  |
| `idempotency_key` | varchar(255) | yes | UNI |  |  |
| `guest_id` | bigint(20) unsigned |  | MUL |  | guests |
| `property_id` | bigint(20) unsigned |  | MUL |  | properties |
| `booking_source_id` | bigint(20) unsigned |  | MUL |  | booking_sources |
| `source_reference` | varchar(255) | yes |  |  |  |
| `referrer` | varchar(255) | yes |  |  |  |
| `import_source` | varchar(255) |  |  | `manual` |  |
| `status` | varchar(255) |  | MUL | `inquiry` |  |
| `check_in` | date |  |  |  |  |
| `check_out` | date |  |  |  |  |
| `requested_arrival_time` | time | yes |  |  |  |
| `approved_early_time` | time | yes |  |  |  |
| `actual_check_in_at` | timestamp | yes |  |  |  |
| `actual_check_out_at` | timestamp | yes |  |  |  |
| `adults` | tinyint(3) unsigned |  |  | `1` |  |
| `children` | tinyint(3) unsigned |  |  | `0` |  |
| `guest_count` | tinyint(3) unsigned |  |  | `1` |  |
| `special_requests` | text | yes |  |  |  |
| `internal_notes` | text | yes |  |  |  |
| `hold_expires_at` | timestamp | yes |  |  |  |
| `pricing_version` | int(10) unsigned |  |  | `0` |  |
| `total_cents` | bigint(20) |  |  | `0` |  |
| `cancellation_reason` | varchar(255) | yes |  |  |  |
| `created_by` | bigint(20) unsigned | yes | MUL |  | users |
| `confirmed_by` | bigint(20) unsigned | yes | MUL |  | users |
| `confirmed_at` | timestamp | yes |  |  |  |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `booking_rooms`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `booking_id` | bigint(20) unsigned |  | MUL |  | bookings |
| `room_id` | bigint(20) unsigned |  | MUL |  | rooms |
| `check_in` | date |  |  |  |  |
| `check_out` | date |  |  |  |  |
| `guest_count` | tinyint(3) unsigned |  |  |  |  |
| `released_at` | timestamp | yes |  |  |  |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `booking_nights`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `booking_id` | bigint(20) unsigned |  | MUL |  | bookings |
| `room_id` | bigint(20) unsigned |  | MUL |  | rooms |
| `stay_date` | date |  | MUL |  |  |
| `rate_cents` | bigint(20) |  |  |  |  |
| `rate_source` | varchar(255) |  |  |  |  |
| `pricing_version` | int(10) unsigned |  |  |  |  |
| `is_current` | tinyint(1) |  |  | `1` |  |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `booking_charges`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `booking_id` | bigint(20) unsigned |  | MUL |  | bookings |
| `type` | varchar(255) |  | MUL |  |  |
| `description` | varchar(255) |  |  |  |  |
| `quantity` | decimal(8,2) |  |  | `1.00` |  |
| `unit_cents` | bigint(20) |  |  |  |  |
| `amount_cents` | bigint(20) |  |  |  |  |
| `charge_date` | date | yes |  |  |  |
| `pricing_version` | int(10) unsigned |  |  | `0` |  |
| `approved_by` | bigint(20) unsigned | yes | MUL |  | users |
| `created_by` | bigint(20) unsigned | yes | MUL |  | users |
| `voided_at` | timestamp | yes |  |  |  |
| `void_reason` | varchar(255) | yes |  |  |  |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `booking_status_events`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `booking_id` | bigint(20) unsigned |  | MUL |  | bookings |
| `from_status` | varchar(255) | yes |  |  |  |
| `to_status` | varchar(255) |  |  |  |  |
| `note` | varchar(255) | yes |  |  |  |
| `user_id` | bigint(20) unsigned | yes | MUL |  | users |
| `created_at` | timestamp |  |  | `current_timestamp()` |  |

### `pricing_snapshots`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `booking_id` | bigint(20) unsigned |  | MUL |  | bookings |
| `version` | int(10) unsigned |  |  |  |  |
| `snapshot` | longtext |  |  |  |  |
| `total_cents` | bigint(20) |  |  |  |  |
| `reason` | varchar(255) |  |  |  |  |
| `created_by` | bigint(20) unsigned | yes | MUL |  | users |
| `created_at` | timestamp |  |  | `current_timestamp()` |  |

### `quotes`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `reference` | varchar(255) |  | MUL |  |  |
| `version` | int(10) unsigned |  |  | `1` |  |
| `booking_id` | bigint(20) unsigned | yes | MUL |  | bookings |
| `room_id` | bigint(20) unsigned |  | MUL |  | rooms |
| `booking_source_id` | bigint(20) unsigned | yes | MUL |  | booking_sources |
| `guest_name` | varchar(255) | yes |  |  |  |
| `check_in` | date |  |  |  |  |
| `check_out` | date |  |  |  |  |
| `guest_count` | tinyint(3) unsigned |  |  |  |  |
| `early_time` | time | yes |  |  |  |
| `total_cents` | bigint(20) |  |  |  |  |
| `warnings` | longtext | yes |  |  |  |
| `created_by` | bigint(20) unsigned | yes | MUL |  | users |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `quote_items`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `quote_id` | bigint(20) unsigned |  | MUL |  | quotes |
| `type` | varchar(255) |  |  |  |  |
| `description` | varchar(255) |  |  |  |  |
| `quantity` | decimal(8,2) |  |  | `1.00` |  |
| `unit_cents` | bigint(20) |  |  |  |  |
| `amount_cents` | bigint(20) |  |  |  |  |

### `message_templates`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `key` | varchar(255) |  | UNI |  |  |
| `name` | varchar(255) |  |  |  |  |
| `body` | text |  |  |  |  |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `external_calendar_feeds`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `room_id` | bigint(20) unsigned |  | MUL |  | rooms |
| `provider` | varchar(255) |  |  | `airbnb` |  |
| `url` | text |  |  |  |  |
| `is_active` | tinyint(1) |  |  | `1` |  |
| `last_synced_at` | timestamp | yes |  |  |  |
| `last_status` | varchar(255) | yes |  |  |  |
| `last_error` | text | yes |  |  |  |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `external_reservation_links`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `booking_id` | bigint(20) unsigned |  | MUL |  | bookings |
| `provider` | varchar(255) |  | MUL |  |  |
| `external_id` | varchar(255) |  |  |  |  |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

## Finance

### `payment_accounts`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `name` | varchar(255) |  |  |  |  |
| `kind` | varchar(255) |  |  |  |  |
| `is_active` | tinyint(1) |  |  | `1` |  |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `receipts`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `reference` | varchar(255) |  | UNI |  |  |
| `booking_id` | bigint(20) unsigned | yes | MUL |  | bookings |
| `received_at` | timestamp |  |  | `current_timestamp()` |  |
| `receipt_date` | date |  |  |  |  |
| `amount_cents` | bigint(20) |  |  |  |  |
| `method` | varchar(255) |  |  |  |  |
| `payment_account_id` | bigint(20) unsigned | yes | MUL |  | payment_accounts |
| `external_reference` | varchar(255) | yes | MUL |  |  |
| `evidence_path` | varchar(255) | yes |  |  |  |
| `verification_status` | varchar(255) |  | MUL | `pending` |  |
| `review_note` | varchar(255) | yes |  |  |  |
| `entered_by` | bigint(20) unsigned | yes | MUL |  | users |
| `reviewed_by` | bigint(20) unsigned | yes | MUL |  | users |
| `reviewed_at` | timestamp | yes |  |  |  |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |
| `reverses_receipt_id` | bigint(20) unsigned | yes | UNI |  | receipts |

### `refunds`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `booking_id` | bigint(20) unsigned |  | MUL |  | bookings |
| `receipt_id` | bigint(20) unsigned | yes | MUL |  | receipts |
| `amount_cents` | bigint(20) |  |  |  |  |
| `reason` | varchar(255) |  |  |  |  |
| `status` | varchar(255) |  | MUL | `requested` |  |
| `executed_on` | date | yes |  |  |  |
| `requested_by` | bigint(20) unsigned | yes | MUL |  | users |
| `approved_by` | bigint(20) unsigned | yes | MUL |  | users |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `external_settlements`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `booking_id` | bigint(20) unsigned | yes | MUL |  | bookings |
| `provider` | varchar(255) |  | MUL |  |  |
| `payout_reference` | varchar(255) | yes |  |  |  |
| `gross_cents` | bigint(20) |  |  |  |  |
| `fees_cents` | bigint(20) |  |  | `0` |  |
| `adjustments_cents` | bigint(20) |  |  | `0` |  |
| `expected_net_cents` | bigint(20) |  |  |  |  |
| `actual_payout_cents` | bigint(20) | yes |  |  |  |
| `payout_date` | date | yes | MUL |  |  |
| `payment_account_id` | bigint(20) unsigned | yes | MUL |  | payment_accounts |
| `notes` | varchar(255) | yes |  |  |  |
| `created_by` | bigint(20) unsigned | yes | MUL |  | users |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `expense_categories`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `name` | varchar(255) |  | UNI |  |  |

### `vendors`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `name` | varchar(255) |  |  |  |  |
| `contact` | varchar(255) | yes |  |  |  |
| `kind` | varchar(255) | yes |  |  |  |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `expenses`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `expense_category_id` | bigint(20) unsigned |  | MUL |  | expense_categories |
| `property_id` | bigint(20) unsigned | yes | MUL |  | properties |
| `room_id` | bigint(20) unsigned | yes | MUL |  | rooms |
| `booking_id` | bigint(20) unsigned | yes | MUL |  | bookings |
| `vendor_id` | bigint(20) unsigned | yes | MUL |  | vendors |
| `description` | varchar(255) |  |  |  |  |
| `amount_cents` | bigint(20) |  |  |  |  |
| `expense_date` | date |  | MUL |  |  |
| `status` | varchar(255) |  |  | `incurred` |  |
| `paid_on` | date | yes |  |  |  |
| `payment_method` | varchar(255) | yes |  |  |  |
| `proof_path` | varchar(255) | yes |  |  |  |
| `recurrence` | varchar(255) | yes |  |  |  |
| `approved_by` | bigint(20) unsigned | yes | MUL |  | users |
| `approved_at` | timestamp | yes |  |  |  |
| `created_by` | bigint(20) unsigned | yes | MUL |  | users |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |
| `reverses_expense_id` | bigint(20) unsigned | yes | UNI |  | expenses |
| `reversal_reason` | varchar(255) | yes |  |  |  |

### `period_locks`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `period` | varchar(7) |  | UNI |  |  |
| `locked_by` | bigint(20) unsigned |  | MUL |  | users |
| `locked_at` | timestamp |  |  | `current_timestamp()` |  |
| `notes` | text | yes |  |  |  |

## Operations

### `housekeeping_tasks`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `idempotency_key` | varchar(255) | yes | UNI |  |  |
| `type` | varchar(255) |  |  | `turnover` |  |
| `room_id` | bigint(20) unsigned |  | MUL |  | rooms |
| `booking_id` | bigint(20) unsigned | yes | MUL |  | bookings |
| `assigned_to` | bigint(20) unsigned | yes | MUL |  | users |
| `status` | varchar(255) |  | MUL | `pending` |  |
| `priority` | varchar(255) |  |  | `normal` |  |
| `due_at` | timestamp | yes |  |  |  |
| `instructions` | text | yes |  |  |  |
| `checklist` | longtext | yes |  |  |  |
| `completion_notes` | text | yes |  |  |  |
| `started_at` | timestamp | yes |  |  |  |
| `completed_at` | timestamp | yes |  |  |  |
| `inspected_by` | bigint(20) unsigned | yes | MUL |  | users |
| `inspected_at` | timestamp | yes |  |  |  |
| `cost_cents` | bigint(20) | yes |  |  |  |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `task_events`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `housekeeping_task_id` | bigint(20) unsigned |  | MUL |  | housekeeping_tasks |
| `event` | varchar(255) |  |  |  |  |
| `note` | varchar(255) | yes |  |  |  |
| `user_id` | bigint(20) unsigned | yes | MUL |  | users |
| `created_at` | timestamp |  |  | `current_timestamp()` |  |

### `task_attachments`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `housekeeping_task_id` | bigint(20) unsigned |  | MUL |  | housekeeping_tasks |
| `path` | varchar(255) |  |  |  |  |
| `stage` | varchar(255) |  |  | `after` |  |
| `user_id` | bigint(20) unsigned | yes | MUL |  | users |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `laundry_jobs`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `idempotency_key` | varchar(255) | yes | UNI |  |  |
| `room_id` | bigint(20) unsigned |  | MUL |  | rooms |
| `booking_id` | bigint(20) unsigned | yes | MUL |  | bookings |
| `status` | varchar(255) |  | MUL | `suggested` |  |
| `quantity` | smallint(5) unsigned | yes |  |  |  |
| `provider` | varchar(255) | yes |  |  |  |
| `pickup_on` | date | yes |  |  |  |
| `delivered_on` | date | yes |  |  |  |
| `cost_cents` | bigint(20) | yes |  |  |  |
| `notes` | varchar(255) | yes |  |  |  |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `work_orders`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `room_id` | bigint(20) unsigned | yes | MUL |  | rooms |
| `property_id` | bigint(20) unsigned | yes | MUL |  | properties |
| `assigned_to` | bigint(20) unsigned | yes | MUL |  | users |
| `vendor_id` | bigint(20) unsigned | yes | MUL |  | vendors |
| `title` | varchar(255) |  |  |  |  |
| `description` | text | yes |  |  |  |
| `category` | varchar(255) |  |  | `general` |  |
| `severity` | varchar(255) |  |  | `normal` |  |
| `safety_block` | tinyint(1) |  |  | `0` |  |
| `status` | varchar(255) |  |  | `open` |  |
| `classification` | varchar(255) |  |  | `unclassified` |  |
| `cost_cents` | bigint(20) | yes |  |  |  |
| `due_on` | date | yes |  |  |  |
| `resolved_at` | timestamp | yes |  |  |  |
| `created_by` | bigint(20) unsigned | yes | MUL |  | users |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `incidents`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `booking_id` | bigint(20) unsigned | yes | MUL |  | bookings |
| `room_id` | bigint(20) unsigned |  | MUL |  | rooms |
| `guest_id` | bigint(20) unsigned | yes | MUL |  | guests |
| `work_order_id` | bigint(20) unsigned | yes | MUL |  | work_orders |
| `charge_id` | bigint(20) unsigned | yes | MUL |  | booking_charges |
| `item` | varchar(255) |  |  |  |  |
| `description` | text | yes |  |  |  |
| `discovered_on` | date |  |  |  |  |
| `estimated_cents` | bigint(20) | yes |  |  |  |
| `approved_cents` | bigint(20) | yes |  |  |  |
| `status` | varchar(255) |  | MUL | `reported` |  |
| `owner_resolution` | text | yes |  |  |  |
| `repair_cost_cents` | bigint(20) | yes |  |  |  |
| `closure_notes` | text | yes |  |  |  |
| `reported_by` | bigint(20) unsigned | yes | MUL |  | users |
| `approved_by` | bigint(20) unsigned | yes | MUL |  | users |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `incident_evidence`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `incident_id` | bigint(20) unsigned |  | MUL |  | incidents |
| `path` | varchar(255) |  |  |  |  |
| `note` | varchar(255) | yes |  |  |  |
| `user_id` | bigint(20) unsigned | yes | MUL |  | users |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `incident_notes`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `incident_id` | bigint(20) unsigned |  | MUL |  | incidents |
| `user_id` | bigint(20) unsigned | yes | MUL |  | users |
| `channel` | varchar(30) |  |  |  |  |
| `body` | text |  |  |  |  |
| `created_at` | timestamp | yes |  |  |  |

### `inventory_items`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `sku` | varchar(255) |  | UNI |  |  |
| `name` | varchar(255) |  |  |  |  |
| `unit` | varchar(255) |  |  | `pc` |  |
| `reorder_level` | int(11) |  |  | `0` |  |
| `unit_cost_cents` | bigint(20) | yes |  |  |  |
| `supplier` | varchar(255) | yes |  |  |  |
| `is_active` | tinyint(1) |  |  | `1` |  |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `inventory_movements`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `inventory_item_id` | bigint(20) unsigned |  | MUL |  | inventory_items |
| `property_id` | bigint(20) unsigned |  | MUL |  | properties |
| `room_id` | bigint(20) unsigned | yes | MUL |  | rooms |
| `housekeeping_task_id` | bigint(20) unsigned | yes | MUL |  | housekeeping_tasks |
| `type` | varchar(255) |  |  |  |  |
| `quantity` | int(11) |  |  |  |  |
| `note` | varchar(255) | yes |  |  |  |
| `user_id` | bigint(20) unsigned | yes | MUL |  | users |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `parking_spaces`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `property_id` | bigint(20) unsigned |  | MUL |  | properties |
| `code` | varchar(255) |  |  |  |  |
| `vendor_name` | varchar(255) | yes |  |  |  |
| `vendor_contact` | varchar(255) | yes |  |  |  |
| `rate_cents` | bigint(20) | yes |  |  |  |
| `cost_cents` | bigint(20) | yes |  |  |  |
| `rate_status` | varchar(255) |  |  | `proposed` |  |
| `restrictions` | varchar(255) | yes |  |  |  |
| `is_active` | tinyint(1) |  |  | `1` |  |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `parking_assignments`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `parking_space_id` | bigint(20) unsigned |  | MUL |  | parking_spaces |
| `booking_id` | bigint(20) unsigned | yes | MUL |  | bookings |
| `holder_name` | varchar(255) |  |  |  |  |
| `vehicle` | varchar(255) | yes |  |  |  |
| `start_date` | date |  |  |  |  |
| `end_date` | date |  |  |  |  |
| `status` | varchar(255) |  |  | `active` |  |
| `charge_id` | bigint(20) unsigned | yes | MUL |  | booking_charges |
| `created_by` | bigint(20) unsigned | yes | MUL |  | users |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

## Staff advances (restricted)

### `employees`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `user_id` | bigint(20) unsigned | yes | MUL |  | users |
| `full_name` | varchar(255) |  |  |  |  |
| `position` | varchar(255) | yes |  |  |  |
| `is_active` | tinyint(1) |  |  | `1` |  |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `staff_cash_advances`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `employee_id` | bigint(20) unsigned |  | MUL |  | employees |
| `advance_date` | date |  |  |  |  |
| `amount_cents` | bigint(20) |  |  |  |  |
| `note` | varchar(255) | yes |  |  |  |
| `approved_by` | bigint(20) unsigned | yes | MUL |  | users |
| `created_by` | bigint(20) unsigned | yes | MUL |  | users |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `staff_repayments`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `employee_id` | bigint(20) unsigned |  | MUL |  | employees |
| `repaid_on` | date |  |  |  |  |
| `amount_cents` | bigint(20) |  |  |  |  |
| `note` | varchar(255) | yes |  |  |  |
| `created_by` | bigint(20) unsigned | yes | MUL |  | users |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

## Historical import

### `import_batches`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `file_name` | varchar(255) |  |  |  |  |
| `stored_path` | varchar(255) |  |  |  |  |
| `checksum` | varchar(64) |  |  |  |  |
| `status` | varchar(255) |  |  | `staged` |  |
| `transformation_version` | varchar(255) |  |  |  |  |
| `summary` | longtext | yes |  |  |  |
| `imported_by` | bigint(20) unsigned | yes | MUL |  | users |
| `committed_by` | bigint(20) unsigned | yes | MUL |  | users |
| `committed_at` | timestamp | yes |  |  |  |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `legacy_rows`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `import_batch_id` | bigint(20) unsigned |  | MUL |  | import_batches |
| `source_key` | varchar(255) |  | UNI |  |  |
| `record_type` | varchar(255) |  |  |  |  |
| `sheet` | varchar(255) |  |  |  |  |
| `row_number` | int(10) unsigned |  |  |  |  |
| `cell_range` | varchar(255) | yes |  |  |  |
| `raw` | longtext |  |  |  |  |
| `room_label` | varchar(255) | yes |  |  |  |
| `room_id` | bigint(20) unsigned | yes | MUL |  | rooms |
| `record_date` | date | yes | MUL |  |  |
| `guest_name` | varchar(255) | yes |  |  |  |
| `source_label` | varchar(255) | yes |  |  |  |
| `payment_label` | varchar(255) | yes |  |  |  |
| `amount_cents` | bigint(20) | yes |  |  |  |
| `extra_cents` | bigint(20) | yes |  |  |  |
| `confidence` | tinyint(3) unsigned |  |  | `0` |  |
| `review_status` | varchar(255) |  |  | `pending` |  |
| `reviewed_by` | bigint(20) unsigned | yes | MUL |  | users |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `import_anomalies`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `import_batch_id` | bigint(20) unsigned |  | MUL |  | import_batches |
| `legacy_row_id` | bigint(20) unsigned | yes | MUL |  | legacy_rows |
| `category` | varchar(255) |  |  |  |  |
| `detail` | varchar(255) |  |  |  |  |
| `status` | varchar(255) |  |  | `open` |  |
| `resolved_by` | bigint(20) unsigned | yes | MUL |  | users |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `import_mappings`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `label` | varchar(255) |  | UNI |  |  |
| `room_id` | bigint(20) unsigned | yes | MUL |  | rooms |
| `status` | varchar(255) |  |  | `unverified` |  |
| `decided_by` | bigint(20) unsigned | yes | MUL |  | users |
| `decided_at` | timestamp | yes |  |  |  |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

## People and system

### `users`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `name` | varchar(255) |  |  |  |  |
| `email` | varchar(255) |  | UNI |  |  |
| `email_verified_at` | timestamp | yes |  |  |  |
| `password` | varchar(255) |  |  |  |  |
| `two_factor_secret` | text | yes |  |  |  |
| `two_factor_recovery_codes` | text | yes |  |  |  |
| `two_factor_confirmed_at` | timestamp | yes |  |  |  |
| `two_factor_last_step` | bigint(20) unsigned | yes |  |  |  |
| `is_active` | tinyint(1) |  |  | `1` |  |
| `remember_token` | varchar(100) | yes |  |  |  |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |
| `muted_notifications` | text | yes |  |  |  |

### `activity_logs`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `user_id` | bigint(20) unsigned | yes | MUL |  | users |
| `action` | varchar(255) |  | MUL |  |  |
| `subject_type` | varchar(255) | yes | MUL |  |  |
| `subject_id` | bigint(20) unsigned | yes |  |  |  |
| `meta` | longtext | yes |  |  |  |
| `ip` | varchar(45) | yes |  |  |  |
| `created_at` | timestamp |  |  | `current_timestamp()` |  |

### `owner_decisions`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `key` | varchar(255) |  | UNI |  |  |
| `title` | varchar(255) |  |  |  |  |
| `detail` | text |  |  |  |  |
| `kind` | varchar(255) |  |  |  |  |
| `status` | varchar(255) |  |  | `pending` |  |
| `resolution` | text | yes |  |  |  |
| `decided_by` | bigint(20) unsigned | yes | MUL |  | users |
| `decided_at` | timestamp | yes |  |  |  |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `application_settings`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `key` | varchar(255) |  | PRI |  |  |
| `value` | longtext | yes |  |  |  |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `integration_health`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | bigint(20) unsigned |  | PRI |  |  |
| `provider` | varchar(255) |  | UNI |  |  |
| `status` | varchar(255) |  |  | `not_connected` |  |
| `detail` | text | yes |  |  |  |
| `last_success_at` | timestamp | yes |  |  |  |
| `last_failure_at` | timestamp | yes |  |  |  |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

### `notifications`

| Column | Type | Null | Key | Default | References |
|---|---|---|---|---|---|
| `id` | char(36) |  | PRI |  |  |
| `type` | varchar(255) |  |  |  |  |
| `notifiable_type` | varchar(255) |  | MUL |  |  |
| `notifiable_id` | bigint(20) unsigned |  |  |  |  |
| `data` | text |  |  |  |  |
| `read_at` | timestamp | yes |  |  |  |
| `created_at` | timestamp | yes |  |  |  |
| `updated_at` | timestamp | yes |  |  |  |

## Framework tables

Managed by Laravel and the permissions package: `cache`, `cache_locks`, `failed_jobs`, `job_batches`, `jobs`, `migrations`, `model_has_permissions`, `model_has_roles`, `password_reset_tokens`, `permissions`, `role_has_permissions`, `roles`, `sessions`.
