-- Roles and permissions baseline for ABRM unified database
CREATE TABLE IF NOT EXISTS roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_roles (
    user_id INT UNSIGNED NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO roles (name, description) VALUES
    ('root', 'Omnipotent super administrator'),
    ('admin', 'Full administrative access except system level overrides'),
    ('viewer', 'Read-only access'),
    ('manager', 'Manage teams, tasks, and inventory approvals'),
    ('staff', 'Standard staff operations'),
    ('auditor', 'Read-only with access to exports and audit tools')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO permissions (code, description) VALUES
    ('user.manage', 'Create, edit, and delete users'),
    ('user.view', 'View user details'),
    ('role.manage', 'Manage roles and permissions'),
    ('inventory.view', 'View inventory items'),
    ('inventory.move', 'Create inventory movements'),
    ('inventory.approve', 'Approve inventory actions'),
    ('inventory.export', 'Export inventory data'),
    ('tasks.view', 'View tasks'),
    ('tasks.assign', 'Assign tasks to users'),
    ('tasks.close', 'Close tasks'),
    ('notes.view', 'View notes'),
    ('notes.create', 'Create notes'),
    ('notes.edit', 'Edit notes'),
    ('notes.delete', 'Delete notes'),
    ('dashboard.view', 'View dashboard overview'),
    ('reports.view', 'Access reports'),
    ('settings.edit', 'Edit application settings'),
    ('notifications.manage', 'Manage notification preferences'),
    ('files.upload', 'Upload files'),
    ('files.view', 'View files'),
    ('global.search', 'Use global search'),
    ('inventory.paperwork', 'Access inventory paperwork documents')
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- Seed default role to permission relationships
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p
WHERE (r.name = 'admin' AND p.code IN (
        'user.manage','user.view','inventory.view','inventory.move','inventory.approve','inventory.export',
        'tasks.view','tasks.assign','tasks.close','notes.view','notes.create','notes.edit','notes.delete',
        'dashboard.view','reports.view','settings.edit','notifications.manage','files.upload','files.view','global.search','inventory.paperwork'
    ))
   OR (r.name = 'manager' AND p.code IN (
        'user.view','inventory.view','inventory.move','inventory.approve','inventory.export',
        'tasks.view','tasks.assign','tasks.close','notes.view','notes.create','notes.edit','dashboard.view','notifications.manage','files.upload','files.view','global.search','inventory.paperwork'
    ))
   OR (r.name = 'staff' AND p.code IN (
        'inventory.view','inventory.move','tasks.view','tasks.assign','notes.view','notes.create','notes.edit','dashboard.view','files.upload','files.view','global.search'
    ))
   OR (r.name = 'viewer' AND p.code IN (
        'inventory.view','tasks.view','notes.view','dashboard.view','files.view','global.search'
    ))
   OR (r.name = 'auditor' AND p.code IN (
        'inventory.view','inventory.export','tasks.view','notes.view','dashboard.view','reports.view','files.view','global.search','inventory.paperwork'
    ))
ON DUPLICATE KEY UPDATE permission_id = role_permissions.permission_id;
