# Timezone and timestamp handling

WP CareGrid keeps its operational clock in UTC. The application timezone in
`config/app.php`, PHP's default timezone, and MySQL/MariaDB session timezone
are UTC. Queue leases, Connector polling, capability freshness, scan timeouts,
backup retention, token expiry, and audit ordering compare UTC instants and do
not use the Owner's display preference.

The Owner's `general.timezone` setting is a validated IANA timezone used only
when presenting timestamps. `App\Support\DisplayDateTime` converts an instant
to that zone without mutating the Carbon value. It falls back to UTC if a
persisted value is missing or invalid. Date-only business values (for example,
subscription periods and tax dates), explicit UTC legal form inputs, and
machine-readable timestamps remain in their domain-defined representation.

Owner forms that accept local wall-clock values (coupon windows, recovery
offers, and internal plan windows) display and parse those values in the
configured Owner timezone, then persist the resulting UTC instant. Changing
the display preference does not rewrite existing timestamp columns.

## Historical records

Before this separation, Owner settings could change both Laravel's runtime
timezone and PHP's process timezone. That could affect `now()` values and
timezone-less local form input, while MySQL `TIMESTAMP` columns also depend on
the database session timezone. The current code sets UTC on the MySQL/MariaDB
session and leaves UTC as the application clock. Existing records are preserved
without a mass conversion: the source code alone cannot prove whether every
historical timestamp was created by UTC or by a process that had applied a
non-UTC Owner timezone. Treat old records as stored instants and do not shift
them without independent provenance.

The presentation formatter is used for human-readable Owner, Operator, and
Customer timestamps. It includes the timezone abbreviation by default (for
example, `CEST` or `CET`) so the repeated local hour at DST transitions remains
distinguishable.
