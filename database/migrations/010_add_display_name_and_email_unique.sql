-- Migration 010: Add display_name field and email unique constraint
-- This migration adds support for:
-- 1. Display names (users can have a different display name than their username)
-- 2. Email login (email must be unique when not null)

-- Add display_name column to users table
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS display_name VARCHAR(100);

-- Add constraint for display_name (must be at least 1 character if not null)
DO $$ 
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'display_name_length') THEN
        ALTER TABLE users ADD CONSTRAINT display_name_length CHECK (display_name IS NULL OR LENGTH(display_name) >= 1);
    END IF;
END $$;

-- Create unique index on email (only for non-null values)
-- This allows email login while still permitting NULL emails
CREATE UNIQUE INDEX IF NOT EXISTS idx_users_email_unique ON users(email) WHERE email IS NOT NULL;

-- Create unique index on display_name (only for non-null values)
-- This ensures display names are unique across all users when set
CREATE UNIQUE INDEX IF NOT EXISTS idx_users_display_name_unique ON users(display_name) WHERE display_name IS NOT NULL;

-- Update comment on table to reflect new functionality
COMMENT ON TABLE users IS 'Authenticated user accounts with passwords and role-based access (admins, moderators, future registered chat users). Supports email login when email is provided.';
COMMENT ON COLUMN users.display_name IS 'Optional display name shown in chat - must be unique when set, falls back to username if null';
COMMENT ON COLUMN users.email IS 'Email address for login - must be unique when not null';
