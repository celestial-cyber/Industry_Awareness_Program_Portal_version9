# IAP Portal - Database Documentation

## Quick Start

### Setup New Database
```bash
mysql -u root -p iap_portal < database/iap_master_database.sql
```

### Verify Installation
```bash
mysql -u root -p iap_portal -e "SHOW TABLES;"
```

Expected output:
```
iap_psychometric_questions
iap_psychometric_scores
iap_session_registrations
iap_session_suggestions
iap_sessions
iap_student_sessions
iap_students
iap_users_details
```

---

## Database Schema

### Tables (8 Total)

#### 1. iap_users_details
Admin user credentials
- id (PK)
- username (UNIQUE)
- email (UNIQUE)
- password (bcrypt)
- role (admin/student)

#### 2. iap_students
Student accounts
- id (PK)
- roll_number (UNIQUE)
- full_name
- email (UNIQUE)
- department
- year (1-4, Graduate)
- password (bcrypt)
- is_password_changed
- reset_token
- reset_token_expiry
- created_at
- updated_at

#### 3. iap_sessions
Sessions/Courses
- id (PK)
- session_code (UNIQUE, nullable)
- topic
- title (nullable)
- year (1-4, Graduate)
- description (nullable)
- created_at

#### 4. iap_student_sessions
Student-Session Registrations (Many-to-Many)
- id (PK)
- student_id (FK → iap_students)
- session_id (FK → iap_sessions)
- session_year
- registration_status (registered/completed/dropped)
- registered_at
- UNIQUE(student_id, session_id)

#### 5. iap_session_registrations
Form Submissions
- id (PK)
- name
- roll_number
- year (1-4, Graduate)
- department
- email
- session_desired
- other_query
- submitted_at

#### 6. iap_session_suggestions
Student Suggestions
- id (PK)
- name
- roll_number
- year (1-4, Graduate)
- branch
- section
- session_desired
- other_query
- status (pending/reviewed/approved/rejected)
- submitted_at

#### 7. iap_psychometric_scores
Assessment Results
- id (PK)
- student_id (FK → iap_students, UNIQUE)
- score
- trait_a
- trait_b
- trait_c
- trait_d
- completed_at

#### 8. iap_psychometric_questions
Quiz Questions
- id (PK)
- question (UNIQUE)
- option_a
- option_b
- option_c
- option_d
- correct_answer
- created_at

---

## Relationships

```
iap_students (1) ──→ (Many) iap_student_sessions
iap_sessions (1) ──→ (Many) iap_student_sessions
iap_students (1) ──→ (1) iap_psychometric_scores
```

---

## Default Data

### Admin User
- Username: admin
- Email: admin@example.com
- Password: (bcrypt hash)
- Role: admin

### Sample Students
- 2021001 - Test Student
- 2021002 - Jane Smith
- 2021003 - Bob Johnson
- 2021004 - Alice Brown

Default Password: student@IAP

### Sample Sessions
- 01SN01 - Introduction to Engineering Careers (Year 1)
- 01SN02 - How to Ace Ideathons (Year 1)
- 02SN01 - Resume Building and Career Positioning (Year 2)
- 02SN02 - Interview Preparation Fundamentals (Year 2)
- 03SN01 - Internship Readiness Program (Year 3)
- 03SN02 - Advanced System Design (Year 3)
- 04SN01 - Startup Ecosystem and Entrepreneurship (Year 4)
- 04SN02 - Leadership and Management Skills (Year 4)

---

## Session Code Format

### Auto-Generated Format
```
YYSNNN
```
- YY = Year code (01, 02, 03, 04)
- SN = Session Number prefix
- NNN = Sequential number (01, 02, 03, etc.)

Examples: 01SN01, 01SN02, 02SN01, 03SN05

### Manual Format
```
ALPHANUMERIC-SESSIONNAME
```
Examples: CS101-Introduction to Programming, AI202-Artificial Intelligence

---

## Files

### Master Schema
- `iap_master_database.sql` - Complete database setup

### Documentation
- `README.md` - This file
- `REFACTORING_SUMMARY.md` - Refactoring overview
- `IMPLEMENTATION_CHECKLIST.md` - Deployment checklist
- `CHANGES_DETAILED.md` - Detailed changes

### Archive (Old Files)
- `archive/COMPLETE_SETUP_SQL.sql`
- `archive/final_database.sql`
- `archive/student_migration.sql`
- `archive/db.sql`
- `archive/SESSION_CODE_MIGRATION.sql`

---

## Common Queries

### View All Students
```sql
SELECT id, roll_number, full_name, email, year FROM iap_students;
```

### View All Sessions
```sql
SELECT id, session_code, topic, year FROM iap_sessions;
```

### View Student Registrations
```sql
SELECT 
    s.roll_number,
    s.full_name,
    sess.session_code,
    sess.topic,
    ss.registration_status
FROM iap_student_sessions ss
JOIN iap_students s ON ss.student_id = s.id
JOIN iap_sessions sess ON ss.session_id = sess.id
ORDER BY s.roll_number;
```

### View Psychometric Scores
```sql
SELECT 
    s.roll_number,
    s.full_name,
    ps.score,
    ps.trait_a,
    ps.trait_b,
    ps.trait_c,
    ps.trait_d
FROM iap_psychometric_scores ps
JOIN iap_students s ON ps.student_id = s.id;
```

---

## Backup & Restore

### Backup Database
```bash
mysqldump -u root -p iap_portal > backup_iap_portal_$(date +%Y%m%d).sql
```

### Restore Database
```bash
mysql -u root -p iap_portal < backup_iap_portal_YYYYMMDD.sql
```

---

## Troubleshooting

### Connection Issues
```bash
# Test connection
mysql -u root -p -e "SELECT 1;"

# Check database exists
mysql -u root -p -e "SHOW DATABASES;"
```

### Table Issues
```sql
-- Check table exists
SHOW TABLES;

-- Check table structure
DESCRIBE iap_sessions;

-- Check table size
SELECT table_name, ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
FROM information_schema.TABLES
WHERE table_schema = 'iap_portal';
```

### Query Issues
```sql
-- Check for errors
SHOW ERRORS;

-- Check for warnings
SHOW WARNINGS;

-- Check query execution plan
EXPLAIN SELECT * FROM iap_sessions;
```

---

## Performance Tips

### Indexes
All tables have appropriate indexes for common queries.

### Query Optimization
- Use prepared statements (already implemented)
- Use LIMIT for large result sets
- Use indexes for WHERE clauses
- Avoid SELECT *

### Maintenance
- Regular backups
- Monitor query performance
- Check table sizes
- Optimize tables periodically

```sql
-- Optimize all tables
OPTIMIZE TABLE iap_sessions;
OPTIMIZE TABLE iap_students;
OPTIMIZE TABLE iap_student_sessions;
```

---

## Security

### Password Hashing
All passwords use bcrypt (PASSWORD_BCRYPT)

### SQL Injection Prevention
All queries use prepared statements with parameter binding

### Access Control
- Admin users: Full access
- Students: Limited to own data
- Public: Registration only

### Data Protection
- Unique constraints on sensitive fields
- Foreign key constraints
- Referential integrity
- Audit trails (created_at, updated_at)

---

## Maintenance

### Regular Tasks
- [ ] Daily: Monitor error logs
- [ ] Weekly: Check database size
- [ ] Monthly: Optimize tables
- [ ] Quarterly: Review security
- [ ] Annually: Archive old data

### Backup Schedule
- Daily: Automated backups
- Weekly: Manual verification
- Monthly: Off-site backup
- Yearly: Archive backup

---

## Support

### Documentation
- See REFACTORING_SUMMARY.md for overview
- See IMPLEMENTATION_CHECKLIST.md for deployment
- See CHANGES_DETAILED.md for technical details

### Common Issues
1. Connection failed → Check credentials and database exists
2. Table not found → Check table name (use iap_ prefix)
3. Foreign key error → Check referenced record exists
4. Duplicate key error → Check unique constraints

### Contact
For issues or questions, refer to the documentation files or contact the development team.

---

## Version History

### v1.0 (Current)
- ✅ Database refactoring complete
- ✅ All tables standardized with iap_ prefix
- ✅ Master SQL file created
- ✅ All PHP files updated
- ✅ Documentation complete

---

## License & Attribution

This database schema is part of the IAP Portal project.
All rights reserved.

---

**Last Updated:** 2024
**Status:** Production Ready
**Verified:** Yes

