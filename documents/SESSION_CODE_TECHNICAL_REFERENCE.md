# Session Code Feature - Technical Reference

## Complete Code Implementation

---

## 1. DATABASE MIGRATION

### SQL Commands
```sql
-- Step 1: Add session_code column
ALTER TABLE sessions ADD COLUMN session_code VARCHAR(20) NULL AFTER id;

-- Step 2: Add unique constraint
ALTER TABLE sessions ADD UNIQUE KEY uq_sessions_session_code (session_code);

-- Step 3: Verify column was added
SHOW COLUMNS FROM sessions LIKE 'session_code';

-- Step 4: Check unique constraint
SHOW INDEX FROM sessions WHERE Key_name = 'uq_sessions_session_code';
```

### Idempotent Migration (Already in Admin/admin_dashboard.php)
```php
// Add structured session code column (idempotent migration).
$session_code_column_check = $conn->query("SHOW COLUMNS FROM sessions LIKE 'session_code'");
if ($session_code_column_check && $session_code_column_check->num_rows === 0) {
    $conn->query("ALTER TABLE sessions ADD COLUMN session_code VARCHAR(20) NULL AFTER id");
}

// Add unique constraint
$session_code_unique_check = $conn->query("SHOW INDEX FROM sessions WHERE Key_name = 'uq_sessions_session_code'");
if ($session_code_unique_check && $session_code_unique_check->num_rows === 0) {
    $conn->query("ALTER TABLE sessions ADD UNIQUE KEY uq_sessions_session_code (session_code)");
}
```

---

## 2. HELPER FUNCTIONS

### Location: Admin/admin_dashboard.php (Lines 210-280)

#### Function 1: year_to_code()
```php
/**
 * Normalize year text into YY code used by session codes.
 */
function year_to_code(string $year): string
{
    $v = strtolower(trim($year));
    if (preg_match('/([1-4])/', $v, $m)) {
        return str_pad($m[1], 2, '0', STR_PAD_LEFT);
    }
    return '00';
}
```

**Usage:**
```php
year_to_code('1')        // Returns '01'
year_to_code('Year 1')   // Returns '01'
year_to_code('2')        // Returns '02'
year_to_code('Graduate') // Returns '00'
```

#### Function 2: generate_next_session_code()
```php
/**
 * Generate next session code for a given year.
 * Format: YYSNNN (e.g. 01SN01, 02SN03)
 */
function generate_next_session_code(mysqli $conn, string $year): string
{
    $yy = year_to_code($year);
    $prefix = $yy . 'SN';

    $sql = "SELECT session_code FROM sessions
            WHERE session_code LIKE CONCAT(?, '%')
            ORDER BY session_code DESC
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $prefix . '01';
    }
    $stmt->bind_param("s", $prefix);
    $stmt->execute();
    $res = $stmt->get_result();
    $last = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    $next_num = 1;
    if ($last && !empty($last['session_code']) && preg_match('/SN(\d{2})$/', $last['session_code'], $m)) {
        $next_num = intval($m[1]) + 1;
    }
    return $prefix . str_pad((string)$next_num, 2, '0', STR_PAD_LEFT);
}
```

**Usage:**
```php
$code = generate_next_session_code($conn, '1');
// Returns: '01SN01' (or next available for Year 1)

$code = generate_next_session_code($conn, '2');
// Returns: '02SN01' (or next available for Year 2)
```

#### Function 3: backfill_session_codes()
```php
/**
 * One-time/backfill: generate missing session_code for existing sessions,
 * grouped by year and ordered by id ascending.
 */
function backfill_session_codes(mysqli $conn): void
{
    $query = "SELECT id, year FROM sessions WHERE session_code IS NULL OR session_code = '' ORDER BY year ASC, id ASC";
    $result = $conn->query($query);
    if (!$result) {
        return;
    }

    $year_counters = [];
    while ($row = $result->fetch_assoc()) {
        $session_id = (int)$row['id'];
        $year = (string)$row['year'];
        $yy = year_to_code($year);
        if ($yy === '00') {
            continue;
        }
        if (!isset($year_counters[$yy])) {
            $count_sql = "SELECT COUNT(*) AS cnt FROM sessions WHERE session_code LIKE CONCAT(?, '%')";
            $count_stmt = $conn->prepare($count_sql);
            if ($count_stmt) {
                $prefix = $yy . 'SN';
                $count_stmt->bind_param("s", $prefix);
                $count_stmt->execute();
                $cnt_res = $count_stmt->get_result();
                $cnt_row = $cnt_res ? $cnt_res->fetch_assoc() : ['cnt' => 0];
                $year_counters[$yy] = intval($cnt_row['cnt']);
                $count_stmt->close();
            } else {
                $year_counters[$yy] = 0;
            }
        }
        $year_counters[$yy]++;
        $session_code = $yy . 'SN' . str_pad((string)$year_counters[$yy], 2, '0', STR_PAD_LEFT);

        $upd = $conn->prepare("UPDATE sessions SET session_code = ? WHERE id = ?");
        if ($upd) {
            $upd->bind_param("si", $session_code, $session_id);
            $upd->execute();
            $upd->close();
        }
    }
}
```

**Usage:**
```php
// Called automatically on admin dashboard load
backfill_session_codes($conn);
// Assigns codes to all sessions without codes
```

---

## 3. CREATE SESSION FORM

### Location: Admin/admin_dashboard.php (Lines 1776-1795)

#### HTML Form
```html
<?php elseif ($page == 'create_session'): ?>
    <h2 class="section-title">Create New Session</h2>
    <form method="post" action="">
        <div class="form-group">
            <label for="topic">Topic:</label>
            <input type="text" id="topic" name="topic" required>
        </div>
        <div class="form-group">
            <label for="year">Year:</label>
            <select id="year" name="year" required>
                <option value="1">Year 1</option>
                <option value="2">Year 2</option>
                <option value="3">Year 3</option>
                <option value="4">Year 4</option>
                <option value="Graduate">Graduate</option>
            </select>
        </div>
        <button type="submit" name="create_session" class="btn">Create Session</button>
    </form>
<?php endif; ?>
```

#### Backend Processing
```php
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_session'])) {
    $topic = $_POST['topic'];
    $year = $_POST['year'];

    // Auto-generate session code
    $new_session_code = generate_next_session_code($conn, (string)$year);
    
    // Insert with session_code
    $sql = "INSERT INTO sessions (session_code, topic, year) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $new_session_code, $topic, $year);
    
    if ($stmt->execute()) {
        $message = "Session created successfully! Code: " . htmlspecialchars($new_session_code);
    } else {
        $message = "Error: " . $conn->error;
    }
    $stmt->close();
}
```

---

## 4. DISPLAY FUNCTIONS

### Location: Student/student_dashboard.php (Lines 69-110)

#### Function: session_display_label()
```php
/**
 * Build display label as "session_code - title" when code exists.
 */
function session_display_label(array $session): string
{
    $code = trim((string)($session['session_code'] ?? ''));
    $title = trim((string)($session['title'] ?? ''));
    return $code !== '' ? ($code . ' - ' . $title) : $title;
}
```

**Usage:**
```php
$session = [
    'session_code' => '01SN01',
    'title' => 'Introduction to Engineering Careers'
];

echo session_display_label($session);
// Output: "01SN01 - Introduction to Engineering Careers"
```

#### Function: year_to_code_for_session()
```php
function year_to_code_for_session(string $year): string
{
    $k = normalize_year_key($year);
    if (in_array($k, ['1', '2', '3', '4'], true)) {
        return str_pad($k, 2, '0', STR_PAD_LEFT);
    }
    return '00';
}
```

#### Function: generate_next_session_code_for_year()
```php
/**
 * Generate next structured session code for a given year.
 */
function generate_next_session_code_for_year(mysqli $conn, string $year): string
{
    $yy = year_to_code_for_session($year);
    $prefix = $yy . 'SN';
    $sql = "SELECT session_code FROM sessions WHERE session_code LIKE CONCAT(?, '%') ORDER BY session_code DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $prefix . '01';
    }
    $stmt->bind_param("s", $prefix);
    $stmt->execute();
    $res = $stmt->get_result();
    $last = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    $n = 1;
    if ($last && !empty($last['session_code']) && preg_match('/SN(\d{2})$/', $last['session_code'], $m)) {
        $n = intval($m[1]) + 1;
    }
    return $prefix . str_pad((string)$n, 2, '0', STR_PAD_LEFT);
}
```

---

## 5. FETCH QUERIES

### Location: Student/student_dashboard.php (Lines 113-130)

#### Query with Schema Detection
```php
// Detect if session_code column exists
$session_code_col_check = $conn->query("SHOW COLUMNS FROM sessions LIKE 'session_code'");
$session_code_exists = ($session_code_col_check && $session_code_col_check->num_rows > 0);

// Build conditional SELECT
$session_code_select = $session_code_exists ? "s.session_code" : "'' AS session_code";

// Fetch student's registered sessions
$sql = "SELECT 
            s.id,
            {$session_code_select},
            s.{$session_title_column} as title,
            s.year,
            {$description_select} as description,
            ss.registration_status,
            ss.registered_at
        FROM sessions s
        JOIN iap_student_sessions ss ON s.id = ss.session_id
        WHERE ss.student_id = ?
        ORDER BY s.year ASC, s.{$session_title_column} ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $_SESSION['student_id']);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $registered_sessions[] = $row;
}
$stmt->close();
```

### Location: Admin/admin_dashboard.php (Lines 380-395)

#### Admin Dashboard Query
```php
$sql = "SELECT DISTINCT
            s.id,
            s.full_name,
            s.email,
            s.roll_number,
            s.department,
            s.year,
            s.password,
            COUNT(DISTINCT ss.session_id) as sessions_count,
            GROUP_CONCAT(DISTINCT CONCAT(
                COALESCE(sess.session_code, ''), 
                CASE WHEN sess.session_code IS NULL OR sess.session_code = '' THEN '' ELSE ' - ' END, 
                sess.{$session_title_column}
            ) SEPARATOR ', ') as registered_sessions,
            COALESCE(ps.score, 0) as iap_psychometric_score,
            CASE WHEN ps.score IS NOT NULL THEN 'Completed' ELSE 'Not Taken' END as assessment_status
        FROM iap_students s
        LEFT JOIN iap_student_sessions ss ON s.id = ss.student_id
        LEFT JOIN sessions sess ON ss.session_id = sess.id
        LEFT JOIN iap_psychometric_scores ps ON s.id = ps.student_id
        GROUP BY s.id
        ORDER BY s.created_at DESC";

$registered_students_result = $conn->query($sql);
```

---

## 6. DISPLAY IN DROPDOWNS

### Example: Session Selection Dropdown
```php
<?php
// Fetch all sessions for dropdown
$sql = "SELECT id, session_code, title FROM sessions WHERE year = ? ORDER BY session_code ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $year);
$stmt->execute();
$result = $stmt->get_result();
?>

<select name="session_id" required>
    <option value="">-- Select Session --</option>
    <?php while ($row = $result->fetch_assoc()): ?>
        <?php
        $display = session_display_label($row);
        $session_id = (int)$row['id'];
        ?>
        <option value="<?php echo $session_id; ?>">
            <?php echo htmlspecialchars($display); ?>
        </option>
    <?php endwhile; ?>
</select>

<?php $stmt->close(); ?>
```

**Output:**
```html
<select name="session_id" required>
    <option value="">-- Select Session --</option>
    <option value="1">01SN01 - Introduction to Engineering Careers</option>
    <option value="2">01SN02 - How to Ace Ideathons</option>
    <option value="3">02SN01 - Resume Building and Career Positioning</option>
</select>
```

---

## 7. BACKWARD COMPATIBILITY

### Schema Detection Pattern
```php
// Check if column exists
$column_check = $conn->query("SHOW COLUMNS FROM sessions LIKE 'session_code'");
$column_exists = ($column_check && $column_check->num_rows > 0);

// Use conditional SELECT
$select_clause = $column_exists ? "s.session_code" : "'' AS session_code";

// Build query with fallback
$sql = "SELECT {$select_clause}, s.title FROM sessions s";
```

### Display Fallback
```php
function session_display_label(array $session): string
{
    $code = trim((string)($session['session_code'] ?? ''));
    $title = trim((string)($session['title'] ?? ''));
    
    // If code exists, show "CODE - TITLE"
    // Otherwise, show just "TITLE"
    return $code !== '' ? ($code . ' - ' . $title) : $title;
}
```

---

## 8. VALIDATION RULES

### Session Code Format
```php
// Validate session code format
$pattern = '/^[0-9]{2}SN[0-9]{2}$/';
if (preg_match($pattern, $session_code)) {
    // Valid format: YYSNNN
} else {
    // Invalid format
}
```

### Unique Constraint
```sql
-- Ensure session_code is unique
ALTER TABLE sessions ADD UNIQUE KEY uq_sessions_session_code (session_code);

-- This prevents duplicate codes
```

---

## 9. ERROR HANDLING

### Insert with Error Handling
```php
$sql = "INSERT INTO sessions (session_code, topic, year) VALUES (?, ?, ?)";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}

$stmt->bind_param("sss", $session_code, $topic, $year);

if (!$stmt->execute()) {
    if ($conn->errno === 1062) {
        // Duplicate session_code
        $message = "Session code already exists. Please try again.";
    } else {
        $message = "Error: " . $conn->error;
    }
} else {
    $message = "Session created successfully! Code: " . htmlspecialchars($session_code);
}

$stmt->close();
```

---

## 10. TESTING QUERIES

### Check Session Codes
```sql
-- View all sessions with codes
SELECT id, session_code, topic, year FROM sessions ORDER BY year, session_code;

-- Find sessions without codes
SELECT id, topic, year FROM sessions WHERE session_code IS NULL OR session_code = '';

-- Check for duplicate codes
SELECT session_code, COUNT(*) as count FROM sessions GROUP BY session_code HAVING count > 1;

-- View sessions by year
SELECT id, session_code, topic FROM sessions WHERE year = '1' ORDER BY session_code;
```

### Check Registrations with Codes
```sql
-- View student registrations with session codes
SELECT 
    s.roll_number,
    s.full_name,
    sess.session_code,
    sess.topic,
    ss.registration_status
FROM iap_student_sessions ss
JOIN iap_students s ON ss.student_id = s.id
JOIN sessions sess ON ss.session_id = sess.id
ORDER BY s.roll_number, sess.session_code;
```

---

## 11. PERFORMANCE CONSIDERATIONS

### Indexes
```sql
-- Existing indexes (already created)
CREATE INDEX idx_year ON sessions(year);
CREATE INDEX idx_created_at ON sessions(created_at);

-- Unique constraint on session_code
ALTER TABLE sessions ADD UNIQUE KEY uq_sessions_session_code (session_code);

-- Consider adding if querying by session_code frequently
CREATE INDEX idx_session_code ON sessions(session_code);
```

### Query Optimization
```php
// Use prepared statements (already implemented)
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $year);
$stmt->execute();

// Avoid N+1 queries - fetch all data in one query
$sql = "SELECT s.*, COUNT(ss.id) as registration_count 
        FROM sessions s 
        LEFT JOIN iap_student_sessions ss ON s.id = ss.session_id 
        GROUP BY s.id";
```

---

## 12. MIGRATION CHECKLIST

- [ ] Run ALTER TABLE to add session_code column
- [ ] Add unique constraint on session_code
- [ ] Verify column exists: `SHOW COLUMNS FROM sessions LIKE 'session_code'`
- [ ] Run backfill function to assign codes to existing sessions
- [ ] Test create session form - verify code is auto-generated
- [ ] Test student dashboard - verify code displays with title
- [ ] Test session dropdown - verify code shows in options
- [ ] Test backward compatibility - sessions without codes still display
- [ ] Verify no breaking changes to existing functionality
- [ ] Test on mobile devices - responsive design maintained

---

## 13. QUICK REFERENCE

| Item | Value |
|------|-------|
| Column Name | `session_code` |
| Column Type | `VARCHAR(20)` |
| Format | `YYSNNN` |
| Example | `01SN01` |
| Unique | Yes |
| Nullable | Yes (for backward compatibility) |
| Auto-generated | Yes |
| Display Format | `01SN01 - Session Title` |

---

## 14. FILES MODIFIED

| File | Changes | Status |
|------|---------|--------|
| Admin/admin_dashboard.php | Schema migration, functions, form, queries | ✅ Complete |
| Student/student_dashboard.php | Schema detection, functions, queries, display | ✅ Complete |
| common/db.php | None | ✅ No changes |
| index.php | None | ✅ No changes |

---

## 15. SUPPORT & TROUBLESHOOTING

### Issue: Session code not generating
**Solution:** Ensure `generate_next_session_code()` function is called before INSERT

### Issue: Duplicate session code error
**Solution:** Check unique constraint exists: `SHOW INDEX FROM sessions WHERE Key_name = 'uq_sessions_session_code'`

### Issue: Old sessions showing without codes
**Solution:** Run `backfill_session_codes($conn)` to assign codes to existing sessions

### Issue: Dropdown not showing codes
**Solution:** Verify `session_code` column exists and `session_display_label()` function is used

