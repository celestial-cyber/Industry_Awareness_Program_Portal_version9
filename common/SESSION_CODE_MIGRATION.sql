-- ============================================================================
-- Session Code Feature - Database Migration
-- ============================================================================
-- This file contains the SQL commands to add session code functionality
-- Run this file in your MySQL client to initialize the feature
-- ============================================================================

-- Step 1: Add session_code column to sessions table
ALTER TABLE sessions ADD COLUMN session_code VARCHAR(20) NULL AFTER id;

-- Step 2: Add unique constraint on session_code
ALTER TABLE sessions ADD UNIQUE KEY uq_sessions_session_code (session_code);

-- Step 3: Verify the changes
SHOW COLUMNS FROM sessions LIKE 'session_code';
SHOW INDEX FROM sessions WHERE Key_name = 'uq_sessions_session_code';

-- Step 4: View all sessions (should show session_code column)
SELECT id, session_code, topic, year FROM sessions ORDER BY year, id;

-- ============================================================================
-- MIGRATION COMPLETE!
-- ============================================================================
-- The session code feature is now ready to use.
-- Session codes will be auto-generated when creating new sessions.
-- Existing sessions will be backfilled with codes automatically.
-- ============================================================================
