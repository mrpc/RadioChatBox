-- Migration: Admin Users with Role-Based Access Control
-- Created: 2025-11-12
-- Description: Creates admin_users table with hierarchical roles for admin panel access

-- Create enum type for user roles
DO $$ 
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'admin_role') THEN
        CREATE TYPE admin_role AS ENUM ('root', 'administrator', 'moderator', 'simple_user');
    END IF;
END $$;

-- Admin users table
CREATE TABLE IF NOT EXISTS admin_users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role admin_role NOT NULL DEFAULT 'simple_user',
    email VARCHAR(255),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP,
    created_by INTEGER REFERENCES admin_users(id),
    CONSTRAINT username_length CHECK (LENGTH(username) >= 3),
    CONSTRAINT valid_email CHECK (email IS NULL OR email ~* '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$')
);

CREATE INDEX idx_admin_users_username ON admin_users(username);
CREATE INDEX idx_admin_users_role ON admin_users(role);
CREATE INDEX idx_admin_users_is_active ON admin_users(is_active);

COMMENT ON TABLE admin_users IS 'Admin panel users with role-based access control';

-- Insert default root user (username: admin, password: admin123)
INSERT INTO admin_users (username, password_hash, role, email, is_active) VALUES 
    ('admin', '$2y$10$ZUCvW9SmSpOUwPtWC.XzL.mA0piFBy.DM8TKPHvkWdd0CsG121vCC', 'root', NULL, TRUE)
ON CONFLICT (username) DO NOTHING;

-- Auto-update updated_at timestamp
CREATE OR REPLACE FUNCTION update_admin_users_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_admin_users_updated_at
BEFORE UPDATE ON admin_users
FOR EACH ROW
EXECUTE FUNCTION update_admin_users_updated_at();
