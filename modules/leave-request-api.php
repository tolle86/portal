<?php
// leave-request-api.php - API för ledighetsansökan
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Stockholm');

// Inkludera huvuddatabasen
$db_path = '../shiftplanner.db';

function get_db() {
    global $db_path;
    $db = new SQLite3($db_path);
    $db->exec("PRAGMA foreign_keys=ON");
    return $db;
}

// Initiera tabell för ledighetsansökningar
function init_leave_tables() {
    $db = get_db();
    
    $db->exec("
        CREATE TABLE IF NOT EXISTS leave_requests(
            id INTEGER PRIMARY KEY,
            user_id INTEGER NOT NULL,
            type TEXT NOT NULL CHECK(type IN ('Semester','ATK','Komp','VAB','Tjänstledig')),
            from_date TEXT NOT NULL,
            to_date TEXT NOT NULL,
            days INTEGER NOT NULL,
            hours REAL NOT NULL,
            status TEXT NOT NULL DEFAULT 'Pending' CHECK(status IN ('Pending','Approved','Denied','Cancelled')),
            comment TEXT,
            deny_reason TEXT,
            created_at TEXT NOT NULL,
            approved_by INTEGER,
            approved_at TEXT,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(approved_by) REFERENCES users(id) ON DELETE SET NULL
        )
    ");
    
    $db->close();
}

init_leave_tables();

// Hämta användarens schema för beräkning
function get_user_schedule($user_id, $from_date, $to_date) {
    $db = get_db();
    
    // Hämta användarinfo
    $stmt = $db->prepare("SELECT team FROM users WHERE id=?");
    $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$user) {
        $db->close();
        return null;
    }
    
    $team = $user['team'];
    
    // Hämta inställningar
    $result = $db->query("SELECT start_date, team_anchors FROM settings WHERE id=1");
    $settings_row = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$settings_row) {
        $db->close();
        return null;
    }
    
    $db->close();
    
    return [
        'team' => $team,
        'settings' => [
            'start_date' => $settings_row['start_date'],
            'team_anchors' => $settings_row['team_anchors']
        ]
    ];
}

// Importera funktioner från huvudapi
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
        if ($weekday == 5) return 'NATT';
        if ($weekday == 6) return 'NATT';
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

// Hantera API-anrop
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'calculate_leave_days':
        handle_calculate_leave_days();
        break;
    
    case 'submit_leave_request':
        handle_submit_leave_request();
        break;
    
    case 'list_my_leave_requests':
        handle_list_my_leave_requests();
        break;
    
    case 'list_all_leave_requests':
        handle_list_all_leave_requests();
        break;
    
    case 'approve_leave_request':
        handle_approve_leave_request();
        break;
    
    case 'deny_leave_request':
        handle_deny_leave_request();
        break;
    
    case 'cancel_leave_request':
        handle_cancel_leave_request();
        break;
    
    default:
        echo json_encode(['success' => false, 'error' => 'Okänd åtgärd: ' . $action]);
}

// Beräkna antal dagar och timmar för ledighet
function handle_calculate_leave_days() {
    $user_id = $_GET['user_id'] ?? 0;
    $from_date = $_GET['from_date'] ?? '';
    $to_date = $_GET['to_date'] ?? '';
    
    if (!$user_id || !$from_date || !$to_date) {
        echo json_encode(['success' => false, 'error' => 'Saknade parametrar']);
        return;
    }
    
    $schedule = get_user_schedule($user_id, $from_date, $to_date);
    
    if (!$schedule) {
        echo json_encode(['success' => false, 'error' => 'Kunde inte hämta schema']);
        return;
    }
    
    $team = $schedule['team'];
    $settings = $schedule['settings'];
    
    $current = new DateTime($from_date);
    $end = new DateTime($to_date);
    
    $total_days = 0;
    $total_hours = 0.0;
    
    while ($current <= $end) {
        $date_str = $current->format('Y-m-d');
        $shift_label = get_shift_pattern($team, $date_str, $settings);
        $planned_hours = get_planned_hours($team, $shift_label);
        
        if ($planned_hours > 0) {
            $total_days++;
            $total_hours += $planned_hours;
        }
        
        $current->modify('+1 day');
    }
    
    echo json_encode([
        'success' => true,
        'days' => $total_days,
        'hours' => $total_hours
    ]);
}

// Skicka ledighetsansökan
function handle_submit_leave_request() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $user_id = $data['user_id'] ?? 0;
    $type = $data['type'] ?? '';
    $from_date = $data['from_date'] ?? '';
    $to_date = $data['to_date'] ?? '';
    $comment = $data['comment'] ?? '';
    
    if (!$user_id || !$type || !$from_date || !$to_date) {
        echo json_encode(['success' => false, 'error' => 'Alla fält måste fyllas i']);
        return;
    }
    
    // Beräkna dagar och timmar
    $schedule = get_user_schedule($user_id, $from_date, $to_date);
    
    if (!$schedule) {
        echo json_encode(['success' => false, 'error' => 'Kunde inte hämta schema']);
        return;
    }
    
    $team = $schedule['team'];
    $settings = $schedule['settings'];
    
    $current = new DateTime($from_date);
    $end = new DateTime($to_date);
    
    $total_days = 0;
    $total_hours = 0.0;
    
    while ($current <= $end) {
        $date_str = $current->format('Y-m-d');
        $shift_label = get_shift_pattern($team, $date_str, $settings);
        $planned_hours = get_planned_hours($team, $shift_label);
        
        if ($planned_hours > 0) {
            $total_days++;
            $total_hours += $planned_hours;
        }
        
        $current->modify('+1 day');
    }
    
    if ($total_days == 0) {
        echo json_encode(['success' => false, 'error' => 'Ingen arbetad tid under vald period']);
        return;
    }
    
    $db = get_db();
    $created_at = date('Y-m-d H:i:s');
    
    $stmt = $db->prepare("
        INSERT INTO leave_requests(user_id, type, from_date, to_date, days, hours, status, comment, created_at)
        VALUES(?, ?, ?, ?, ?, ?, 'Pending', ?, ?)
    ");
    $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
    $stmt->bindValue(2, $type, SQLITE3_TEXT);
    $stmt->bindValue(3, $from_date, SQLITE3_TEXT);
    $stmt->bindValue(4, $to_date, SQLITE3_TEXT);
    $stmt->bindValue(5, $total_days, SQLITE3_INTEGER);
    $stmt->bindValue(6, $total_hours, SQLITE3_FLOAT);
    $stmt->bindValue(7, $comment, SQLITE3_TEXT);
    $stmt->bindValue(8, $created_at, SQLITE3_TEXT);
    $stmt->execute();
    
    echo json_encode(['success' => true]);
    
    $db->close();
}

// Lista användarens egna ansökningar
function handle_list_my_leave_requests() {
    $user_id = $_GET['user_id'] ?? 0;
    
    if (!$user_id) {
        echo json_encode(['success' => false, 'error' => 'Användar-ID saknas']);
        return;
    }
    
    $db = get_db();
    
    $stmt = $db->prepare("
        SELECT id, type, from_date, to_date, days, hours, status, comment, deny_reason, created_at
        FROM leave_requests
        WHERE user_id=? AND status != 'Cancelled'
        ORDER BY created_at DESC
    ");
    $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    $requests = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $requests[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'requests' => $requests
    ]);
    
    $db->close();
}

// Lista alla ansökningar (Admin/Kontrollant)
function handle_list_all_leave_requests() {
    $db = get_db();
    
    $result = $db->query("
        SELECT lr.id, lr.user_id, u.name as user_name, u.team, lr.type, lr.from_date, lr.to_date, 
               lr.days, lr.hours, lr.status, lr.comment, lr.created_at
        FROM leave_requests lr
        JOIN users u ON u.id = lr.user_id
        WHERE lr.status != 'Cancelled'
        ORDER BY lr.created_at DESC
    ");
    
    $requests = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $requests[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'requests' => $requests
    ]);
    
    $db->close();
}

// Godkänn ledighetsansökan (Admin)
function handle_approve_leave_request() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $request_id = $data['request_id'] ?? 0;
    $admin_id = $data['admin_id'] ?? 0;
    
    if (!$request_id || !$admin_id) {
        echo json_encode(['success' => false, 'error' => 'Saknade parametrar']);
        return;
    }
    
    $db = get_db();
    
    // Hämta ansökan
    $stmt = $db->prepare("SELECT user_id, type, from_date, to_date, hours FROM leave_requests WHERE id=?");
    $stmt->bindValue(1, $request_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $request = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$request) {
        echo json_encode(['success' => false, 'error' => 'Ansökan hittades inte']);
        $db->close();
        return;
    }
    
    // Uppdatera status
    $approved_at = date('Y-m-d H:i:s');
    $stmt = $db->prepare("UPDATE leave_requests SET status='Approved', approved_by=?, approved_at=? WHERE id=?");
    $stmt->bindValue(1, $admin_id, SQLITE3_INTEGER);
    $stmt->bindValue(2, $approved_at, SQLITE3_TEXT);
    $stmt->bindValue(3, $request_id, SQLITE3_INTEGER);
    $stmt->execute();
    
    // Lägg till ledighet i absences-tabellen för varje dag
    $current = new DateTime($request['from_date']);
    $end = new DateTime($request['to_date']);
    
    // Hämta schema för att veta vilka dagar som ska läggas till
    $schedule = get_user_schedule($request['user_id'], $request['from_date'], $request['to_date']);
    $team = $schedule['team'];
    $settings = $schedule['settings'];
    
    while ($current <= $end) {
        $date_str = $current->format('Y-m-d');
        $shift_label = get_shift_pattern($team, $date_str, $settings);
        $planned_hours = get_planned_hours($team, $shift_label);
        
        if ($planned_hours > 0) {
            // Kontrollera om det redan finns en absence
            $stmt = $db->prepare("SELECT id FROM absences WHERE user_id=? AND work_date=?");
            $stmt->bindValue(1, $request['user_id'], SQLITE3_INTEGER);
            $stmt->bindValue(2, $date_str, SQLITE3_TEXT);
            $check_result = $stmt->execute();
            $exists = $check_result->fetchArray();
            
            if ($exists) {
                // Uppdatera befintlig
                $stmt = $db->prepare("UPDATE absences SET reason=?, hours=? WHERE id=?");
                $stmt->bindValue(1, $request['type'], SQLITE3_TEXT);
                $stmt->bindValue(2, $planned_hours, SQLITE3_FLOAT);
                $stmt->bindValue(3, $exists['id'], SQLITE3_INTEGER);
                $stmt->execute();
            } else {
                // Skapa ny
                $stmt = $db->prepare("INSERT INTO absences(user_id, work_date, reason, hours) VALUES(?,?,?,?)");
                $stmt->bindValue(1, $request['user_id'], SQLITE3_INTEGER);
                $stmt->bindValue(2, $date_str, SQLITE3_TEXT);
                $stmt->bindValue(3, $request['type'], SQLITE3_TEXT);
                $stmt->bindValue(4, $planned_hours, SQLITE3_FLOAT);
                $stmt->execute();
            }
        }
        
        $current->modify('+1 day');
    }
    
    echo json_encode(['success' => true]);
    
    $db->close();
}

// Neka ledighetsansökan (Admin)
function handle_deny_leave_request() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $request_id = $data['request_id'] ?? 0;
    $admin_id = $data['admin_id'] ?? 0;
    $reason = $data['reason'] ?? '';
    
    if (!$request_id || !$admin_id || !$reason) {
        echo json_encode(['success' => false, 'error' => 'Saknade parametrar']);
        return;
    }
    
    $db = get_db();
    
    $approved_at = date('Y-m-d H:i:s');
    $stmt = $db->prepare("UPDATE leave_requests SET status='Denied', approved_by=?, approved_at=?, deny_reason=? WHERE id=?");
    $stmt->bindValue(1, $admin_id, SQLITE3_INTEGER);
    $stmt->bindValue(2, $approved_at, SQLITE3_TEXT);
    $stmt->bindValue(3, $reason, SQLITE3_TEXT);
    $stmt->bindValue(4, $request_id, SQLITE3_INTEGER);
    $stmt->execute();
    
    echo json_encode(['success' => true]);
    
    $db->close();
}

// Avbryt egen ansökan
function handle_cancel_leave_request() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $request_id = $data['request_id'] ?? 0;
    
    if (!$request_id) {
        echo json_encode(['success' => false, 'error' => 'Ansöknings-ID saknas']);
        return;
    }
    
    $db = get_db();
    
    $stmt = $db->prepare("UPDATE leave_requests SET status='Cancelled' WHERE id=? AND status='Pending'");
    $stmt->bindValue(1, $request_id, SQLITE3_INTEGER);
    $stmt->execute();
    
    echo json_encode(['success' => true]);
    
    $db->close();
}
?>