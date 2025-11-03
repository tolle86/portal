// Ledighetsansökan - JavaScript modul

var currentLeaveFilter = 'all';
var pendingDenyRequestId = null;

// Ladda modulen när Ledighet-fliken aktiveras
function loadLeaveModule() {
    console.log('Laddar ledighetsmodul...');
    
    // Ladda HTML-innehåll
    fetch('modules/leave-request.html')
        .then(function(response) {
            if (!response.ok) throw new Error('Kunde inte ladda ledighetsmodul');
            return response.text();
        })
        .then(function(html) {
            document.getElementById('tab-content-leave').innerHTML = html;
            
            // Sätt dagens datum som minimum
            var today = new Date().toISOString().split('T')[0];
            document.getElementById('leave-from-date').min = today;
            document.getElementById('leave-to-date').min = today;
            
            // Ladda användarens ansökningar
            loadMyLeaveRequests();
            
            // Visa admin-sektion om användaren är Admin eller Kontrollant
            if (originalUser && (originalUser.role === 'Admin' || originalUser.role === 'Kontrollant')) {
                document.getElementById('admin-leave-section').style.display = 'block';
                loadAllLeaveRequests();
            }
        })
        .catch(function(error) {
            console.error('Fel vid laddning av ledighetsmodul:', error);
            document.getElementById('tab-content-leave').innerHTML = 
                '<div class="panel"><p style="color: #e74c3c;">❌ Kunde inte ladda ledighetsmodul. Kontrollera att modules/leave-request.html finns.</p></div>';
        });
}

// Beräkna antal dagar och timmar baserat på användarens schema
function calculateLeaveDays() {
    var fromDate = document.getElementById('leave-from-date').value;
    var toDate = document.getElementById('leave-to-date').value;
    
    if (!fromDate || !toDate) {
        document.getElementById('leave-calculation').style.display = 'none';
        return;
    }
    
    if (new Date(toDate) < new Date(fromDate)) {
        showError('Till-datum måste vara efter från-datum');
        document.getElementById('leave-calculation').style.display = 'none';
        return;
    }
    
    // Anropa API för att beräkna dagar och timmar
    showLoading(true);
    
    fetch('modules/leave-request-api.php?action=calculate_leave_days&user_id=' + currentUser.id + 
          '&from_date=' + fromDate + '&to_date=' + toDate)
        .then(function(response) { return response.json(); })
        .then(function(data) {
            showLoading(false);
            
            if (data.success) {
                var calc = document.getElementById('leave-calculation');
                var text = document.getElementById('leave-days-text');
                
                text.innerHTML = '<strong>' + data.days + ' arbetsdagar</strong>, ' + 
                                data.hours.toFixed(1) + ' timmar';
                
                calc.style.display = 'block';
            } else {
                showError(data.error || 'Kunde inte beräkna ledighet');
            }
        })
        .catch(function(error) {
            showLoading(false);
            console.error('Fel vid beräkning:', error);
            showError('Ett fel uppstod vid beräkning');
        });
}

// Skicka ledighetsansökan
function submitLeaveRequest(event) {
    event.preventDefault();
    
    var type = document.getElementById('leave-type').value;
    var fromDate = document.getElementById('leave-from-date').value;
    var toDate = document.getElementById('leave-to-date').value;
    var comment = document.getElementById('leave-comment').value;
    
    if (!type || !fromDate || !toDate) {
        showError('Alla obligatoriska fält måste fyllas i');
        return false;
    }
    
    if (new Date(toDate) < new Date(fromDate)) {
        showError('Till-datum måste vara efter från-datum');
        return false;
    }
    
    showLoading(true);
    
    fetch('modules/leave-request-api.php?action=submit_leave_request', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            user_id: currentUser.id,
            type: type,
            from_date: fromDate,
            to_date: toDate,
            comment: comment
        })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        showLoading(false);
        
        if (data.success) {
            showSuccess('✅ Ledighetsansökan skickad!');
            resetLeaveForm();
            loadMyLeaveRequests();
            
            // Uppdatera admin-listan om användaren är admin
            if (originalUser && (originalUser.role === 'Admin' || originalUser.role === 'Kontrollant')) {
                loadAllLeaveRequests();
            }
        } else {
            showError(data.error || 'Kunde inte skicka ansökan');
        }
    })
    .catch(function(error) {
        showLoading(false);
        console.error('Fel vid skickande:', error);
        showError('Ett fel uppstod vid skickande av ansökan');
    });
    
    return false;
}

// Återställ formulär
function resetLeaveForm() {
    document.getElementById('leave-request-form').reset();
    document.getElementById('leave-calculation').style.display = 'none';
}

// Ladda användarens egna ansökningar
function loadMyLeaveRequests() {
    if (!currentUser) return;
    
    fetch('modules/leave-request-api.php?action=list_my_leave_requests&user_id=' + currentUser.id)
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                renderMyLeaveRequests(data.requests);
            } else {
                showError(data.error || 'Kunde inte ladda ansökningar');
            }
        })
        .catch(function(error) {
            console.error('Fel vid laddning av ansökningar:', error);
        });
}

// Rendera användarens ansökningar
function renderMyLeaveRequests(requests) {
    var tbody = document.getElementById('my-leave-requests-body');
    tbody.innerHTML = '';
    
    if (requests.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; padding: 40px; color: #666;">Du har inga ledighetsansökningar</td></tr>';
        return;
    }
    
    requests.forEach(function(req) {
        var tr = document.createElement('tr');
        
        var statusColor = '';
        var statusText = '';
        
        if (req.status === 'Pending') {
            statusColor = '#ffc107';
            statusText = '⏳ Väntar';
        } else if (req.status === 'Approved') {
            statusColor = '#28a745';
            statusText = '✅ Godkänd';
            tr.classList.add('approved');
        } else if (req.status === 'Denied') {
            statusColor = '#e74c3c';
            statusText = '❌ Nekad';
        }
        
        tr.innerHTML = '<td><strong>' + req.type + '</strong></td>';
        tr.innerHTML += '<td>' + req.from_date + '</td>';
        tr.innerHTML += '<td>' + req.to_date + '</td>';
        tr.innerHTML += '<td>' + req.days + '</td>';
        tr.innerHTML += '<td>' + req.hours.toFixed(1) + 'h</td>';
        tr.innerHTML += '<td><span style="background: ' + statusColor + '; color: white; padding: 4px 10px; border-radius: 4px; font-weight: bold; font-size: 12px;">' + statusText + '</span></td>';
        tr.innerHTML += '<td>' + req.created_at + '</td>';
        tr.innerHTML += '<td>' + (req.comment || '-') + '</td>';
        
        var actions = '';
        if (req.status === 'Pending') {
            actions = '<button class="btn btn-danger" style="padding: 6px 12px; font-size: 12px;" onclick="cancelLeaveRequest(' + req.id + ')">Avbryt</button>';
        } else if (req.status === 'Denied' && req.deny_reason) {
            actions = '<button class="btn btn-info" style="padding: 6px 12px; font-size: 12px;" onclick="showDenyReason(\'' + req.deny_reason.replace(/'/g, "\\'") + '\')">Visa orsak</button>';
        } else {
            actions = '-';
        }
        
        tr.innerHTML += '<td>' + actions + '</td>';
        
        tbody.appendChild(tr);
    });
}

// Avbryt egen ansökan (bara pending)
function cancelLeaveRequest(requestId) {
    if (!confirm('Är du säker på att du vill avbryta denna ansökan?')) {
        return;
    }
    
    showLoading(true);
    
    fetch('modules/leave-request-api.php?action=cancel_leave_request', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ request_id: requestId })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        showLoading(false);
        
        if (data.success) {
            showSuccess('Ansökan avbruten');
            loadMyLeaveRequests();
        } else {
            showError(data.error || 'Kunde inte avbryta ansökan');
        }
    })
    .catch(function(error) {
        showLoading(false);
        console.error('Fel:', error);
        showError('Ett fel uppstod');
    });
}

// Visa varför ansökan nekades
function showDenyReason(reason) {
    alert('Orsak till nekande:\n\n' + reason);
}

// Ladda alla ansökningar (Admin/Kontrollant)
function loadAllLeaveRequests() {
    fetch('modules/leave-request-api.php?action=list_all_leave_requests')
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                renderAllLeaveRequests(data.requests);
            } else {
                showError(data.error || 'Kunde inte ladda ansökningar');
            }
        })
        .catch(function(error) {
            console.error('Fel vid laddning av ansökningar:', error);
        });
}

// Rendera alla ansökningar (Admin)
function renderAllLeaveRequests(requests) {
    var tbody = document.getElementById('all-leave-requests-body');
    tbody.innerHTML = '';
    
    // Filtrera baserat på aktuellt filter
    var filtered = requests.filter(function(req) {
        if (currentLeaveFilter === 'all') return true;
        if (currentLeaveFilter === 'pending') return req.status === 'Pending';
        if (currentLeaveFilter === 'approved') return req.status === 'Approved';
        if (currentLeaveFilter === 'denied') return req.status === 'Denied';
        return true;
    });
    
    if (filtered.length === 0) {
        tbody.innerHTML = '<tr><td colspan="11" style="text-align: center; padding: 40px; color: #666;">Inga ansökningar att visa</td></tr>';
        return;
    }
    
    filtered.forEach(function(req) {
        var tr = document.createElement('tr');
        
        var statusColor = '';
        var statusText = '';
        
        if (req.status === 'Pending') {
            statusColor = '#ffc107';
            statusText = '⏳ Väntar';
        } else if (req.status === 'Approved') {
            statusColor = '#28a745';
            statusText = '✅ Godkänd';
            tr.classList.add('approved');
        } else if (req.status === 'Denied') {
            statusColor = '#e74c3c';
            statusText = '❌ Nekad';
        }
        
        tr.innerHTML = '<td><strong>' + req.user_name + '</strong></td>';
        tr.innerHTML += '<td>' + getTeamLabel(req.team) + '</td>';
        tr.innerHTML += '<td>' + req.type + '</td>';
        tr.innerHTML += '<td>' + req.from_date + '</td>';
        tr.innerHTML += '<td>' + req.to_date + '</td>';
        tr.innerHTML += '<td>' + req.days + '</td>';
        tr.innerHTML += '<td>' + req.hours.toFixed(1) + 'h</td>';
        tr.innerHTML += '<td><span style="background: ' + statusColor + '; color: white; padding: 4px 10px; border-radius: 4px; font-weight: bold; font-size: 12px;">' + statusText + '</span></td>';
        tr.innerHTML += '<td>' + req.created_at + '</td>';
        tr.innerHTML += '<td>' + (req.comment || '-') + '</td>';
        
        var actions = '';
        if (req.status === 'Pending') {
            actions = '<div style="display: flex; gap: 5px;">';
            actions += '<button class="btn btn-success" style="padding: 6px 12px; font-size: 12px;" onclick="approveLeaveRequest(' + req.id + ')">✅ Godkänn</button>';
            actions += '<button class="btn btn-danger" style="padding: 6px 12px; font-size: 12px;" onclick="openDenyDialog(' + req.id + ')">❌ Neka</button>';
            actions += '</div>';
        } else {
            actions = '-';
        }
        
        tr.innerHTML += '<td>' + actions + '</td>';
        
        tbody.appendChild(tr);
    });
}

// Filtrera ansökningar (Admin)
function filterLeaveRequests(filter) {
    currentLeaveFilter = filter;
    loadAllLeaveRequests();
}

// Godkänn ledighetsansökan (Admin)
function approveLeaveRequest(requestId) {
    if (!confirm('Är du säker på att du vill godkänna denna ledighetsansökan?')) {
        return;
    }
    
    showLoading(true);
    
    fetch('modules/leave-request-api.php?action=approve_leave_request', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            request_id: requestId,
            admin_id: originalUser.id
        })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        showLoading(false);
        
        if (data.success) {
            showSuccess('✅ Ledighetsansökan godkänd! Ledigheten har lagts till i månadsrapporten.');
            loadAllLeaveRequests();
        } else {
            showError(data.error || 'Kunde inte godkänna ansökan');
        }
    })
    .catch(function(error) {
        showLoading(false);
        console.error('Fel:', error);
        showError('Ett fel uppstod');
    });
}

// Öppna dialog för att neka ansökan
function openDenyDialog(requestId) {
    pendingDenyRequestId = requestId;
    document.getElementById('deny-reason').value = '';
    document.getElementById('deny-leave-dialog').classList.add('visible');
}

// Stäng deny-dialog
function closeDenyDialog() {
    document.getElementById('deny-leave-dialog').classList.remove('visible');
    pendingDenyRequestId = null;
}

// Bekräfta nekande av ansökan
function confirmDenyLeave() {
    var reason = document.getElementById('deny-reason').value.trim();
    
    if (!reason) {
        showError('Du måste ange en orsak för att neka ansökan');
        return;
    }
    
    showLoading(true);
    
    fetch('modules/leave-request-api.php?action=deny_leave_request', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            request_id: pendingDenyRequestId,
            admin_id: originalUser.id,
            reason: reason
        })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        showLoading(false);
        closeDenyDialog();
        
        if (data.success) {
            showSuccess('❌ Ledighetsansökan nekad');
            loadAllLeaveRequests();
        } else {
            showError(data.error || 'Kunde inte neka ansökan');
        }
    })
    .catch(function(error) {
        showLoading(false);
        console.error('Fel:', error);
        showError('Ett fel uppstod');
    });
}