<?php
// api.php - Komplett backend API för månadsrapport
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Stockholm');

// Databaskonfiguration
$db_path = 'shiftplanner.db';

// Öppna databas
function get_db() {
    global $db_path;
    $db = new SQLite3($db_path);
    $db->exec("PRAGMA foreign_keys=ON");
    return $db;
}

// Initiera databas om den inte finns
function init_db() {
    $db = get_db();
    
    // Användare
    $db->exec("
        CREATE TABLE IF NOT EXISTS users(
            id INTEGER PRIMARY KEY,
            name TEXT UNIQUE NOT NULL,
            team TEXT NOT NULL CHECK(team IN ('A','B','C','D')),
            role TEXT NOT NULL CHECK(role IN ('Admin','Användare','Kontrollant')),
            pwd_hash TEXT NOT NULL DEFAULT '',
            must_change_pw INTEGER NOT NULL DEFAULT 0,
            hidden INTEGER NOT NULL DEFAULT 0
        )
    ");
    
    // Inställningar
    $db->exec("
        CREATE TABLE IF NOT EXISTS settings(
            id INTEGER PRIMARY KEY CHECK(id=1),
            start_date TEXT NOT NULL,
            team_week_map TEXT NOT NULL,
            smtp_host TEXT,
            smtp_port INTEGER,
            smtp_ssl INTEGER,
            smtp_user TEXT,
            smtp_pass TEXT,
            team_anchors TEXT
        )
    ");
    
    // Skiftlagsnamn
    $db->exec("
        CREATE TABLE IF NOT EXISTS team_names(
            id INTEGER PRIMARY KEY CHECK(id=1),
            team_a TEXT NOT NULL DEFAULT 'Skiftlag 1',
            team_b TEXT NOT NULL DEFAULT 'Skiftlag 2',
            team_c TEXT NOT NULL DEFAULT 'Skiftlag 3',
            team_d TEXT NOT NULL DEFAULT 'Dagtid'
        )
    ");
    
    // Frånvaro
    $db->exec("
        CREATE TABLE IF NOT EXISTS absences(
            id INTEGER PRIMARY KEY,
            user_id INTEGER NOT NULL,
            work_date TEXT NOT NULL,
            reason TEXT NOT NULL,
            hours REAL NOT NULL,
            UNIQUE(user_id, work_date),
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    
    // Övertid
    $db->exec("
        CREATE TABLE IF NOT EXISTS overtimes(
            id INTEGER PRIMARY KEY,
            user_id INTEGER NOT NULL,
            work_date TEXT NOT NULL,
            mertid REAL NOT NULL DEFAULT 0,
            ot50 REAL NOT NULL DEFAULT 0,
            ot100 REAL NOT NULL DEFAULT 0,
            ot200 REAL NOT NULL DEFAULT 0,
            UNIQUE(user_id, work_date),
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    
    // Rapporter
    $db->exec("
        CREATE TABLE IF NOT EXISTS reports(
            id INTEGER PRIMARY KEY,
            user_id INTEGER NOT NULL,
            year INTEGER NOT NULL,
            month INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            totals_json TEXT NOT NULL,
            changes INTEGER NOT NULL,
            approved_by INTEGER,
            approved_at TEXT,
            UNIQUE(user_id, year, month),
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(approved_by) REFERENCES users(id) ON DELETE SET NULL
        )
    ");
    
    // Daganteckningar
    $db->exec("
        CREATE TABLE IF NOT EXISTS day_notes(
            id INTEGER PRIMARY KEY,
            user_id INTEGER NOT NULL,
            work_date TEXT NOT NULL,
            note TEXT NOT NULL DEFAULT '',
            UNIQUE(user_id, work_date),
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    
    // Manuella arbetstimmar
    $db->exec("
        CREATE TABLE IF NOT EXISTS manual_hours(
            id INTEGER PRIMARY KEY,
            user_id INTEGER NOT NULL,
            work_date TEXT NOT NULL,
            hours REAL NOT NULL DEFAULT 0,
            UNIQUE(user_id, work_date),
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    
    // Skiftöverrides
    $db->exec("
        CREATE TABLE IF NOT EXISTS shift_overrides(
            id INTEGER PRIMARY KEY,
            user_id INTEGER NOT NULL,
            work_date TEXT NOT NULL,
            shift_type TEXT NOT NULL CHECK(shift_type IN ('DAG','NATT')),
            UNIQUE(user_id, work_date),
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    
    // Vanliga fraser
    $db->exec("
        CREATE TABLE IF NOT EXISTS common_phrases(
            id INTEGER PRIMARY KEY,
            phrase TEXT NOT NULL UNIQUE
        )
    ");
    
    // Skapa standardinställningar
    $result = $db->query("SELECT COUNT(*) as count FROM settings");
    $row = $result->fetchArray();
    if ($row['count'] == 0) {
        $start_date = date('Y-m-d', strtotime('first monday of january ' . date('Y')));
        $team_week_map = json_encode(['W1' => 'A', 'W2' => 'B', 'W3' => 'C']);
        $team_anchors = json_encode([
            'A' => $start_date,
            'B' => date('Y-m-d', strtotime($start_date . ' +7 days')),
            'C' => date('Y-m-d', strtotime($start_date . ' +14 days'))
        ]);
        
        $db->exec("
            INSERT INTO settings(id, start_date, team_week_map, smtp_host, smtp_port, smtp_ssl, smtp_user, smtp_pass, team_anchors)
            VALUES(1, '$start_date', '$team_week_map', '', 587, 0, '', '', '$team_anchors')
        ");
    }
    
    // Skapa standardnamn för skiftlag
    $result = $db->query("SELECT COUNT(*) as count FROM team_names");
    $row = $result->fetchArray();
    if ($row['count'] == 0) {
        $db->exec("
            INSERT INTO team_names(id, team_a, team_b, team_c, team_d)
            VALUES(1, 'Skiftlag 1', 'Skiftlag 2', 'Skiftlag 3', 'Dagtid')
        ");
    }
    
    // Skapa admin om ingen användare finns
    $result = $db->query("SELECT COUNT(*) as count FROM users");
    $row = $result->fetchArray();
    if ($row['count'] == 0) {
        $db->exec("
            INSERT INTO users(name, team, role, pwd_hash, must_change_pw) 
            VALUES('Admin', 'A', 'Admin', 'admin123', 1)
        ");
    }
    
    $db->close();
}

// Initiera databasen
init_db();

// Svenska helgdagar
function is_red_day($date_str) {
    $date = new DateTime($date_str);
    $year = (int)$date->format('Y');
    $month = (int)$date->format('m');
    $day = (int)$date->format('d');
    $weekday = (int)$date->format('N');
    
    $fixed_holidays = [
        '01-01', '01-06', '05-01', '06-06', '12-24', '12-25', '12-26', '12-31',
    ];
    
    $date_md = sprintf('%02d-%02d', $month, $day);
    if (in_array($date_md, $fixed_holidays)) {
        return true;
    }
    
    $easter = easter_date($year);
    $easter_dt = new DateTime();
    $easter_dt->setTimestamp($easter);
    
    $good_friday = clone $easter_dt;
    $good_friday->modify('-2 days');
    
    $easter_monday = clone $easter_dt;
    $easter_monday->modify('+1 day');
    
    $ascension = clone $easter_dt;
    $ascension->modify('+39 days');
    
    $pentecost = clone $easter_dt;
    $pentecost->modify('+49 days');
    
    $movable_holidays = [
        $good_friday->format('Y-m-d'),
        $easter_dt->format('Y-m-d'),
        $easter_monday->format('Y-m-d'),
        $ascension->format('Y-m-d'),
        $pentecost->format('Y-m-d'),
    ];
    
    if (in_array($date_str, $movable_holidays)) {
        return true;
    }
    
    if ($month == 6 && $day >= 19 && $day <= 25 && $weekday == 5) {
        return true;
    }
    
    if ($month == 10 && $day == 31 && $weekday == 6) {
        return true;
    }
    if ($month == 11 && $day <= 6 && $weekday == 6) {
        return true;
    }
    
    return false;
}

function get_shift_pattern($team, $date_str, $settings) {
    if ($team == 'D') {
        $date = new DateTime($date_str);
        $weekday = (int)$date->format('N');
        return ($weekday >= 1 && $weekday <= 5) ? 'DAG' : '';
    }
    
    $team_anchors = json_decode($settings['team_anchors'], true);
    $anchor_date_str = $team_anchors[$team] ?? $settings['start_date'];
    
    $anchor = new DateTime($anchor_date_str);
    $current = new DateTime($date_str);
    
    $diff = $anchor->diff($current);
    $days_diff = (int)$diff->format('%r%a');
    
    $week_index = (int)(floor($days_diff / 7) % 3);
    if ($week_index < 0) $week_index = (3 + $week_index) % 3;
    
    $weekday = (int)$current->format('N');
    
    if ($week_index == 0) {
        if ($weekday == 1) return 'DAG';
        if ($weekday == 2) return 'DAG';
        if ($weekday == 3) return 'NATT';
        if ($weekday == 4) return 'NATT';
    }
    
    if ($week_index == 1) {
        if ($weekday == 3) return 'DAG';
        if ($weekday == 4) return 'DAG';
        if ($weekday == 5) return 'DAG';
    }
    
    if ($week_index == 2) {
        if ($weekday == 1) return 'NATT';
        if ($weekday == 2) return 'NATT';
    }
    
    return '';
}

function get_planned_hours($team, $shift_label) {
    if ($team == 'D') {
        return ($shift_label == 'DAG') ? 8.0 : 0.0;
    }
    
    if ($shift_label == 'DAG' || $shift_label == 'NATT') {
        return 12.0;
    }
    
    return 0.0;
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'login':
        handle_login();
        break;
    case 'list_users':
        handle_list_users();
        break;
    case 'list_all_users':
        handle_list_all_users();
        break;
    case 'get_user':
        handle_get_user();
        break;
    case 'create_user':
        handle_create_user();
        break;
    case 'update_user':
        handle_update_user();
        break;
    case 'delete_user':
        handle_delete_user();
        break;
    case 'get_schedule':
        handle_get_schedule();
        break;
    case 'save_absence':
        handle_save_absence();
        break;
    case 'save_overtime':
        handle_save_overtime();
        break;
    case 'save_note':
        handle_save_note();
        break;
    case 'save_report':
        handle_save_report();
        break;
    case 'list_reports':
        handle_list_reports();
        break;
    case 'approve_report':
        handle_approve_report();
        break;
    case 'get_statistics':
        handle_get_statistics();
        break;
    case 'get_company_statistics':
        handle_get_company_statistics();
        break;
    case 'get_settings':
        handle_get_settings();
        break;
    case 'save_settings':
        handle_save_settings();
        break;
    case 'get_team_names':
        handle_get_team_names();
        break;
    case 'save_team_names':
        handle_save_team_names();
        break;
    case 'get_phrases':
        handle_get_phrases();
        break;
    case 'add_phrase':
        handle_add_phrase();
        break;
    case 'delete_phrase':
        handle_delete_phrase();
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Okänd åtgärd: ' . $action]);
}

function handle_login() {
    $data = json_decode(file_get_contents('php://input'), true);
    $username = $data['username'] ?? '';
    $password = $data['password'] ?? '';
    
    $db = get_db();
    $stmt = $db->prepare("SELECT id, name, team, role, pwd_hash, hidden FROM users WHERE name=?");
    $stmt->bindValue(1, $username, SQLITE3_TEXT);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'Användaren finns inte']);
        $db->close();
        return;
    }
    
    if ($user['pwd_hash'] !== $password) {
        echo json_encode(['success' => false, 'error' => 'Felaktigt lösenord']);
        $db->close();
        return;
    }
    
    unset($user['pwd_hash']);
    echo json_encode([
        'success' => true,
        'user' => $user
    ]);
    
    $db->close();
}

function handle_list_users() {
    $db = get_db();
    $result = $db->query("SELECT id, name, team, role, hidden FROM users WHERE hidden=0 ORDER BY name");
    
    $users = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $users[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'users' => $users
    ]);
    
    $db->close();
}

function handle_list_all_users() {
    $db = get_db();
    $result = $db->query("SELECT id, name, team, role, hidden FROM users ORDER BY name");
    
    $users = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $users[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'users' => $users
    ]);
    
    $db->close();
}

function handle_get_user() {
    $user_id = $_GET['user_id'] ?? 0;
    
    $db = get_db();
    $stmt = $db->prepare("SELECT id, name, team, role, hidden FROM users WHERE id=?");
    $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'Användaren finns inte']);
        $db->close();
        return;
    }
    
    echo json_encode([
        'success' => true,
        'user' => $user
    ]);
    
    $db->close();
}

function handle_create_user() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $name = $data['name'] ?? '';
    $team = $data['team'] ?? 'A';
    $role = $data['role'] ?? 'Användare';
    $password = $data['password'] ?? '';
    $hidden = isset($data['hidden']) && $data['hidden'] ? 1 : 0;
    
    if (!$name || !$password) {
        echo json_encode(['success' => false, 'error' => 'Namn och lösenord måste anges']);
        return;
    }
    
    $db = get_db();
    
    $stmt = $db->prepare("SELECT id FROM users WHERE name=?");
    $stmt->bindValue(1, $name, SQLITE3_TEXT);
    $result = $stmt->execute();
    if ($result->fetchArray()) {
        echo json_encode(['success' => false, 'error' => 'Användarnamnet finns redan']);
        $db->close();
        return;
    }
    
    $stmt = $db->prepare("INSERT INTO users(name, team, role, pwd_hash, must_change_pw, hidden) VALUES(?,?,?,?,1,?)");
    $stmt->bindValue(1, $name, SQLITE3_TEXT);
    $stmt->bindValue(2, $team, SQLITE3_TEXT);
    $stmt->bindValue(3, $role, SQLITE3_TEXT);
    $stmt->bindValue(4, $password, SQLITE3_TEXT);
    $stmt->bindValue(5, $hidden, SQLITE3_INTEGER);
    $stmt->execute();
    
    echo json_encode(['success' => true]);
    
    $db->close();
}

function handle_update_user() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $user_id = $data['user_id'] ?? 0;
    $name = $data['name'] ?? '';
    $team = $data['team'] ?? '';
    $role = $data['role'] ?? '';
    $password = $data['password'] ?? '';
    $hidden = isset($data['hidden']) && $data['hidden'] ? 1 : 0;
    
    if (!$user_id) {
        echo json_encode(['success' => false, 'error' => 'Användar-ID saknas']);
        return;
    }
    
    $db = get_db();
    
    $updates = [];
    $values = [];
    
    if ($name) {
        $updates[] = "name=?";
        $values[] = [$name, SQLITE3_TEXT];
    }
    
    if ($team) {
        $updates[] = "team=?";
        $values[] = [$team, SQLITE3_TEXT];
    }
    
    if ($role) {
        $updates[] = "role=?";
        $values[] = [$role, SQLITE3_TEXT];
    }
    
    if ($password) {
        $updates[] = "pwd_hash=?";
        $values[] = [$password, SQLITE3_TEXT];
        $updates[] = "must_change_pw=1";
    }
    
    $updates[] = "hidden=?";
    $values[] = [$hidden, SQLITE3_INTEGER];
    
    if (empty($updates)) {
        echo json_encode(['success' => false, 'error' => 'Inget att uppdatera']);
        $db->close();
        return;
    }
    
    $sql = "UPDATE users SET " . implode(", ", $updates) . " WHERE id=?";
    $stmt = $db->prepare($sql);
    
    $i = 1;
    foreach ($values as $val) {
        $stmt->bindValue($i++, $val[0], $val[1]);
    }
    $stmt->bindValue($i, $user_id, SQLITE3_INTEGER);
    
    $stmt->execute();
    
    echo json_encode(['success' => true]);
    
    $db->close();
}

function handle_delete_user() {
    $data = json_decode(file_get_contents('php://input'), true);
    $user_id = $data['user_id'] ?? 0;
    
    if (!$user_id) {
        echo json_encode(['success' => false, 'error' => 'Användar-ID saknas']);
        return;
    }
    
    $db = get_db();
    
    $stmt = $db->prepare("DELETE FROM users WHERE id=?");
    $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
    $stmt->execute();
    
    echo json_encode(['success' => true]);
    
    $db->close();
}

function handle_get_schedule() {
    $user_id = $_GET['user_id'] ?? 0;
    $year = $_GET['year'] ?? date('Y');
    $month = $_GET['month'] ?? date('m');
    $show_empty = isset($_GET['show_empty']) && $_GET['show_empty'] == '1';
    
    if (!$user_id) {
        echo json_encode(['success' => false, 'error' => 'Användar-ID saknas']);
        return;
    }
    
    $db = get_db();
    
    $stmt = $db->prepare("SELECT team FROM users WHERE id=?");
    $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'Användaren finns inte']);
        $db->close();
        return;
    }
    
    $team = $user['team'];
    
    $result = $db->query("SELECT start_date, team_anchors FROM settings WHERE id=1");
    $settings_row = $result->fetchArray(SQLITE3_ASSOC);
    $settings = [
        'start_date' => $settings_row['start_date'],
        'team_anchors' => $settings_row['team_anchors']
    ];
    
    $first_day = new DateTime("$year-$month-01");
    $last_day = clone $first_day;
    $last_day->modify('last day of this month');
    
    $schedule = [];
    $current = clone $first_day;
    
    $weekdays_sv = ['', 'Måndag', 'Tisdag', 'Onsdag', 'Torsdag', 'Fredag', 'Lördag', 'Söndag'];
    
    while ($current <= $last_day) {
        $date_str = $current->format('Y-m-d');
        $weekday_num = (int)$current->format('N');
        $is_weekend = ($weekday_num >= 6);
        $is_red = is_red_day($date_str);
        
        $shift_label = get_shift_pattern($team, $date_str, $settings);
        $planned_hours = get_planned_hours($team, $shift_label);
        
        $stmt = $db->prepare("SELECT shift_type FROM shift_overrides WHERE user_id=? AND work_date=?");
        $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
        $stmt->bindValue(2, $date_str, SQLITE3_TEXT);
        $override_result = $stmt->execute();
        $override = $override_result->fetchArray(SQLITE3_ASSOC);
        
        if ($override) {
            $shift_label = $override['shift_type'];
            $planned_hours = get_planned_hours($team, $shift_label);
        }
        
        $stmt = $db->prepare("SELECT reason, hours FROM absences WHERE user_id=? AND work_date=?");
        $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
        $stmt->bindValue(2, $date_str, SQLITE3_TEXT);
        $absence_result = $stmt->execute();
        $absence = $absence_result->fetchArray(SQLITE3_ASSOC);
        
        $absence_reason = $absence ? $absence['reason'] : '';
        $absence_hours_raw = $absence ? (float)$absence['hours'] : 0.0;
        
        $leave_reasons = ['Semester', 'ATK', 'Komp'];
        $is_leave = in_array($absence_reason, $leave_reasons);
        $absence_hours = $is_leave ? 0.0 : $absence_hours_raw;
        $leave_hours = $is_leave ? $absence_hours_raw : 0.0;
        
        $stmt = $db->prepare("SELECT mertid, ot50, ot100, ot200 FROM overtimes WHERE user_id=? AND work_date=?");
        $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
        $stmt->bindValue(2, $date_str, SQLITE3_TEXT);
        $overtime_result = $stmt->execute();
        $overtime = $overtime_result->fetchArray(SQLITE3_ASSOC);
        
        $mertid = $overtime ? (float)$overtime['mertid'] : 0.0;
        $ot50 = $overtime ? (float)$overtime['ot50'] : 0.0;
        $ot100 = $overtime ? (float)$overtime['ot100'] : 0.0;
        $ot200 = $overtime ? (float)$overtime['ot200'] : 0.0;
        
        $has_overtime = ($mertid > 0 || $ot50 > 0 || $ot100 > 0 || $ot200 > 0);
        
        if ($is_red && $planned_hours > 0) {
            if ($has_overtime) {
                $planned_hours = ($team == 'D') ? 8.0 : 12.0;
            } else {
                $planned_hours = 0.0;
            }
        }
        
        $stmt = $db->prepare("SELECT hours FROM manual_hours WHERE user_id=? AND work_date=?");
        $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
        $stmt->bindValue(2, $date_str, SQLITE3_TEXT);
        $manual_result = $stmt->execute();
        $manual = $manual_result->fetchArray(SQLITE3_ASSOC);
        
        $manual_hours = $manual ? (float)$manual['hours'] : 0.0;
        
        $worked_hours = $manual_hours > 0 ? $manual_hours : max(0, $planned_hours - $absence_hours - $leave_hours);
        
        $stmt = $db->prepare("SELECT note FROM day_notes WHERE user_id=? AND work_date=?");
        $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
        $stmt->bindValue(2, $date_str, SQLITE3_TEXT);
        $note_result = $stmt->execute();
        $note_row = $note_result->fetchArray(SQLITE3_ASSOC);
        
        $note = $note_row ? $note_row['note'] : '';
        
        $has_activity = ($planned_hours > 0 || $worked_hours > 0 || $absence_hours > 0 || $leave_hours > 0 || 
                        $has_overtime || $note != '');
        
        if (!$show_empty && !$has_activity) {
            $current->modify('+1 day');
            continue;
        }
        
        $schedule[] = [
            'date' => $current->format('d/m'),
            'full_date' => $date_str,
            'weekday' => $weekdays_sv[$weekday_num],
            'label' => $shift_label,
            'planned_hours' => $planned_hours,
            'worked_hours' => $worked_hours,
            'absence_reason' => $absence_reason,
            'absence_hours' => $absence_hours,
            'leave_hours' => $leave_hours,
            'mertid' => $mertid,
            'ot50' => $ot50,
            'ot100' => $ot100,
            'ot200' => $ot200,
            'note' => $note,
            'is_weekend' => $is_weekend,
            'is_red_day' => $is_red
        ];
        
        $current->modify('+1 day');
    }
    
    echo json_encode([
        'success' => true,
        'schedule' => $schedule
    ]);
    
    $db->close();
}

function handle_save_absence() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $user_id = $data['user_id'] ?? 0;
    $work_date = $data['work_date'] ?? '';
    $reason = $data['reason'] ?? '';
    $hours = floatval($data['hours'] ?? 0);
    
    $db = get_db();
    
    if ($hours == 0 && $reason == '') {
        $stmt = $db->prepare("DELETE FROM absences WHERE user_id=? AND work_date=?");
        $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
        $stmt->bindValue(2, $work_date, SQLITE3_TEXT);
        $stmt->execute();
        echo json_encode(['success' => true]);
        $db->close();
        return;
    }
    
    $stmt = $db->prepare("SELECT id FROM absences WHERE user_id=? AND work_date=?");
    $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
    $stmt->bindValue(2, $work_date, SQLITE3_TEXT);
    $result = $stmt->execute();
    $exists = $result->fetchArray();
    
    if ($exists) {
        $stmt = $db->prepare("UPDATE absences SET reason=?, hours=? WHERE id=?");
        $stmt->bindValue(1, $reason, SQLITE3_TEXT);
        $stmt->bindValue(2, $hours, SQLITE3_FLOAT);
        $stmt->bindValue(3, $exists['id'], SQLITE3_INTEGER);
    } else {
        $stmt = $db->prepare("INSERT INTO absences(user_id, work_date, reason, hours) VALUES(?,?,?,?)");
        $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
        $stmt->bindValue(2, $work_date, SQLITE3_TEXT);
        $stmt->bindValue(3, $reason, SQLITE3_TEXT);
        $stmt->bindValue(4, $hours, SQLITE3_FLOAT);
    }
    
    $stmt->execute();
    
    echo json_encode(['success' => true]);
    
    $db->close();
}

function handle_save_overtime() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $user_id = $data['user_id'] ?? 0;
    $work_date = $data['work_date'] ?? '';
    $mertid = floatval($data['mertid'] ?? 0);
    $ot50 = floatval($data['ot50'] ?? 0);
    $ot100 = floatval($data['ot100'] ?? 0);
    $ot200 = floatval($data['ot200'] ?? 0);
    
    $db = get_db();
    
    if ($mertid == 0 && $ot50 == 0 && $ot100 == 0 && $ot200 == 0) {
        $stmt = $db->prepare("DELETE FROM overtimes WHERE user_id=? AND work_date=?");
        $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
        $stmt->bindValue(2, $work_date, SQLITE3_TEXT);
        $stmt->execute();
        echo json_encode(['success' => true]);
        $db->close();
        return;
    }
    
    $stmt = $db->prepare("SELECT id FROM overtimes WHERE user_id=? AND work_date=?");
    $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
    $stmt->bindValue(2, $work_date, SQLITE3_TEXT);
    $result = $stmt->execute();
    $exists = $result->fetchArray();
    
    if ($exists) {
        $stmt = $db->prepare("UPDATE overtimes SET mertid=?, ot50=?, ot100=?, ot200=? WHERE id=?");
        $stmt->bindValue(1, $mertid, SQLITE3_FLOAT);
        $stmt->bindValue(2, $ot50, SQLITE3_FLOAT);
        $stmt->bindValue(3, $ot100, SQLITE3_FLOAT);
        $stmt->bindValue(4, $ot200, SQLITE3_FLOAT);
        $stmt->bindValue(5, $exists['id'], SQLITE3_INTEGER);
    } else {
        $stmt = $db->prepare("INSERT INTO overtimes(user_id, work_date, mertid, ot50, ot100, ot200) VALUES(?,?,?,?,?,?)");
        $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
        $stmt->bindValue(2, $work_date, SQLITE3_TEXT);
        $stmt->bindValue(3, $mertid, SQLITE3_FLOAT);
        $stmt->bindValue(4, $ot50, SQLITE3_FLOAT);
        $stmt->bindValue(5, $ot100, SQLITE3_FLOAT);
        $stmt->bindValue(6, $ot200, SQLITE3_FLOAT);
    }
    
    $stmt->execute();
    
    echo json_encode(['success' => true]);
    
    $db->close();
}

function handle_save_note() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $user_id = $data['user_id'] ?? 0;
    $work_date = $data['work_date'] ?? '';
    $note = $data['note'] ?? '';
    
    $db = get_db();
    
    if ($note == '') {
        $stmt = $db->prepare("DELETE FROM day_notes WHERE user_id=? AND work_date=?");
        $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
        $stmt->bindValue(2, $work_date, SQLITE3_TEXT);
        $stmt->execute();
        echo json_encode(['success' => true]);
        $db->close();
        return;
    }
    
    $stmt = $db->prepare("SELECT id FROM day_notes WHERE user_id=? AND work_date=?");
    $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
    $stmt->bindValue(2, $work_date, SQLITE3_TEXT);
    $result = $stmt->execute();
    $exists = $result->fetchArray();
    
    if ($exists) {
        $stmt = $db->prepare("UPDATE day_notes SET note=? WHERE id=?");
        $stmt->bindValue(1, $note, SQLITE3_TEXT);
        $stmt->bindValue(2, $exists['id'], SQLITE3_INTEGER);
    } else {
        $stmt = $db->prepare("INSERT INTO day_notes(user_id, work_date, note) VALUES(?,?,?)");
        $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
        $stmt->bindValue(2, $work_date, SQLITE3_TEXT);
        $stmt->bindValue(3, $note, SQLITE3_TEXT);
    }
    
    $stmt->execute();
    
    echo json_encode(['success' => true]);
    
    $db->close();
}

function handle_save_report() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $user_id = $data['user_id'] ?? 0;
    $year = $data['year'] ?? date('Y');
    $month = $data['month'] ?? date('m');
    
    $db = get_db();
    
    $first_day = "$year-$month-01";
    $last_day = date('Y-m-t', strtotime($first_day));
    
    $totals = [
        'plan' => 0.0,
        'worked' => 0.0,
        'abs' => 0.0,
        'leave' => 0.0,
        'mertid' => 0.0,
        'ot50' => 0.0,
        'ot100' => 0.0,
        'ot200' => 0.0
    ];
    
    $stmt = $db->prepare("SELECT reason, SUM(hours) as total FROM absences WHERE user_id=? AND work_date BETWEEN ? AND ? GROUP BY reason");
    $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
    $stmt->bindValue(2, $first_day, SQLITE3_TEXT);
    $stmt->bindValue(3, $last_day, SQLITE3_TEXT);
    $result = $stmt->execute();
    
    $leave_reasons = ['Semester', 'ATK', 'Komp'];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if (in_array($row['reason'], $leave_reasons)) {
            $totals['leave'] += floatval($row['total']);
        } else {
            $totals['abs'] += floatval($row['total']);
        }
    }
    
    $stmt = $db->prepare("SELECT SUM(mertid) as mertid, SUM(ot50) as ot50, SUM(ot100) as ot100, SUM(ot200) as ot200 FROM overtimes WHERE user_id=? AND work_date BETWEEN ? AND ?");
    $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
    $stmt->bindValue(2, $first_day, SQLITE3_TEXT);
    $stmt->bindValue(3, $last_day, SQLITE3_TEXT);
    $result = $stmt->execute();
    $ot_row = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($ot_row) {
        $totals['mertid'] = floatval($ot_row['mertid'] ?? 0);
        $totals['ot50'] = floatval($ot_row['ot50'] ?? 0);
        $totals['ot100'] = floatval($ot_row['ot100'] ?? 0);
        $totals['ot200'] = floatval($ot_row['ot200'] ?? 0);
    }
    
    $stmt = $db->prepare("SELECT team FROM users WHERE id=?");
    $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    $team = $user['team'];
    
    $result = $db->query("SELECT start_date, team_anchors FROM settings WHERE id=1");
    $settings_row = $result->fetchArray(SQLITE3_ASSOC);
    $settings = [
        'start_date' => $settings_row['start_date'],
        'team_anchors' => $settings_row['team_anchors']
    ];
    
    $current = new DateTime($first_day);
    $end = new DateTime($last_day);
    
    while ($current <= $end) {
        $date_str = $current->format('Y-m-d');
        $shift_label = get_shift_pattern($team, $date_str, $settings);
        $planned = get_planned_hours($team, $shift_label);
        $totals['plan'] += $planned;
        $current->modify('+1 day');
    }
    
    $totals['worked'] = max(0, $totals['plan'] - $totals['abs'] - $totals['leave']);
    
    $changes = ($totals['abs'] > 0 || $totals['leave'] > 0 || $totals['mertid'] > 0 || 
                $totals['ot50'] > 0 || $totals['ot100'] > 0 || $totals['ot200'] > 0) ? 1 : 0;
    
    $totals_json = json_encode($totals);
    $created_at = date('Y-m-d H:i:s');
    
    $stmt = $db->prepare("SELECT id FROM reports WHERE user_id=? AND year=? AND month=?");
    $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
    $stmt->bindValue(2, $year, SQLITE3_INTEGER);
    $stmt->bindValue(3, $month, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $exists = $result->fetchArray();
    
    if ($exists) {
        $stmt = $db->prepare("UPDATE reports SET created_at=?, totals_json=?, changes=?, approved_by=NULL, approved_at=NULL WHERE id=?");
        $stmt->bindValue(1, $created_at, SQLITE3_TEXT);
        $stmt->bindValue(2, $totals_json, SQLITE3_TEXT);
        $stmt->bindValue(3, $changes, SQLITE3_INTEGER);
        $stmt->bindValue(4, $exists['id'], SQLITE3_INTEGER);
    } else {
        $stmt = $db->prepare("INSERT INTO reports(user_id, year, month, created_at, totals_json, changes) VALUES(?,?,?,?,?,?)");
        $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
        $stmt->bindValue(2, $year, SQLITE3_INTEGER);
        $stmt->bindValue(3, $month, SQLITE3_INTEGER);
        $stmt->bindValue(4, $created_at, SQLITE3_TEXT);
        $stmt->bindValue(5, $totals_json, SQLITE3_TEXT);
        $stmt->bindValue(6, $changes, SQLITE3_INTEGER);
    }
    
    $stmt->execute();
    
    echo json_encode(['success' => true, 'message' => 'Rapporten har sparats']);
    
    $db->close();
}

function handle_list_reports() {
    $year = $_GET['year'] ?? date('Y');
    $month = $_GET['month'] ?? date('m');
    
    $db = get_db();
    
    $stmt = $db->prepare("
        SELECT r.id, r.user_id, u.name, u.team, r.year, r.month, r.totals_json, r.changes, r.approved_by
        FROM reports r
        JOIN users u ON u.id = r.user_id
        WHERE r.year=? AND r.month=? AND u.hidden=0
        ORDER BY u.name
    ");
    $stmt->bindValue(1, $year, SQLITE3_INTEGER);
    $stmt->bindValue(2, $month, SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    $reports = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $totals = json_decode($row['totals_json'], true);
        $reports[] = [
            'id' => $row['id'],
            'user_id' => $row['user_id'],
            'name' => $row['name'],
            'team' => $row['team'],
            'year' => $row['year'],
            'month' => $row['month'],
            'totals' => $totals,
            'changes' => (int)$row['changes'],
            'approved' => !is_null($row['approved_by'])
        ];
    }
    
    echo json_encode([
        'success' => true,
        'reports' => $reports
    ]);
    
    $db->close();
}

function handle_approve_report() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $report_id = $data['report_id'] ?? 0;
    $admin_id = $data['admin_id'] ?? 0;
    
    $db = get_db();
    
    $approved_at = date('Y-m-d H:i:s');
    
    $stmt = $db->prepare("UPDATE reports SET approved_by=?, approved_at=? WHERE id=?");
    $stmt->bindValue(1, $admin_id, SQLITE3_INTEGER);
    $stmt->bindValue(2, $approved_at, SQLITE3_TEXT);
    $stmt->bindValue(3, $report_id, SQLITE3_INTEGER);
    $stmt->execute();
    
    echo json_encode(['success' => true, 'message' => 'Rapporten har godkänts']);
    
    $db->close();
}

function handle_get_statistics() {
    $user_id = $_GET['user_id'] ?? 0;
    $year = $_GET['year'] ?? date('Y');
    
    if (!$user_id) {
        echo json_encode(['success' => false, 'error' => 'Användar-ID saknas']);
        return;
    }
    
    $db = get_db();
    
    $first_day = "$year-01-01";
    $last_day = "$year-12-31";
    
    $statistics = [
        'year' => $year,
        'total_absence' => 0.0,
        'total_overtime' => 0.0,
        'sick_hours' => 0.0,
        'leave_hours' => 0.0,
        'absence_by_month' => [],
        'overtime_by_month' => []
    ];
    
    $stmt = $db->prepare("
        SELECT strftime('%m', work_date) as month, reason, SUM(hours) as total
        FROM absences
        WHERE user_id=? AND work_date BETWEEN ? AND ?
        GROUP BY month, reason
        ORDER BY month, reason
    ");
    $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
    $stmt->bindValue(2, $first_day, SQLITE3_TEXT);
    $stmt->bindValue(3, $last_day, SQLITE3_TEXT);
    $result = $stmt->execute();
    
    $leave_reasons = ['Semester', 'ATK', 'Komp'];
    
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $month = (int)$row['month'];
        $reason = $row['reason'];
        $hours = floatval($row['total']);
        
        if (!isset($statistics['absence_by_month'][$month])) {
            $statistics['absence_by_month'][$month] = [];
        }
        
        $statistics['absence_by_month'][$month][$reason] = $hours;
        $statistics['total_absence'] += $hours;
        
        if ($reason == 'Sjuk') {
            $statistics['sick_hours'] += $hours;
        }
        
        if (in_array($reason, $leave_reasons)) {
            $statistics['leave_hours'] += $hours;
        }
    }
    
    $stmt = $db->prepare("
        SELECT strftime('%m', work_date) as month, 
               SUM(mertid) as mertid, SUM(ot50) as ot50, SUM(ot100) as ot100, SUM(ot200) as ot200
        FROM overtimes
        WHERE user_id=? AND work_date BETWEEN ? AND ?
        GROUP BY month
        ORDER BY month
    ");
    $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
    $stmt->bindValue(2, $first_day, SQLITE3_TEXT);
    $stmt->bindValue(3, $last_day, SQLITE3_TEXT);
    $result = $stmt->execute();
    
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $month = (int)$row['month'];
        $mertid = floatval($row['mertid'] ?? 0);
        $ot50 = floatval($row['ot50'] ?? 0);
        $ot100 = floatval($row['ot100'] ?? 0);
        $ot200 = floatval($row['ot200'] ?? 0);
        
        $statistics['overtime_by_month'][$month] = [
            'mertid' => $mertid,
            'ot50' => $ot50,
            'ot100' => $ot100,
            'ot200' => $ot200
        ];
        
        $statistics['total_overtime'] += ($mertid + $ot50 + $ot100 + $ot200);
    }
    
    echo json_encode([
        'success' => true,
        'statistics' => $statistics
    ]);
    
    $db->close();
}

function handle_get_company_statistics() {
    $year = $_GET['year'] ?? date('Y');
    $month = $_GET['month'] ?? 0;
    
    $db = get_db();
    
    if ($month == 0) {
        $first_day = "$year-01-01";
        $last_day = "$year-12-31";
    } else {
        $first_day = "$year-$month-01";
        $last_day = date('Y-m-t', strtotime($first_day));
    }
    
    $statistics = [
        'total_worked' => 0.0,
        'total_absence' => 0.0,
        'total_leave' => 0.0,
        'total_overtime' => 0.0,
        'sick_hours' => 0.0,
        'sick_percent' => 0.0,
        'user_count' => 0,
        'by_team' => [],
        'top_worked' => [],
        'top_absence' => [],
        'top_overtime' => [],
        'users' => []
    ];
    
    $result = $db->query("SELECT id, name, team FROM users WHERE hidden=0 ORDER BY name");
    $users = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $users[] = $row;
    }
    
    $statistics['user_count'] = count($users);
    
    $leave_reasons = ['Semester', 'ATK', 'Komp'];
    $team_stats = ['A' => [], 'B' => [], 'C' => [], 'D' => []];
    
    foreach ($users as $user) {
        $user_data = [
            'name' => $user['name'],
            'team' => $user['team'],
            'worked' => 0.0,
            'absence' => 0.0,
            'leave' => 0.0,
            'total_overtime' => 0.0
        ];
        
        $stmt = $db->prepare("SELECT totals_json FROM reports WHERE user_id=? AND year=?" . ($month > 0 ? " AND month=?" : ""));
        $stmt->bindValue(1, $user['id'], SQLITE3_INTEGER);
        $stmt->bindValue(2, $year, SQLITE3_INTEGER);
        if ($month > 0) {
            $stmt->bindValue(3, $month, SQLITE3_INTEGER);
        }
        $result = $stmt->execute();
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $totals = json_decode($row['totals_json'], true);
            $user_data['worked'] += $totals['worked'] ?? 0;
            $user_data['absence'] += $totals['abs'] ?? 0;
            $user_data['leave'] += $totals['leave'] ?? 0;
            $user_data['total_overtime'] += ($totals['mertid'] ?? 0) + ($totals['ot50'] ?? 0) + ($totals['ot100'] ?? 0) + ($totals['ot200'] ?? 0);
        }
        
        $statistics['total_worked'] += $user_data['worked'];
        $statistics['total_absence'] += $user_data['absence'];
        $statistics['total_leave'] += $user_data['leave'];
        $statistics['total_overtime'] += $user_data['total_overtime'];
        
        $stmt = $db->prepare("SELECT SUM(hours) as total FROM absences WHERE user_id=? AND work_date BETWEEN ? AND ? AND reason='Sjuk'");
        $stmt->bindValue(1, $user['id'], SQLITE3_INTEGER);
        $stmt->bindValue(2, $first_day, SQLITE3_TEXT);
        $stmt->bindValue(3, $last_day, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        if ($row) {
            $statistics['sick_hours'] += floatval($row['total'] ?? 0);
        }
        
        if (!isset($team_stats[$user['team']]['worked'])) {
            $team_stats[$user['team']] = [
                'team' => $user['team'],
                'user_count' => 0,
                'worked' => 0.0,
                'absence' => 0.0,
                'leave' => 0.0,
                'overtime' => 0.0
            ];
        }
        
        $team_stats[$user['team']]['user_count']++;
        $team_stats[$user['team']]['worked'] += $user_data['worked'];
        $team_stats[$user['team']]['absence'] += $user_data['absence'];
        $team_stats[$user['team']]['leave'] += $user_data['leave'];
        $team_stats[$user['team']]['overtime'] += $user_data['total_overtime'];
        
        $statistics['users'][] = $user_data;
    }
    
    if ($statistics['total_worked'] > 0) {
        $statistics['sick_percent'] = ($statistics['sick_hours'] / $statistics['total_worked']) * 100;
    }
    
    foreach ($team_stats as $team => $data) {
        if ($data['user_count'] > 0) {
            $statistics['by_team'][] = $data;
        }
    }
    
    $users_sorted_worked = $statistics['users'];
    usort($users_sorted_worked, function($a, $b) {
        return $b['worked'] - $a['worked'];
    });
    $statistics['top_worked'] = array_slice(array_map(function($user) {
        return ['name' => $user['name'], 'hours' => $user['worked']];
    }, $users_sorted_worked), 0, 5);
    
    $users_sorted_absence = $statistics['users'];
    usort($users_sorted_absence, function($a, $b) {
        return $b['absence'] - $a['absence'];
    });
    $statistics['top_absence'] = array_slice(array_map(function($user) {
        return ['name' => $user['name'], 'hours' => $user['absence']];
    }, $users_sorted_absence), 0, 5);
    
    $users_sorted_overtime = $statistics['users'];
    usort($users_sorted_overtime, function($a, $b) {
        return $b['total_overtime'] - $a['total_overtime'];
    });
    $statistics['top_overtime'] = array_slice(array_map(function($user) {
        return ['name' => $user['name'], 'hours' => $user['total_overtime']];
    }, $users_sorted_overtime), 0, 5);
    
    echo json_encode([
        'success' => true,
        'statistics' => $statistics
    ]);
    
    $db->close();
}

function handle_get_team_names() {
    $db = get_db();
    $result = $db->query("SELECT team_a, team_b, team_c, team_d FROM team_names WHERE id=1");
    $row = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$row) {
        echo json_encode([
            'success' => true,
            'team_names' => [
                'A' => 'Skiftlag 1',
                'B' => 'Skiftlag 2',
                'C' => 'Skiftlag 3',
                'D' => 'Dagtid'
            ]
        ]);
        $db->close();
        return;
    }
    
    echo json_encode([
        'success' => true,
        'team_names' => [
            'A' => $row['team_a'],
            'B' => $row['team_b'],
            'C' => $row['team_c'],
            'D' => $row['team_d']
        ]
    ]);
    
    $db->close();
}

function handle_save_team_names() {
    $data = json_decode(file_get_contents('php://input'), true);
    $team_names = $data['team_names'] ?? [];
    
    if (empty($team_names)) {
        echo json_encode(['success' => false, 'error' => 'Skiftlagsnamn måste anges']);
        return;
    }
    
    $db = get_db();
    
    $team_a = $team_names['A'] ?? 'Skiftlag 1';
    $team_b = $team_names['B'] ?? 'Skiftlag 2';
    $team_c = $team_names['C'] ?? 'Skiftlag 3';
    $team_d = $team_names['D'] ?? 'Dagtid';
    
    $stmt = $db->prepare("UPDATE team_names SET team_a=?, team_b=?, team_c=?, team_d=? WHERE id=1");
    $stmt->bindValue(1, $team_a, SQLITE3_TEXT);
    $stmt->bindValue(2, $team_b, SQLITE3_TEXT);
    $stmt->bindValue(3, $team_c, SQLITE3_TEXT);
    $stmt->bindValue(4, $team_d, SQLITE3_TEXT);
    $stmt->execute();
    
    echo json_encode(['success' => true]);
    
    $db->close();
}

function handle_get_settings() {
    $db = get_db();
    $result = $db->query("SELECT start_date, team_anchors FROM settings WHERE id=1");
    $row = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'Inga inställningar']);
        $db->close();
        return;
    }
    
    $team_anchors = json_decode($row['team_anchors'], true);
    
    echo json_encode([
        'success' => true,
        'settings' => [
            'start_date' => $row['start_date'],
            'team_anchors' => $team_anchors
        ]
    ]);
    
    $db->close();
}

function handle_save_settings() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $start_date = $data['start_date'] ?? '';
    $team_anchors = $data['team_anchors'] ?? [];
    
    if (!$start_date) {
        echo json_encode(['success' => false, 'error' => 'Startdatum måste anges']);
        return;
    }
    
    $db = get_db();
    
    $stmt = $db->prepare("UPDATE settings SET start_date=?, team_anchors=? WHERE id=1");
    $stmt->bindValue(1, $start_date, SQLITE3_TEXT);
    $stmt->bindValue(2, json_encode($team_anchors), SQLITE3_TEXT);
    $stmt->execute();
    
    echo json_encode(['success' => true]);
    
    $db->close();
}

function handle_get_phrases() {
    $db = get_db();
    
    $result = $db->query("SELECT id, phrase FROM common_phrases ORDER BY phrase");
    
    $phrases = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $phrases[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'phrases' => $phrases
    ]);
    
    $db->close();
}

function handle_add_phrase() {
    $data = json_decode(file_get_contents('php://input'), true);
    $phrase = $data['phrase'] ?? '';
    
    if (!$phrase) {
        echo json_encode(['success' => false, 'error' => 'Fras saknas']);
        return;
    }
    
    $db = get_db();
    
    try {
        $stmt = $db->prepare("INSERT INTO common_phrases(phrase) VALUES(?)");
        $stmt->bindValue(1, $phrase, SQLITE3_TEXT);
        $stmt->execute();
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Frasen finns redan']);
    }
    
    $db->close();
}

function handle_delete_phrase() {
    $data = json_decode(file_get_contents('php://input'), true);
    $phrase_id = $data['phrase_id'] ?? 0;
    
    if (!$phrase_id) {
        echo json_encode(['success' => false, 'error' => 'Fras-ID saknas']);
        return;
    }
    
    $db = get_db();
    
    $stmt = $db->prepare("DELETE FROM common_phrases WHERE id=?");
    $stmt->bindValue(1, $phrase_id, SQLITE3_INTEGER);
    $stmt->execute();
    
    echo json_encode(['success' => true]);
    
    $db->close();
}
?>