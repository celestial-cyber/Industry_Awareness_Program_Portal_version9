# Session Code Feature - Quick Setup Guide

## ⚡ 5-Minute Setup

### Step 1: Database Migration (1 minute)
Run these SQL commands in your MySQL client:

```sql
-- Add session_code column
ALTER TABLE sessions ADD COLUMN session_code VARCHAR(20) NULL AFTER id;

-- Add unique constraint
ALTER TABLE sessions ADD UNIQUE KEY uq_sessions_session_code (session_code);

-- Verify
SHOW COLUMNS FROM sessions LIKE 'session_code';
```

**Status:** ✅ Already implemented in `Admin/admin_dashboard.php` (auto-runs on page load)

---

### Step 2: Verify Code is Working (2 minutes)

#### Test 1: Create a Session
1. Go to Admin Dashboard → Create Session
2. Enter:
   - Topic: "Test Session"
   - Year: "1"
3. Click "Create Session"
4. **Expected:** Message shows "Session created successfully! Code: 01SN01"

#### Test 2: View Session Code
1. Go to Admin Dashboard → Registered Students
2. Look for "registered_sessions" column
3. **Expected:** Shows "01SN01 - Test Session"

#### Test 3: Student Dashboard
1. Login as student (Roll: 2021001, Password: student@IAP)
2. Go to Student Dashboard
3. **Expected:** Sessions show as "01SN01 - Session Title"

---

### Step 3: Verify Backward Compatibility (1 minute)

#### Check Existing Sessions
```sql
-- View all sessions
SELECT id, session_code, topic, year FROM sessions;

-- Should show:
-- id | session_code | topic | year
-- 1  | 01SN01       | ... | 1
-- 2  | 01SN02       | ... | 1
-- etc.
```

#### If Sessions Missing Codes
The system auto-backfills on admin dashboard load. If needed, manually run:

```php
// In Admin/admin_dashboard.php, this is called automatically:
backfill_session_codes($conn);
```

---

### Step 4: Test Dropdowns (1 minute)

#### Session Selection Dropdown
When selecting a session, dropdown should show:
```
-- Select Session --
01SN01 - Introduction to Engineering Careers
01SN02 - How to Ace Ideathons
02SN01 - Resume Building and Career Positioning
```

**Value stored:** Session ID (not code)
**Display:** Session Code + Title

---

## 📋 Implementation Checklist

### Database
- [ ] ALTER TABLE sessions ADD COLUMN session_code
- [ ] ALTER TABLE sessions ADD UNIQUE KEY
- [ ] Verify column exists
- [ ] Verify unique constraint exists

### Admin Dashboard
- [ ] Create new session
- [ ] Verify code auto-generates (format: YYSNNN)
- [ ] View session in registered students list
- [ ] Verify code displays with title

### Student Dashboard
- [ ] Login as student
- [ ] View registered sessions
- [ ] Verify code displays with title
- [ ] Register for new session
- [ ] Verify new session shows code

### Backward Compatibility
- [ ] Check existing sessions have codes
- [ ] Verify sessions without codes still display
- [ ] Test fallback display (title only if no code)

### Dropdowns
- [ ] Session selection shows codes
- [ ] Dropdown value is session ID
- [ ] Display format is "CODE - TITLE"

---

## 🔍 Verification Queries

### Check All Sessions
```sql
SELECT id, session_code, topic, year FROM sessions ORDER BY year, session_code;
```

### Check Sessions Without Codes
```sql
SELECT id, topic, year FROM sessions WHERE session_code IS NULL OR session_code = '';
```

### Check for Duplicates
```sql
SELECT session_code, COUNT(*) as count FROM sessions GROUP BY session_code HAVING count > 1;
```

### View Student Registrations
```sql
SELECT 
    s.roll_number,
    s.full_name,
    sess.session_code,
    sess.topic
FROM iap_student_sessions ss
JOIN iap_students s ON ss.student_id = s.id
JOIN sessions sess ON ss.session_id = sess.id
ORDER BY s.roll_number;
```

---

## 📊 Session Code Format

### Pattern
```
YYSNNN
```

### Breakdown
- `YY` = Year code (01, 02, 03, 04)
- `SN` = Session Number prefix
- `NNN` = Sequential number (01, 02, 03, etc.)

### Examples
```
01SN01 - Year 1, Session 1
01SN02 - Year 1, Session 2
02SN01 - Year 2, Session 1
03SN05 - Year 3, Session 5
04SN03 - Year 4, Session 3
```

---

## 🎯 Key Features

✅ **Auto-Generated** - No manual entry needed
✅ **Unique** - Each session has unique code
✅ **Consistent Format** - YYSNNN pattern
✅ **Backward Compatible** - Works with existing sessions
✅ **No Breaking Changes** - All existing functionality preserved
✅ **Mobile Responsive** - Works on all devices
✅ **AdminLTE Styled** - Matches existing UI

---

## 🚀 Files Already Updated

| File | Status | Changes |
|------|--------|---------|
| Admin/admin_dashboard.php | ✅ Complete | Schema, functions, form, queries |
| Student/student_dashboard.php | ✅ Complete | Schema detection, functions, queries |
| common/db.php | ✅ No changes | - |
| index.php | ✅ No changes | - |

---

## ⚠️ Troubleshooting

### Issue: Code not showing in dropdown
**Solution:** Verify `session_code` column exists
```sql
SHOW COLUMNS FROM sessions LIKE 'session_code';
```

### Issue: Duplicate code error
**Solution:** Check unique constraint
```sql
SHOW INDEX FROM sessions WHERE Key_name = 'uq_sessions_session_code';
```

### Issue: Old sessions without codes
**Solution:** Backfill codes (auto-runs on admin dashboard load)
```sql
-- Or manually check:
SELECT id, topic FROM sessions WHERE session_code IS NULL;
```

### Issue: Code format incorrect
**Solution:** Verify `generate_next_session_code()` function is called
- Check Admin/admin_dashboard.php line 330-340

---

## 📞 Support

### Quick Reference
- **Column:** `session_code VARCHAR(20)`
- **Format:** `YYSNNN` (e.g., 01SN01)
- **Display:** `01SN01 - Session Title`
- **Auto-generated:** Yes
- **Unique:** Yes
- **Nullable:** Yes (backward compatible)

### Key Functions
- `year_to_code($year)` - Convert year to code
- `generate_next_session_code($conn, $year)` - Generate code
- `backfill_session_codes($conn)` - Backfill existing sessions
- `session_display_label($session)` - Format display

---

## ✨ What's Included

### Database
- ✅ session_code column (VARCHAR(20))
- ✅ Unique constraint
- ✅ Idempotent migration

### Admin Features
- ✅ Auto-generate codes on session creation
- ✅ Display codes in admin dashboard
- ✅ View codes in student registrations

### Student Features
- ✅ View codes in dashboard
- ✅ See codes in session listings
- ✅ Codes in dropdowns

### Compatibility
- ✅ Backward compatible
- ✅ Fallback for missing codes
- ✅ No breaking changes
- ✅ Mobile responsive

---

## 🎓 Example Workflow

### Admin Creates Session
1. Admin goes to "Create Session"
2. Enters Topic: "Introduction to Engineering Careers"
3. Selects Year: "1"
4. Clicks "Create Session"
5. **System auto-generates:** `01SN01`
6. **Message:** "Session created successfully! Code: 01SN01"

### Student Views Session
1. Student logs in
2. Goes to Dashboard
3. **Sees:** "01SN01 - Introduction to Engineering Careers"
4. Clicks "Take Quiz"
5. Takes quiz for session 01SN01

### Admin Views Registrations
1. Admin goes to "Registered Students"
2. **Sees:** "01SN01 - Introduction to Engineering Careers, 01SN02 - How to Ace Ideathons"
3. Can track which sessions students are registered for

---

## 📝 Notes

- Session codes are **automatically generated** when creating sessions
- Codes follow a **consistent format** (YYSNNN)
- Codes are **unique** per session
- System is **backward compatible** with existing sessions
- **No manual intervention** needed for code generation
- **Mobile responsive** - works on all devices
- **AdminLTE styled** - matches existing UI

---

## ✅ Ready to Go!

The Session Code feature is **production-ready** and fully integrated. No additional setup needed beyond running the database migration.

**Next Steps:**
1. Run the SQL migration
2. Test creating a session
3. Verify code displays in student dashboard
4. Done! 🎉

