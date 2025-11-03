// app.js - Huvudapplikationslogik för Portal

var currentUser = null;
var originalUser = null;
var editingUserData = null;
var currentSchedule = [];
var editingUserId = null;

var TEAM_NAMES = {
    'A': 'Skiftlag 1',
    'B': 'Skiftlag 2',
    'C': 'Skiftlag 3',
    'D': 'Dagtid'
};

var REASONS = ['Sjuk', 'Semester', 'VAB', 'Tjänstledig', 'Övrig frånvaro', 'ATK', 'Komp'];
var LEAVE_REASONS = ['Semester', 'ATK', 'Komp'];
var WEEKDAYS = ['Måndag', 'Tisdag', 'Onsdag', 'Torsdag', 'Fredag', 'Lördag', 'Söndag'];
var MONTHS = ['', 'Januari', 'Februari', 'Mars', 'April', 'Maj', 'Juni', 'Juli', 'Augusti', 'September', 'Oktober', 'November', 'December'];

function getTeamLabel(team) {
    return TEAM_NAMES[team] || 'Skiftlag ' + team;
}

// Initiering vid sidladdning
document.addEventListener('DOMContentLoaded', function() {
    loadUserList();
    setDefaultDates();
});

function setDefaultDates() {
    var today = new Date();
    document.getElementById('report-year').value = today.getFullYear();
    document.getElementById('report-month').value = today.getMonth() + 1;
    document.getElementById('overview-year').value = today.getFullYear();
    document.getElementById('overview-month').value = today.getMonth() + 1;
    document.getElementById('stats-year').value = today.getFullYear();
    document.getElementById('company-stats-year').value = today.getFullYear();
    document.getElementById('company-stats-month').value = 0;
}

function loadUserList() {
    fetch('api.php?action=list_users')
        .then(function(response) { return response.json(); })
        .then(function(data) {
            var select = document.getElementById('username');
            select.innerHTML = '<option value="">— Välj användare —</option>';
            
            if (data.success && data.users) {
                data.users.forEach(function(user) {
                    var option = document.createElement('option');
                    option.value = user.name;
                    option.textContent = user.name;
                    select.appendChild(option);
                });
            }
        })
        .catch(function(error) {
            console.error('Fel vid laddning av användare:', error);
        });
}

function handleLogin(event) {
    event.preventDefault();
    
    var username = document.getElementById('username').value;
    var password = document.getElementById('password').value;
    
    if (!username) {
        showLoginError('Välj en användare');
        return false;
    }
    
    showLoading(true);
    
    fetch('api.php?action=login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: username, password: password })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        showLoading(false);
        
        if (data.success) {
            currentUser = data.user;
            originalUser = JSON.parse(JSON.stringify(data.user));
            editingUserData = null;
            showApp();
            showSuccess('Inloggning lyckades!');
            
            setTimeout(function() {
                loadSchedule();
            }, 500);
        } else {
            showLoginError(data.error || 'Felaktigt användarnamn eller lösenord');
        }
    })
    .catch(function(error) {
        showLoading(false);
        console.error('Login-fel:', error);
        showLoginError('Ett fel uppstod vid inloggning');
    });
    
    return false;
}

function handleLogout() {
    currentUser = null;
    originalUser = null;
    editingUserData = null;
    currentSchedule = [];
    document.getElementById('login-page').classList.remove('hidden');
    document.getElementById('app-page').classList.remove('active');
    document.getElementById('password').value = '';
    document.getElementById('edit-user-banner').classList.remove('visible');
}

function showApp() {
    document.getElementById('login-page').classList.add('hidden');
    document.getElementById('app-page').classList.add('active');
    
    loadTeamNames(function() {
        updateUserDisplay();
    });
    
    if (originalUser.role === 'Admin' || originalUser.role === 'Kontrollant') {
        document.getElementById('tab-overview').style.display = 'inline-block';
    } else {
        document.getElementById('tab-overview').style.display = 'none';
    }
    
    if (originalUser.role === 'Admin') {
        document.getElementById('tab-admin').style.display = 'inline-block';
        document.getElementById('tab-company-stats').style.display = 'inline-block';
    } else {
        document.getElementById('tab-admin').style.display = 'none';
        document.getElementById('tab-company-stats').style.display = 'none';
    }
}

function updateUserDisplay() {
    var displayUser = editingUserData || currentUser;
    
    document.getElementById('user-name').textContent = displayUser.name;
    
    var teamLabel = getTeamLabel(displayUser.team);
    
    document.getElementById('user-details').innerHTML = 
        '<span style="background: #e3f2fd; color: #0d47a1; padding: 3px 8px; border-radius: 4px; font-weight: bold; margin-right: 5px;">' + 
        teamLabel + '</span>' +
        '<span style="background: #e8f5e9; color: #2e7d32; padding: 3px 8px; border-radius: 4px; font-weight: bold;">' + 
        displayUser.role + '</span>';
    
    if (editingUserData) {
        document.getElementById('editing-user-name').textContent = editingUserData.name;
        document.getElementById('edit-user-banner').classList.add('visible');
    } else {
        document.getElementById('edit-user-banner').classList.remove('visible');
    }
}

function editUserSchedule(userId, userName, userTeam) {
    if (!originalUser || originalUser.role !== 'Admin') {
        showError('Du har inte behörighet att redigera andra användares scheman');
        return;
    }
    
    editingUserData = {
        id: userId,
        name: userName,
        team: userTeam,
        role: 'Användare'
    };
    
    currentUser = editingUserData;
    
    updateUserDisplay();
    switchTab('report');
    loadSchedule();
}

function cancelEditUser() {
    editingUserData = null;
    currentUser = originalUser;
    updateUserDisplay();
    loadSchedule();
}

function switchTab(tabName) {
    var tabs = document.querySelectorAll('.tab-content');
    tabs.forEach(function(tab) {
        tab.classList.remove('active');
    });
    
    var buttons = document.querySelectorAll('.tab-button');
    buttons.forEach(function(btn) {
        btn.classList.remove('active');
    });
    
    document.getElementById('tab-content-' + tabName).classList.add('active');
    
    var activeButtons = document.querySelectorAll('.tab-button');
    activeButtons.forEach(function(btn) {
        var btnText = btn.textContent.toLowerCase();
        if (btnText.includes(tabName) || 
            (tabName === 'report' && btnText.includes('månadsrapport')) ||
            (tabName === 'overview' && btnText.includes('översikt')) ||
            (tabName === 'stats' && btnText.includes('statistik') && !btnText.includes('företag')) ||
            (tabName === 'company-stats' && btnText.includes('företagsstatistik')) ||
            (tabName === 'leave' && btnText.includes('ledighet'))) {
            btn.classList.add('active');
        }
    });
    
    if (tabName === 'overview') {
        loadOverview();
    } else if (tabName === 'stats') {
        loadStatistics();
    } else if (tabName === 'report') {
        loadSchedule();
    } else if (tabName === 'company-stats') {
        loadCompanyStatistics();
    } else if (tabName === 'leave') {
        loadLeaveModule();
    }
}

function loadSchedule() {
    if (!currentUser) {
        return;
    }
    
    var year = document.getElementById('report-year').value;
    var month = document.getElementById('report-month').value;
    var showEmpty = document.getElementById('show-empty').checked;
    
    showLoading(true);
    
    fetch('api.php?action=get_schedule&user_id=' + currentUser.id + '&year=' + year + '&month=' + month + '&show_empty=' + (showEmpty ? '1' : '0'))
        .then(function(response) { return response.json(); })
        .then(function(data) {
            showLoading(false);
            
            if (data.success) {
                currentSchedule = data.schedule;
                renderSchedule(data.schedule);
                updateSummary(data.schedule);
            } else {
                showError(data.error || 'Kunde inte ladda schema');
            }
        })
        .catch(function(error) {
            showLoading(false);
            console.error('Fel vid laddning av schema:', error);
            showError('Ett fel uppstod vid laddning av schema');
        });
}

function renderSchedule(schedule) {
    var tbody = document.getElementById('schedule-body');
    tbody.innerHTML = '';
    
    if (schedule.length === 0) {
        tbody.innerHTML = '<tr><td colspan="12" style="text-align: center; padding: 40px; color: #666;">Inga dagar att visa för denna period</td></tr>';
        document.getElementById('summary-panel').style.display = 'none';
        return;
    }
    
    schedule.forEach(function(day, index) {
        var tr = document.createElement('tr');
        
        if (day.is_red_day) {
            tr.classList.add('red-day');
        } else if (day.is_weekend) {
            tr.classList.add('weekend');
        }
        
        tr.innerHTML += '<td><strong>' + day.date + '</strong></td>';
        tr.innerHTML += '<td>' + day.weekday + '</td>';
        tr.innerHTML += '<td>' + (day.label || '') + '</td>';
        tr.innerHTML += '<td><input type="number" value="' + (day.worked_hours || '') + '" min="0" max="24" step="0.5" onchange="updateDayData(' + index + ', \'worked\', this.value)"></td>';
        
        var reasonSelect = '<select onchange="updateDayData(' + index + ', \'reason\', this.value)">';
        reasonSelect += '<option value="">-</option>';
        REASONS.forEach(function(r) {
            reasonSelect += '<option value="' + r + '"' + (day.absence_reason === r ? ' selected' : '') + '>' + r + '</option>';
        });
        reasonSelect += '</select>';
        tr.innerHTML += '<td>' + reasonSelect + '</td>';
        
        tr.innerHTML += '<td><input type="number" value="' + (day.absence_hours || '') + '" min="0" max="24" step="0.5" onchange="updateDayData(' + index + ', \'absence\', this.value)"></td>';
        tr.innerHTML += '<td><input type="number" value="' + (day.leave_hours || '') + '" min="0" max="24" step="0.5" onchange="updateDayData(' + index + ', \'leave\', this.value)"></td>';
        tr.innerHTML += '<td><input type="number" value="' + (day.mertid || '') + '" min="0" max="24" step="0.5" onchange="updateDayData(' + index + ', \'mertid\', this.value)"></td>';
        tr.innerHTML += '<td><input type="number" value="' + (day.ot50 || '') + '" min="0" max="24" step="0.5" onchange="updateDayData(' + index + ', \'ot50\', this.value)"></td>';
        tr.innerHTML += '<td><input type="number" value="' + (day.ot100 || '') + '" min="0" max="24" step="0.5" onchange="updateDayData(' + index + ', \'ot100\', this.value)"></td>';
        tr.innerHTML += '<td><input type="number" value="' + (day.ot200 || '') + '" min="0" max="24" step="0.5" onchange="updateDayData(' + index + ', \'ot200\', this.value)"></td>';
        tr.innerHTML += '<td><input type="text" value="' + (day.note || '') + '" onchange="updateDayData(' + index + ', \'note\', this.value)"></td>';
        
        tbody.appendChild(tr);
    });
    
    document.getElementById('summary-panel').style.display = 'block';
}

function updateDayData(index, field, value) {
    if (!currentSchedule[index]) return;
    
    var day = currentSchedule[index];
    
    switch(field) {
        case 'worked':
            day.worked_hours = parseFloat(value) || 0;
            break;
        case 'reason':
            day.absence_reason = value;
            break;
        case 'absence':
            day.absence_hours = parseFloat(value) || 0;
            break;
        case 'leave':
            day.leave_hours = parseFloat(value) || 0;
            break;
        case 'mertid':
            day.mertid = parseFloat(value) || 0;
            break;
        case 'ot50':
            day.ot50 = parseFloat(value) || 0;
            break;
        case 'ot100':
            day.ot100 = parseFloat(value) || 0;
            break;
        case 'ot200':
            day.ot200 = parseFloat(value) || 0;
            break;
        case 'note':
            day.note = value;
            break;
    }
    
    updateSummary(currentSchedule);
    saveDayData(day);
}

function saveDayData(day) {
    if (!currentUser) return;
    
    if (day.absence_reason || day.absence_hours > 0 || day.leave_hours > 0) {
        var hours = day.absence_hours || day.leave_hours || 0;
        fetch('api.php?action=save_absence', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                user_id: currentUser.id,
                work_date: day.full_date,
                reason: day.absence_reason || '',
                hours: hours
            })
        });
    }
    
    if (day.mertid > 0 || day.ot50 > 0 || day.ot100 > 0 || day.ot200 > 0) {
        fetch('api.php?action=save_overtime', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                user_id: currentUser.id,
                work_date: day.full_date,
                mertid: day.mertid || 0,
                ot50: day.ot50 || 0,
                ot100: day.ot100 || 0,
                ot200: day.ot200 || 0
            })
        });
    }
    
    if (day.note) {
        fetch('api.php?action=save_note', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                user_id: currentUser.id,
                work_date: day.full_date,
                note: day.note
            })
        });
    }
}

function updateSummary(schedule) {
    var totals = {
        worked: 0,
        absence: 0,
        leave: 0,
        mertid: 0,
        ot50: 0,
        ot100: 0,
        ot200: 0
    };
    
    schedule.forEach(function(day) {
        totals.worked += parseFloat(day.worked_hours) || 0;
        totals.absence += parseFloat(day.absence_hours) || 0;
        totals.leave += parseFloat(day.leave_hours) || 0;
        totals.mertid += parseFloat(day.mertid) || 0;
        totals.ot50 += parseFloat(day.ot50) || 0;
        totals.ot100 += parseFloat(day.ot100) || 0;
        totals.ot200 += parseFloat(day.ot200) || 0;
    });
    
    document.getElementById('sum-worked').textContent = totals.worked.toFixed(1) + 'h';
    document.getElementById('sum-absence').textContent = totals.absence.toFixed(1) + 'h';
    document.getElementById('sum-leave').textContent = totals.leave.toFixed(1) + 'h';
    document.getElementById('sum-mertid').textContent = totals.mertid.toFixed(1) + 'h';
    document.getElementById('sum-ot50').textContent = totals.ot50.toFixed(1) + 'h';
    document.getElementById('sum-ot100').textContent = totals.ot100.toFixed(1) + 'h';
    document.getElementById('sum-ot200').textContent = totals.ot200.toFixed(1) + 'h';
}

function saveReport() {
    if (!currentUser) {
        showError('Du måste vara inloggad');
        return;
    }
    
    var year = document.getElementById('report-year').value;
    var month = document.getElementById('report-month').value;
    
    showLoading(true);
    
    fetch('api.php?action=save_report', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            user_id: currentUser.id,
            year: year,
            month: month
        })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        showLoading(false);
        
        if (data.success) {
            showSuccess('Rapport sparad!');
        } else {
            showError(data.error || 'Kunde inte spara rapport');
        }
    })
    .catch(function(error) {
        showLoading(false);
        console.error('Fel vid sparande:', error);
        showError('Ett fel uppstod vid sparande');
    });
}

function loadOverview() {
    if (!originalUser || (originalUser.role !== 'Admin' && originalUser.role !== 'Kontrollant')) {
        return;
    }
    
    var year = document.getElementById('overview-year').value;
    var month = document.getElementById('overview-month').value;
    
    showLoading(true);
    
    fetch('api.php?action=list_reports&year=' + year + '&month=' + month)
        .then(function(response) { return response.json(); })
        .then(function(data) {
            showLoading(false);
            
            if (data.success) {
                renderOverview(data.reports);
            } else {
                showError(data.error || 'Kunde inte ladda rapporter');
            }
        })
        .catch(function(error) {
            showLoading(false);
            console.error('Fel vid laddning av översikt:', error);
            showError('Ett fel uppstod vid laddning av översikt');
        });
}

function renderOverview(reports) {
    var tbody = document.getElementById('overview-body');
    tbody.innerHTML = '';
    
    if (reports.length === 0) {
        tbody.innerHTML = '<tr><td colspan="11" style="text-align: center; padding: 40px; color: #666;">Inga rapporter för denna period</td></tr>';
        return;
    }
    
    var isAdmin = originalUser && originalUser.role === 'Admin';
    
    reports.forEach(function(report) {
        var tr = document.createElement('tr');
        
        if (report.approved) {
            tr.classList.add('approved');
        }
        
        var totals = report.totals || {};
        
        tr.innerHTML = '<td><strong>' + report.name + '</strong></td>';
        tr.innerHTML += '<td>' + getTeamLabel(report.team) + '</td>';
        tr.innerHTML += '<td>' + (totals.worked || 0).toFixed(1) + 'h</td>';
        tr.innerHTML += '<td>' + (totals.abs || 0).toFixed(1) + 'h</td>';
        tr.innerHTML += '<td>' + (totals.leave || 0).toFixed(1) + 'h</td>';
        tr.innerHTML += '<td>' + (totals.mertid || 0).toFixed(1) + 'h</td>';
        tr.innerHTML += '<td>' + (totals.ot50 || 0).toFixed(1) + 'h</td>';
        tr.innerHTML += '<td>' + (totals.ot100 || 0).toFixed(1) + 'h</td>';
        tr.innerHTML += '<td>' + (totals.ot200 || 0).toFixed(1) + 'h</td>';
        tr.innerHTML += '<td>' + (report.changes ? 'Ändrat' : 'Oförändrat') + '</td>';
        
        var actions = '<div style="display: flex; gap: 5px; flex-wrap: wrap;">';
        
        if (isAdmin) {
            actions += '<button class="btn btn-info" style="padding: 6px 12px; font-size: 13px;" onclick="editUserSchedule(' + report.user_id + ', \'' + report.name + '\', \'' + report.team + '\')">Visa schema</button>';
        }
        
        if (report.approved) {
            actions += '<span style="background: #c8e6c9; color: #2e7d32; padding: 6px 10px; border-radius: 4px; font-weight: bold; white-space: nowrap;">✓ Godkänd</span>';
        } else {
            actions += '<button class="btn btn-success" style="padding: 6px 12px; font-size: 13px;" onclick="approveReport(' + report.id + ')">Godkänn</button>';
        }
        
        actions += '</div>';
        tr.innerHTML += '<td>' + actions + '</td>';
        
        tbody.appendChild(tr);
    });
}

function approveReport(reportId) {
    if (!originalUser || (originalUser.role !== 'Admin' && originalUser.role !== 'Kontrollant')) {
        showError('Du har inte behörighet att godkänna rapporter');
        return;
    }
    
    showLoading(true);
    
    fetch('api.php?action=approve_report', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            report_id: reportId,
            admin_id: originalUser.id
        })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        showLoading(false);
        
        if (data.success) {
            showSuccess('Rapport godkänd!');
            loadOverview();
        } else {
            showError(data.error || 'Kunde inte godkänna rapport');
        }
    })
    .catch(function(error) {
        showLoading(false);
        console.error('Fel vid godkännande:', error);
        showError('Ett fel uppstod vid godkännande');
    });
}

function loadStatistics() {
    if (!currentUser) {
        return;
    }
    
    var year = document.getElementById('stats-year').value;
    
    showLoading(true);
    
    fetch('api.php?action=get_statistics&user_id=' + currentUser.id + '&year=' + year)
        .then(function(response) { return response.json(); })
        .then(function(data) {
            showLoading(false);
            
            if (data.success) {
                renderStatistics(data.statistics);
            } else {
                showError(data.error || 'Kunde inte ladda statistik');
            }
        })
        .catch(function(error) {
            showLoading(false);
            console.error('Fel vid laddning av statistik:', error);
            showError('Ett fel uppstod vid laddning av statistik');
        });
}

function renderStatistics(stats) {
    var summary = '<h3 style="margin-bottom: 15px;">Sammanfattning för ' + stats.year + '</h3>';
    summary += '<div style="background: #e3f2fd; padding: 15px; border-radius: 8px; margin-bottom: 20px;">';
    summary += '<div style="font-size: 15px; margin-bottom: 10px;"><b>Total frånvaro:</b> ' + (stats.total_absence || 0).toFixed(1) + ' timmar</div>';
    summary += '<div style="font-size: 15px; margin-bottom: 10px;"><b>Total övertid:</b> ' + (stats.total_overtime || 0).toFixed(1) + ' timmar</div>';
    summary += '<div style="font-size: 15px; margin-bottom: 10px;"><b>Sjukfrånvaro:</b> ' + (stats.sick_hours || 0).toFixed(1) + ' timmar</div>';
    summary += '<div style="font-size: 15px;"><b>Semester, ATK och Komp:</b> ' + (stats.leave_hours || 0).toFixed(1) + ' timmar</div>';
    summary += '</div>';
    
    document.getElementById('stats-summary').innerHTML = summary;
    
    var absenceBody = document.getElementById('stats-absence-body');
    absenceBody.innerHTML = '';
    
    for (var m = 1; m <= 12; m++) {
        var monthData = stats.absence_by_month[m] || {};
        
        var tr = document.createElement('tr');
        tr.innerHTML = '<td><strong>' + MONTHS[m] + '</strong></td>';
        
        REASONS.forEach(function(reason) {
            var hours = monthData[reason] || 0;
            tr.innerHTML += '<td>' + (hours > 0 ? hours.toFixed(1) + 'h' : '') + '</td>';
        });
        
        var monthTotal = 0;
        REASONS.forEach(function(reason) {
            monthTotal += monthData[reason] || 0;
        });
        tr.innerHTML += '<td><strong>' + (monthTotal > 0 ? monthTotal.toFixed(1) + 'h' : '') + '</strong></td>';
        
        absenceBody.appendChild(tr);
    }
    
    var overtimeBody = document.getElementById('stats-overtime-body');
    overtimeBody.innerHTML = '';
    
    for (var m = 1; m <= 12; m++) {
        var monthData = stats.overtime_by_month[m] || {};
        
        var tr = document.createElement('tr');
        tr.innerHTML = '<td><strong>' + MONTHS[m] + '</strong></td>';
        tr.innerHTML += '<td>' + (monthData.mertid > 0 ? monthData.mertid.toFixed(1) + 'h' : '') + '</td>';
        tr.innerHTML += '<td>' + (monthData.ot50 > 0 ? monthData.ot50.toFixed(1) + 'h' : '') + '</td>';
        tr.innerHTML += '<td>' + (monthData.ot100 > 0 ? monthData.ot100.toFixed(1) + 'h' : '') + '</td>';
        tr.innerHTML += '<td>' + (monthData.ot200 > 0 ? monthData.ot200.toFixed(1) + 'h' : '') + '</td>';
        
        var monthTotal = (monthData.mertid || 0) + (monthData.ot50 || 0) + (monthData.ot100 || 0) + (monthData.ot200 || 0);
        tr.innerHTML += '<td><strong>' + (monthTotal > 0 ? monthTotal.toFixed(1) + 'h' : '') + '</strong></td>';
        
        overtimeBody.appendChild(tr);
    }
}

function loadCompanyStatistics() {
    if (!originalUser || originalUser.role !== 'Admin') {
        showError('Du har inte behörighet att visa företagsstatistik');
        return;
    }
    
    var year = document.getElementById('company-stats-year').value;
    var month = document.getElementById('company-stats-month').value;
    
    showLoading(true);
    
    fetch('api.php?action=get_company_statistics&year=' + year + '&month=' + month)
        .then(function(response) { return response.json(); })
        .then(function(data) {
            showLoading(false);
            
            if (data.success) {
                renderCompanyStatistics(data.statistics);
            } else {
                showError(data.error || 'Kunde inte ladda företagsstatistik');
            }
        })
        .catch(function(error) {
            showLoading(false);
            console.error('Fel vid laddning av företagsstatistik:', error);
            showError('Ett fel uppstod vid laddning av företagsstatistik');
        });
}

function renderCompanyStatistics(stats) {
    var overviewHTML = '';
    overviewHTML += '<div class="stat-item success"><div class="stat-label">Totalt arbetade timmar</div><div class="stat-value">' + (stats.total_worked || 0).toFixed(1) + 'h</div></div>';
    overviewHTML += '<div class="stat-item warning"><div class="stat-label">Total frånvaro</div><div class="stat-value">' + (stats.total_absence || 0).toFixed(1) + 'h</div></div>';
    overviewHTML += '<div class="stat-item"><div class="stat-label">Total ledighet</div><div class="stat-value">' + (stats.total_leave || 0).toFixed(1) + 'h</div></div>';
    overviewHTML += '<div class="stat-item"><div class="stat-label">Total övertid</div><div class="stat-value">' + (stats.total_overtime || 0).toFixed(1) + 'h</div></div>';
    overviewHTML += '<div class="stat-item danger"><div class="stat-label">Sjukfrånvaro</div><div class="stat-value">' + (stats.sick_hours || 0).toFixed(1) + 'h</div><div class="stat-subtext">' + (stats.sick_percent || 0).toFixed(1) + '% av total tid</div></div>';
    overviewHTML += '<div class="stat-item"><div class="stat-label">Antal användare</div><div class="stat-value">' + (stats.user_count || 0) + '</div></div>';
    
    document.getElementById('company-overview-stats').innerHTML = overviewHTML;
    
    var teamStatsHTML = '<div class="table-wrapper"><table><thead><tr><th>Skiftlag</th><th>Användare</th><th>Arbetade h</th><th>Frånvaro h</th><th>Ledighet h</th><th>Övertid h</th></tr></thead><tbody>';
    
    stats.by_team.forEach(function(team) {
        teamStatsHTML += '<tr>';
        teamStatsHTML += '<td><strong>' + getTeamLabel(team.team) + '</strong></td>';
        teamStatsHTML += '<td>' + team.user_count + '</td>';
        teamStatsHTML += '<td>' + (team.worked || 0).toFixed(1) + 'h</td>';
        teamStatsHTML += '<td>' + (team.absence || 0).toFixed(1) + 'h</td>';
        teamStatsHTML += '<td>' + (team.leave || 0).toFixed(1) + 'h</td>';
        teamStatsHTML += '<td>' + (team.overtime || 0).toFixed(1) + 'h</td>';
        teamStatsHTML += '</tr>';
    });
    
    teamStatsHTML += '</tbody></table></div>';
    document.getElementById('team-stats-container').innerHTML = teamStatsHTML;
    
    var topWorkedHTML = '<div style="background: #f8f9fa; padding: 10px; border-radius: 8px;">';
    stats.top_worked.forEach(function(user, index) {
        topWorkedHTML += '<div style="padding: 8px; border-bottom: 1px solid #dee2e6;"><strong>' + (index + 1) + '.</strong> ' + user.name + ' <span style="float: right; font-weight: bold;">' + (user.hours || 0).toFixed(1) + 'h</span></div>';
    });
    topWorkedHTML += '</div>';
    document.getElementById('top-worked-hours').innerHTML = topWorkedHTML;
    
    var topAbsenceHTML = '<div style="background: #f8f9fa; padding: 10px; border-radius: 8px;">';
    stats.top_absence.forEach(function(user, index) {
        topAbsenceHTML += '<div style="padding: 8px; border-bottom: 1px solid #dee2e6;"><strong>' + (index + 1) + '.</strong> ' + user.name + ' <span style="float: right; font-weight: bold;">' + (user.hours || 0).toFixed(1) + 'h</span></div>';
    });
    topAbsenceHTML += '</div>';
    document.getElementById('top-absence').innerHTML = topAbsenceHTML;
    
    var topOvertimeHTML = '<div style="background: #f8f9fa; padding: 10px; border-radius: 8px;">';
    stats.top_overtime.forEach(function(user, index) {
        topOvertimeHTML += '<div style="padding: 8px; border-bottom: 1px solid #dee2e6;"><strong>' + (index + 1) + '.</strong> ' + user.name + ' <span style="float: right; font-weight: bold;">' + (user.hours || 0).toFixed(1) + 'h</span></div>';
    });
    topOvertimeHTML += '</div>';
    document.getElementById('top-overtime').innerHTML = topOvertimeHTML;
    
    var usersBody = document.getElementById('company-users-body');
    usersBody.innerHTML = '';
    
    stats.users.forEach(function(user) {
        var tr = document.createElement('tr');
        var sickPercent = user.worked > 0 ? ((user.absence / user.worked) * 100) : 0;
        
        tr.innerHTML = '<td><strong>' + user.name + '</strong></td>';
        tr.innerHTML += '<td>' + getTeamLabel(user.team) + '</td>';
        tr.innerHTML += '<td>' + (user.worked || 0).toFixed(1) + 'h</td>';
        tr.innerHTML += '<td>' + (user.absence || 0).toFixed(1) + 'h</td>';
        tr.innerHTML += '<td>' + (user.leave || 0).toFixed(1) + 'h</td>';
        tr.innerHTML += '<td>' + (user.total_overtime || 0).toFixed(1) + 'h</td>';
        tr.innerHTML += '<td>' + sickPercent.toFixed(1) + '%</td>';
        
        usersBody.appendChild(tr);
    });
}

function switchToAdmin() {
    if (!originalUser || originalUser.role !== 'Admin') {
        showError('Du har inte behörighet till admin-panelen');
        return;
    }
    
    if (editingUserData) {
        cancelEditUser();
    }
    
    var tabs = document.querySelectorAll('.tab-content');
    tabs.forEach(function(tab) {
        tab.classList.remove('active');
    });
    
    var buttons = document.querySelectorAll('.tab-button');
    buttons.forEach(function(btn) {
        btn.classList.remove('active');
    });
    
    document.getElementById('tab-content-admin').classList.add('active');
    document.getElementById('tab-admin').classList.add('active');
    
    loadUsers();
    loadSettings();
    loadTeamNames();
    loadPhrases();
}

function loadUsers() {
    showLoading(true);
    
    fetch('api.php?action=list_all_users')
        .then(function(response) { return response.json(); })
        .then(function(data) {
            showLoading(false);
            
            if (data.success) {
                renderUsers(data.users);
            } else {
                showError(data.error || 'Kunde inte ladda användare');
            }
        })
        .catch(function(error) {
            showLoading(false);
            console.error('Fel vid laddning av användare:', error);
            showError('Ett fel uppstod vid laddning av användare');
        });
}

function renderUsers(users) {
    var tbody = document.getElementById('users-body');
    tbody.innerHTML = '';
    
    if (users.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 40px; color: #666;">Inga användare att visa</td></tr>';
        return;
    }
    
    users.forEach(function(user) {
        var tr = document.createElement('tr');
        
        var teamLabel = getTeamLabel(user.team);
        
        var statusText = user.hidden ? 'Dold' : 'Aktiv';
        var statusColor = user.hidden ? '#dc3545' : '#28a745';
        
        tr.innerHTML = '<td><strong>' + user.name + '</strong></td>';
        tr.innerHTML += '<td>' + teamLabel + '</td>';
        tr.innerHTML += '<td>' + user.role + '</td>';
        tr.innerHTML += '<td><span style="background: ' + statusColor + '; color: white; padding: 4px 10px; border-radius: 4px; font-weight: bold;">' + statusText + '</span></td>';
        
        var actions = '<div style="display: flex; gap: 5px; flex-wrap: wrap;">';
        actions += '<button class="btn btn-info" style="padding: 6px 12px; font-size: 13px;" onclick="editUserSchedule(' + user.id + ', \'' + user.name + '\', \'' + user.team + '\')">Schema</button>';
        actions += '<button class="btn btn-primary" style="padding: 6px 12px; font-size: 13px;" onclick="editUser(' + user.id + ')">Redigera</button>';
        
        if (user.name !== 'Admin') {
            actions += '<button class="btn btn-danger" style="padding: 6px 12px; font-size: 13px;" onclick="deleteUser(' + user.id + ', \'' + user.name + '\')">Ta bort</button>';
        }
        
        actions += '</div>';
        tr.innerHTML += '<td>' + actions + '</td>';
        
        tbody.appendChild(tr);
    });
}

function showCreateUserDialog() {
    editingUserId = null;
    document.getElementById('user-dialog-title').textContent = 'Skapa användare';
    document.getElementById('dialog-user-name').value = '';
    document.getElementById('dialog-user-team').value = 'A';
    document.getElementById('dialog-user-role').value = 'Användare';
    document.getElementById('dialog-user-password').value = '';
    document.getElementById('dialog-user-hidden').checked = false;
    document.getElementById('user-dialog').classList.add('visible');
}

function editUser(userId) {
    showLoading(true);
    
    fetch('api.php?action=get_user&user_id=' + userId)
        .then(function(response) { return response.json(); })
        .then(function(data) {
            showLoading(false);
            
            if (data.success) {
                editingUserId = userId;
                document.getElementById('user-dialog-title').textContent = 'Redigera användare';
                document.getElementById('dialog-user-name').value = data.user.name;
                document.getElementById('dialog-user-team').value = data.user.team;
                document.getElementById('dialog-user-role').value = data.user.role;
                document.getElementById('dialog-user-password').value = '';
                document.getElementById('dialog-user-hidden').checked = data.user.hidden == 1;
                document.getElementById('user-dialog').classList.add('visible');
            } else {
                showError(data.error || 'Kunde inte ladda användare');
            }
        })
        .catch(function(error) {
            showLoading(false);
            console.error('Fel:', error);
            showError('Ett fel uppstod');
        });
}

function saveUser() {
    var name = document.getElementById('dialog-user-name').value.trim();
    var team = document.getElementById('dialog-user-team').value;
    var role = document.getElementById('dialog-user-role').value;
    var password = document.getElementById('dialog-user-password').value;
    var hidden = document.getElementById('dialog-user-hidden').checked;
    
    if (!name) {
        showError('Namn måste anges');
        return;
    }
    
    if (!editingUserId && !password) {
        showError('Lösenord måste anges för nya användare');
        return;
    }
    
    showLoading(true);
    
    var data = {
        name: name,
        team: team,
        role: role,
        hidden: hidden
    };
    
    if (editingUserId) {
        data.user_id = editingUserId;
    }
    
    if (password) {
        data.password = password;
    }
    
    var action = editingUserId ? 'update_user' : 'create_user';
    
    fetch('api.php?action=' + action, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        showLoading(false);
        
        if (data.success) {
            showSuccess(editingUserId ? 'Användare uppdaterad!' : 'Användare skapad!');
            closeUserDialog();
            loadUsers();
            loadUserList();
        } else {
            showError(data.error || 'Kunde inte spara användare');
        }
    })
    .catch(function(error) {
        showLoading(false);
        console.error('Fel:', error);
        showError('Ett fel uppstod vid sparande');
    });
}

function deleteUser(userId, userName) {
    if (!confirm('Är du säker på att du vill ta bort användaren "' + userName + '"?\n\nDetta kommer att radera all data för användaren.')) {
        return;
    }
    
    showLoading(true);
    
    fetch('api.php?action=delete_user', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ user_id: userId })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        showLoading(false);
        
        if (data.success) {
            showSuccess('Användare borttagen!');
            loadUsers();
            loadUserList();
        } else {
            showError(data.error || 'Kunde inte ta bort användare');
        }
    })
    .catch(function(error) {
        showLoading(false);
        console.error('Fel:', error);
        showError('Ett fel uppstod');
    });
}

function closeUserDialog() {
    document.getElementById('user-dialog').classList.remove('visible');
    editingUserId = null;
}

function loadTeamNames(callback) {
    fetch('api.php?action=get_team_names')
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success && data.team_names) {
                TEAM_NAMES = data.team_names;
                
                if (document.getElementById('admin-team-a-name')) {
                    document.getElementById('admin-team-a-name').value = TEAM_NAMES.A || '';
                    document.getElementById('admin-team-b-name').value = TEAM_NAMES.B || '';
                    document.getElementById('admin-team-c-name').value = TEAM_NAMES.C || '';
                    document.getElementById('admin-team-d-name').value = TEAM_NAMES.D || '';
                }
            }
            if (callback) callback();
        })
        .catch(function(error) {
            console.error('Fel vid laddning av skiftlagsnamn:', error);
            if (callback) callback();
        });
}

function saveTeamNames() {
    var teamNames = {
        A: document.getElementById('admin-team-a-name').value.trim() || 'Skiftlag 1',
        B: document.getElementById('admin-team-b-name').value.trim() || 'Skiftlag 2',
        C: document.getElementById('admin-team-c-name').value.trim() || 'Skiftlag 3',
        D: document.getElementById('admin-team-d-name').value.trim() || 'Dagtid'
    };
    
    showLoading(true);
    
    fetch('api.php?action=save_team_names', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ team_names: teamNames })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        showLoading(false);
        
        if (data.success) {
            TEAM_NAMES = teamNames;
            showSuccess('Skiftlagsnamn sparade!');
            updateUserDisplay();
            loadUsers();
        } else {
            showError(data.error || 'Kunde inte spara skiftlagsnamn');
        }
    })
    .catch(function(error) {
        showLoading(false);
        console.error('Fel:', error);
        showError('Ett fel uppstod');
    });
}

function loadSettings() {
    fetch('api.php?action=get_settings')
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                document.getElementById('admin-start-date').value = data.settings.start_date || '';
                
                var anchors = data.settings.team_anchors || {};
                document.getElementById('admin-anchor-a').value = anchors.A || '';
                document.getElementById('admin-anchor-b').value = anchors.B || '';
                document.getElementById('admin-anchor-c').value = anchors.C || '';
            }
        })
        .catch(function(error) {
            console.error('Fel vid laddning av inställningar:', error);
        });
}

function saveSettings() {
    var startDate = document.getElementById('admin-start-date').value;
    var anchorA = document.getElementById('admin-anchor-a').value;
    var anchorB = document.getElementById('admin-anchor-b').value;
    var anchorC = document.getElementById('admin-anchor-c').value;
    
    if (!startDate || !anchorA || !anchorB || !anchorC) {
        showError('Alla datum måste anges');
        return;
    }
    
    showLoading(true);
    
    fetch('api.php?action=save_settings', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            start_date: startDate,
            team_anchors: {
                A: anchorA,
                B: anchorB,
                C: anchorC
            }
        })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        showLoading(false);
        
        if (data.success) {
            showSuccess('Inställningar sparade!');
        } else {
            showError(data.error || 'Kunde inte spara inställningar');
        }
    })
    .catch(function(error) {
        showLoading(false);
        console.error('Fel:', error);
        showError('Ett fel uppstod');
    });
}

function loadPhrases() {
    fetch('api.php?action=get_phrases')
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                renderPhrases(data.phrases);
            }
        })
        .catch(function(error) {
            console.error('Fel vid laddning av fraser:', error);
        });
}

function renderPhrases(phrases) {
    var container = document.getElementById('phrases-list');
    
    if (phrases.length === 0) {
        container.innerHTML = '<p style="color: #666;">Inga fraser än. Lägg till din första fras ovan.</p>';
        return;
    }
    
    container.innerHTML = '';
    
    phrases.forEach(function(phrase) {
        var div = document.createElement('div');
        div.style.cssText = 'background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 12px; display: flex; justify-content: space-between; align-items: center;';
        
        div.innerHTML = '<span style="flex: 1;">' + phrase.phrase + '</span>';
        div.innerHTML += '<button class="btn btn-danger" style="padding: 6px 12px; font-size: 13px;" onclick="deletePhrase(' + phrase.id + ')">Ta bort</button>';
        
        container.appendChild(div);
    });
}

function addPhrase() {
    var phrase = document.getElementById('new-phrase').value.trim();
    
    if (!phrase) {
        showError('Ange en fras');
        return;
    }
    
    showLoading(true);
    
    fetch('api.php?action=add_phrase', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ phrase: phrase })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        showLoading(false);
        
        if (data.success) {
            showSuccess('Fras tillagd!');
            document.getElementById('new-phrase').value = '';
            loadPhrases();
        } else {
            showError(data.error || 'Kunde inte lägga till fras');
        }
    })
    .catch(function(error) {
        showLoading(false);
        console.error('Fel:', error);
        showError('Ett fel uppstod');
    });
}

function deletePhrase(phraseId) {
    if (!confirm('Är du säker på att du vill ta bort denna fras?')) {
        return;
    }
    
    showLoading(true);
    
    fetch('api.php?action=delete_phrase', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ phrase_id: phraseId })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        showLoading(false);
        
        if (data.success) {
            showSuccess('Fras borttagen!');
            loadPhrases();
        } else {
            showError(data.error || 'Kunde inte ta bort fras');
        }
    })
    .catch(function(error) {
        showLoading(false);
        console.error('Fel:', error);
        showError('Ett fel uppstod');
    });
}

function showLoading(visible) {
    var overlay = document.getElementById('loading');
    if (visible) {
        overlay.classList.add('visible');
    } else {
        overlay.classList.remove('visible');
    }
}

function showSuccess(message) {
    var alert = document.getElementById('alert-success');
    alert.textContent = message;
    alert.classList.add('visible');
    setTimeout(function() { alert.classList.remove('visible'); }, 5000);
}

function showError(message) {
    var alert = document.getElementById('alert-error');
    alert.textContent = '❌ ' + message;
    alert.classList.add('visible');
    setTimeout(function() { alert.classList.remove('visible'); }, 5000);
}

function showLoginError(message) {
    var errorDiv = document.getElementById('login-error');
    errorDiv.textContent = '❌ ' + message;
    errorDiv.classList.add('visible');
    setTimeout(function() { errorDiv.classList.remove('visible'); }, 5000);
}