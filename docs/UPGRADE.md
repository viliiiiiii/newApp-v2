# Upgrade Guide

This document walks through the critical steps to upgrade an existing deployment to the unified **ABRM** database along with the new infrastructure required for notifications, permissions, and inventory paperwork.

## Database migration to `abrm`

1. Back up the existing `core_db` and `punchlist` databases.
2. Copy `db/migrate_to_abrm.php` to an environment with PHP CLI access.
3. Adjust the credentials in `config.php` so `DB_HOST`, `DB_USER`, and `DB_PASS` have rights to create databases and tables.
4. Run the migration script:
   ```bash
   php db/migrate_to_abrm.php
   ```
5. Review the generated log in `db/logs/migrate_*.log` for conflicts. Tables with naming collisions are imported as `_v2` variants.
6. Update any application servers so `DB_NAME` points to `abrm`.

### Task tables

Task related schemas are imported without modifications. The migration script converts duplicate `INSERT` statements into `INSERT IGNORE` so that existing data is preserved. No column or index on the `tasks*` tables is altered.

### Permissions schema

The migration automatically creates and seeds the `roles`, `permissions`, `role_permissions`, and `user_roles` tables. Review `db/abrm_permissions.sql` for the default mapping and amend as required before re-running the script.

## Configuration updates

### Database

* Update `config.php` so both `APPS_DSN` and `CORE_DSN` reference `abrm`.
* Ensure credentials support accessing the merged database.

### SMTP (`config/mail.php`)

```
return [
    'transport' => 'smtp',
    'host' => 'smtp.example.com',
    'port' => 587,
    'username' => 'user@example.com',
    'password' => 'change-me',
    'encryption' => 'tls',
    'from_email' => 'noreply@example.com',
    'from_name' => 'Punch List Notifications',
];
```

Update the values to match your MTA. The array is consumed by the email notification service.

### Web Push (`config/push.php`)

```
return [
    'vapid' => [
        'subject' => 'mailto:admin@example.com',
        'public_key' => '',
        'private_key' => '',
    ],
    'ttl' => 1800,
];
```

Provide the generated VAPID keys and adjust the TTL as needed.

### Generating VAPID keys

Use the [web-push-php](https://github.com/web-push-libs/web-push-php) utility or the following PHP snippet:

```php
use Minishlink\WebPush\VAPID;

$keys = VAPID::createVapidKeys();
print_r($keys);
```

Persist the keys to `config/push.php`.

## Permissions matrix

| Role    | Key permissions |
|---------|-----------------|
| root    | All permissions (bypass checks) |
| admin   | `user.manage`, `inventory.*`, `tasks.*`, `notes.*`, `dashboard.view`, `reports.view`, `settings.edit`, `notifications.manage`, `files.*`, `global.search`, `inventory.paperwork` |
| manager | `user.view`, `inventory.view`, `inventory.move`, `inventory.approve`, `inventory.export`, `tasks.view`, `tasks.assign`, `tasks.close`, `notes.view`, `notes.create`, `notes.edit`, `dashboard.view`, `notifications.manage`, `files.upload`, `files.view`, `global.search`, `inventory.paperwork` |
| staff   | `inventory.view`, `inventory.move`, `tasks.view`, `tasks.assign`, `notes.view`, `notes.create`, `notes.edit`, `dashboard.view`, `files.upload`, `files.view`, `global.search` |
| viewer  | `inventory.view`, `tasks.view`, `notes.view`, `dashboard.view`, `files.view`, `global.search` |
| auditor | `inventory.view`, `inventory.export`, `tasks.view`, `notes.view`, `dashboard.view`, `reports.view`, `files.view`, `global.search`, `inventory.paperwork` |

### Mapping users to roles

Populate `user_roles` with the appropriate assignments. Users can have multiple roles; permissions are merged across them.

## Inventory paperwork workflow

* Every inventory movement should now trigger paperwork generation. The PDF is saved under `uploads/inventory/paperwork/{YYYY}/{MM}/movement_{id}.pdf`.
* Signed proof uploads are stored next to the generated PDF using the `movement_{id}_signed.ext` naming convention.

Ensure `uploads/` is writable by the PHP process.

## Notifications setup

1. Configure SMTP as above for email delivery.
2. Generate VAPID keys and expose them to the front end for subscription.
3. Ensure `api/notifications_stream.php` is reachable; it uses Server-Sent Events (SSE) and falls back to polling if the client does not support SSE.
4. Background workers should call the notification service helpers whenever trigger events occur (task assignments, inventory approvals, @mentions, etc.).

## Smoke testing

Run the lightweight regression script before releasing:

```bash
php scripts/smoke_check.php
```

The script verifies database connectivity, permissions table presence, and critical routes.

## Email templates

Simple HTML templates reside in `templates/email/`. Copy and customise as needed, keeping inline CSS for maximum compatibility.

---

For additional hardening guidelines (CSP, CSRF tokens, and secure file uploads) consult the security section in `README.md` or the accompanying infrastructure documentation.
