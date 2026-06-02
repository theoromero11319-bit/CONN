<?php
session_start();

// SECURITY CHECK: Strictly restrict access to the master admin account
if (!isset($_SESSION['usercode']) || strtolower(trim($_SESSION['usercode'])) !== 'admin') {
    header("Location: index.php");
    exit();
}

// Database Connection Parameters
$serverName = "MSHPSERVER";
$connectionOptions = [
    "Database" => "LiveDB_MSHAP",
    "Uid" => "sa",
    "PWD" => "p@ssw0rd",
    "TrustServerCertificate" => true,
    "Encrypt" => false
];

$conn = sqlsrv_connect($serverName, $connectionOptions);
if ($conn === false) {
    die("<pre>Database Engine Offline: " . print_r(sqlsrv_errors(), true) . "</pre>");
}

$success_message = "";
$error_message = "";

// 1. HANDLE NEW USER PROFILE PROVISIONING (From Modal Form)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action_create'])) {
    $new_usercode = strtolower(trim($_POST['usercode']));
    $new_fullname = trim($_POST['fullname']);
    $new_password = trim($_POST['password']);
    $new_role     = $_POST['rolepermissions'];

    if (!empty($new_usercode) && !empty($new_fullname) && !empty($new_password)) {
        $checkSql = "SELECT UserCode FROM [dbo].[appTrackingUsers] WHERE LOWER(RTRIM(UserCode)) = LOWER(?)";
        $checkStmt = sqlsrv_query($conn, $checkSql, array($new_usercode));
        
        if (sqlsrv_has_rows($checkStmt)) {
            $error_message = "The User ID Code '$new_usercode' is already taken.";
        } else {
            $secure_hash = password_hash($new_password, PASSWORD_BCRYPT);
            $insertSql = "INSERT INTO [dbo].[appTrackingUsers] (UserCode, FullName, PasswordHash, RolePermissions, IsActive, DateCreated) VALUES (?, ?, ?, ?, 1, GETDATE())";
            $insertStmt = sqlsrv_query($conn, $insertSql, array($new_usercode, $new_fullname, $secure_hash, $new_role));

            if ($insertStmt !== false) {
                $success_message = "Account for '$new_fullname' provisioned successfully!";
                $desc = "Admin provisioned a new system profile for '{$new_usercode}' [{$new_role}]";
                sqlsrv_query($conn, "INSERT INTO [dbo].[appTrackingLogs] (UserCode, ActionType, LogDescription) VALUES ('admin', 'REGISTRATION', ?)", array($desc));
            }
        }
    }
}

// 2. HANDLE MASTER ADMIN SYSTEM CREDENTIAL UPDATES (Username / Password Change)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action_update_admin'])) {
    $current_password = trim($_POST['current_password']);
    $new_password     = trim($_POST['new_password']);

    $adminSql = "SELECT RTRIM(PasswordHash) as PasswordHash FROM [dbo].[appTrackingUsers] WHERE UserCode = 'admin'";
    $adminStmt = sqlsrv_query($conn, $adminSql);
    $adminRow = sqlsrv_fetch_array($adminStmt, SQLSRV_FETCH_ASSOC);

    if (password_verify($current_password, $adminRow['PasswordHash'])) {
        if (!empty($new_password)) {
            $updatedHash = password_hash($new_password, PASSWORD_BCRYPT);
            $updateSql = "UPDATE [dbo].[appTrackingUsers] SET PasswordHash = ? WHERE UserCode = 'admin'";
            sqlsrv_query($conn, $updateSql, array($updatedHash));
            $success_message = "Admin master security settings updated safely!";
            sqlsrv_query($conn, "INSERT INTO [dbo].[appTrackingLogs] (UserCode, ActionType, LogDescription) VALUES ('admin', 'SECURITY', 'Master admin security access variables altered successfully.')");
        } else {
            $success_message = "Admin identification parameters synchronized.";
        }
    } else {
        $error_message = "Security Violation: Current master verification passphrase invalid.";
    }
}

// 3. HANDLE ACCOUNT STATUS TOGGLES (Kill Connection / Restore Link)
if (isset($_GET['toggle_id']) && isset($_GET['current_status'])) {
    $targetID = (int)$_GET['toggle_id'];
    $newStatus = ((int)$_GET['current_status'] === 1) ? 0 : 1;
    $logAction = ($newStatus === 1) ? 'ACTIVATE' : 'DEACTIVATE';

    $userCheckStmt = sqlsrv_query($conn, "SELECT UserCode FROM [dbo].[appTrackingUsers] WHERE UserSysID = ?", array($targetID));
    $userRow = sqlsrv_fetch_array($userCheckStmt, SQLSRV_FETCH_ASSOC);
    $targetUser = trim($userRow['UserCode']);

    if ($targetUser !== 'admin') { 
        sqlsrv_query($conn, "UPDATE [dbo].[appTrackingUsers] SET IsActive = ? WHERE UserSysID = ?", array($newStatus, $targetID));
        $desc = "Admin changed account status of user code '{$targetUser}' to " . ($newStatus === 1 ? 'ACTIVE' : 'DISABLED');
        sqlsrv_query($conn, "INSERT INTO [dbo].[appTrackingLogs] (UserCode, ActionType, LogDescription) VALUES ('admin', ?, ?)", array($logAction, $desc));
    }
    header("Location: admin_control.php");
    exit();
}

$usersStmt = sqlsrv_query($conn, "SELECT UserSysID, RTRIM(UserCode) as UserCode, RTRIM(FullName) as FullName, RolePermissions, IsActive, LastLoginDateTime FROM [dbo].[appTrackingUsers] WHERE UserCode != 'admin' ORDER BY UserSysID DESC");

// Fetch logs and handle array compilation
$logsQueryStmt = sqlsrv_query($conn, "SELECT TOP 50 RTRIM(UserCode) as UserCode, ActionType, LogDescription, LogDateTime FROM [dbo].[appTrackingLogs] ORDER BY LogDateTime DESC");
$logsArray = [];
if ($logsQueryStmt !== false) {
    while ($row = sqlsrv_fetch_array($logsQueryStmt, SQLSRV_FETCH_ASSOC)) {
        $logsArray[] = $row;
    }
}
$logsArray = array_reverse($logsArray);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSHAP Master Admin Center</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap">
    <style>
        :root {
            /* Clinical Hospital Theme Palette */
            --bg-canvas: #f4f7f9;
            --sidebar-bg: #1e293b;
            --panel-bg: #ffffff;
            --panel-border: #e2e8f0;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --hospital-primary: #0284c7;  /* Medical Cyan / Blue */
            --hospital-secondary: #0f172a;
            --hospital-hover: #0369a1;
            
            /* Medical Status Indicators */
            --clinical-green: #10b981;
            --clinical-red: #ef4444;
            --clinical-amber: #f59e0b;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
        
        body { 
            background-color: var(--bg-canvas); 
            color: var(--text-main); 
            display: flex; 
            min-height: 100vh; 
        }

        /* CLINICAL SIDEBAR LAYOUT */
        .sidebar { 
            width: 280px; 
            background: var(--sidebar-bg); 
            color: white; 
            display: flex; 
            flex-direction: column; 
            padding: 30px 20px; 
            position: fixed; 
            top: 0; bottom: 0; left: 0; 
            z-index: 100; 
            box-shadow: 4px 0 15px rgba(15, 23, 42, 0.05);
        }
        
        .sidebar-brand { 
            font-size: 1.2rem; 
            font-weight: 800; 
            color: #ffffff; 
            margin-bottom: 35px; 
            border-bottom: 1px solid rgba(255, 255, 255, 0.08); 
            padding-bottom: 20px; 
            text-transform: uppercase; 
            letter-spacing: 0.75px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .sidebar-brand span { color: #38bdf8; }
        
        .sidebar-menu { display: flex; flex-direction: column; gap: 8px; flex-grow: 1; }
        .menu-btn { 
            display: flex; 
            align-items: center; 
            background: transparent; 
            border: none; 
            color: #94a3b8; 
            padding: 12px 16px; 
            border-radius: 8px; 
            font-size: 0.9rem; 
            font-weight: 600; 
            cursor: pointer; 
            text-decoration: none; 
            transition: all 0.15s ease; 
            text-align: left; 
        }
        .menu-btn:hover { background: rgba(255, 255, 255, 0.04); color: white; }
        .menu-btn.active { background: var(--hospital-primary); color: white; }
        
        .logout-btn { background: rgba(239, 68, 68, 0.1); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.2); margin-top: auto; }
        .logout-btn:hover { background: #b91c1c; color: white; border-color: #b91c1c; }

        /* CONTENT CONTAINER WORKSPACE */
        .main-workspace { margin-left: 280px; padding: 40px; flex-grow: 1; width: calc(100% - 280px); }
        .workspace-header { margin-bottom: 30px; border-bottom: 1px solid var(--panel-border); padding-bottom: 15px; }
        .workspace-header h1 { font-size: 1.6rem; font-weight: 700; color: var(--hospital-secondary); }
        
        /* Dashboard Layout Split Matrix */
        .dashboard-grid { display: grid; grid-template-columns: 1fr; gap: 30px; }
        @media (min-width: 1300px) { .dashboard-grid { grid-template-columns: 1fr 460px; } }
        
        /* DATA PANELS */
        .card { 
            background: var(--panel-bg); 
            border: 1px solid var(--panel-border); 
            border-radius: 12px; 
            padding: 24px; 
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02), 0 2px 4px -1px rgba(0, 0, 0, 0.02); 
        }
        .card h2 { 
            font-size: 0.95rem; 
            font-weight: 700; 
            margin-bottom: 20px; 
            border-bottom: 1px solid var(--panel-border); 
            padding-bottom: 12px; 
            color: #475569; 
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* MEDICAL CONTROLS AND SYSTEM FORMS */
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 0.75rem; font-weight: 700; color: #475569; margin-bottom: 6px; text-transform: uppercase; }
        .form-control { 
            width: 100%; 
            height: 40px; 
            padding: 0 12px; 
            border: 1px solid #cbd5e1; 
            border-radius: 8px; 
            font-size: 0.875rem; 
            color: var(--text-main);
            background: #ffffff;
        }
        .form-control:focus { outline: none; border-color: var(--hospital-primary); box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15); }
        select.form-control { appearance: none; background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23475569' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e"); background-repeat: no-repeat; background-position: right 12px center; background-size: 14px; }
        
        .btn-submit { 
            width: 100%; 
            height: 42px; 
            background: var(--hospital-primary); 
            color: white; 
            border: none; 
            border-radius: 8px; 
            font-weight: 600; 
            font-size: 0.9rem; 
            cursor: pointer; 
            transition: background 0.15s;
        }
        .btn-submit:hover { background: var(--hospital-hover); }

        /* REFINED DISMISSABLE HEALTHCARE NOTIFICATION MESSAGES */
        .alert { padding: 14px; border-radius: 8px; font-size: 0.875rem; font-weight: 500; margin-bottom: 25px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
        .alert-danger { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; }

        /* HOSPITAL AUDIT DIRECTORY TABLES */
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.875rem; }
        th { background: #f8fafc; padding: 14px; font-weight: 600; color: #64748b; border-bottom: 2px solid var(--panel-border); font-size: 0.75rem; text-transform: uppercase; }
        td { padding: 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; color: #334155; }
        tr:hover td { background-color: #f8fafc; }
        
        /* INTERFACE BADGES */
        .badge { display: inline-block; padding: 4px 8px; border-radius: 6px; font-size: 0.725rem; font-weight: 700; text-transform: uppercase; }
        .badge-active { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .badge-disabled { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .badge-role { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
        
        .btn-toggle { font-size: 0.75rem; font-weight: 600; padding: 6px 12px; border-radius: 6px; text-decoration: none; display: inline-block; text-transform: uppercase; transition: all 0.1s ease; }
        .btn-disable { background: #fee2e2; color: #b91c1c; }
        .btn-disable:hover { background: var(--clinical-red); color: white; }
        .btn-enable { background: #dcfce7; color: #15803d; }
        .btn-enable:hover { background: var(--clinical-green); color: white; }
        
        /* CLEAN CLINICAL SYSTEM LOG TERMINAL STREAM BOX */
        .terminal-box { 
            background: #ffffff; 
            border-radius: 8px; 
            padding: 16px; 
            height: 500px; 
            overflow-y: auto; 
            font-family: 'JetBrains Mono', monospace; 
            font-size: 0.8rem; 
            line-height: 1.6;
            color: #334155; 
            border: 1px solid #cbd5e1;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.03);
        }
        .log-entry { margin-bottom: 8px; border-bottom: 1px solid #f1f5f9; padding-bottom: 6px; }
        .log-time { color: #94a3b8; margin-right: 6px; }
        .log-user { color: var(--hospital-primary); font-weight: 600; margin-right: 4px; }
        .log-tag { display: inline-block; font-weight: 700; font-size: 0.7rem; padding: 2px 6px; border-radius: 4px; margin-right: 6px; text-transform: uppercase; }
        
        .tag-REGISTRATION { background: #e0f2fe; color: #0369a1; }
        .tag-SECURITY { background: #fee2e2; color: #991b1b; }
        .tag-ACTIVATE { background: #dcfce7; color: #166534; }
        .tag-DEACTIVATE { background: #fef3c7; color: #92400e; }
        .log-desc { color: #334155; }

        /* BACKDROP CLINICAL LIGHTBOX MODALS */
        .modal-overlay { position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(15, 23, 42, 0.4); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 1000; display: none; }
        .modal-content { background: white; border-radius: 12px; width: 100%; max-width: 440px; padding: 28px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04); border: 1px solid var(--panel-border); }
        .modal-header { font-size: 1.1rem; font-weight: 700; margin-bottom: 20px; color: var(--hospital-secondary); display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--panel-border); padding-bottom: 10px; }
        .btn-close-modal { background: none; border: none; font-size: 1.4rem; cursor: pointer; color: #94a3b8; line-height: 1; }
        .btn-close-modal:hover { color: #475569; }
    </style>
</head>
<body>

    <!-- LEFT SIDEBAR MANAGEMENT PANEL -->
    <div class="sidebar">
        <div class="sidebar-brand"><span>🏥</span> MSHAP Admin Portal</div>
        <div class="sidebar-menu">
            <a href="admin_control.php" class="menu-btn active">👥 User Account Profiles</a>
            <button class="menu-btn" onclick="openModal('createUserModal')">➕ Provision New Account</button>
            <button class="menu-btn" onclick="openModal('settingsModal')">⚙️ Security Parameters</button>
            <a href="dashboard.php" class="menu-btn">📊 Back to Main Dashboard</a>
            
            <a href="logout.php" class="menu-btn logout-btn">🔒 Terminate Session</a>
        </div>
    </div>

    <!-- MAIN OPERATIONS VIEWPORT CONTAINER -->
    <div class="main-workspace">
        <div class="workspace-header">
            <h1>System Security & Operations Directory</h1>
        </div>

        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">✅ <?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger">⚠️ <?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <div class="dashboard-grid">
            
            <!-- MASTER AUTHENTICATION DIRECTORY TABLE REGISTRY -->
            <div class="card">
                <h2>Active Authentication Registry Directory</h2>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>User ID Code</th>
                                <th>Full Name</th>
                                <th>System Role</th>
                                <th>Link State</th>
                                <th>Last Account Action</th>
                                <th style="text-align: center;">Operational Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = sqlsrv_fetch_array($usersStmt, SQLSRV_FETCH_ASSOC)): ?>
                                <tr>
                                    <td><strong><code><?php echo htmlspecialchars($row['UserCode']); ?></code></strong></td>
                                    <td><?php echo htmlspecialchars($row['FullName']); ?></td>
                                    <td><span class="badge badge-role"><?php echo htmlspecialchars($row['RolePermissions']); ?></span></td>
                                    <td>
                                        <span class="badge <?php echo ($row['IsActive'] == 1) ? 'badge-active' : 'badge-disabled'; ?>">
                                            <?php echo ($row['IsActive'] == 1) ? 'Active Link' : 'Suspended'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo $row['LastLoginDateTime'] ? $row['LastLoginDateTime']->format('M d, g:i A') : '<span style="color:#94a3b8">Never Active</span>'; ?></td>
                                    <td style="text-align: center;">
                                        <?php if ($row['IsActive'] == 1): ?>
                                            <a href="admin_control.php?toggle_id=<?php echo $row['UserSysID']; ?>&current_status=1" class="btn-toggle btn-disable" onclick="return confirm('Suspend tracking interface access options for this employee profile?');">Kill Link</a>
                                        <?php else: ?>
                                            <a href="admin_control.php?toggle_id=<?php echo $row['UserSysID']; ?>&current_status=0" class="btn-toggle btn-enable">Restore Link</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- AUDIT STREAM CLINICAL TERMINAL BOX -->
            <div class="card">
                <h2>Real-Time System Log Stream</h2>
                <div class="terminal-box">
                    <?php foreach($logsArray as $log): 
                        $cleanAction = htmlspecialchars($log['ActionType']);
                    ?>
                        <div class="log-entry">
                            <span class="log-time">[<?php echo $log['LogDateTime']->format('H:i:s'); ?>]</span>
                            <span class="log-user"><?php echo htmlspecialchars($log['UserCode']); ?>:</span>
                            <span class="log-tag tag-<?php echo $cleanAction; ?>"><?php echo $cleanAction; ?></span>
                            <span class="log-desc"><?php echo htmlspecialchars($log['LogDescription']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>
    </div>

    <!-- MODAL Lightbox Shell Windows -->
    <div id="createUserModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <span>➕ Register New System Account</span>
                <button class="btn-close-modal" onclick="closeModal('createUserModal')">&times;</button>
            </div>
            <form action="admin_control.php" method="POST">
                <input type="hidden" name="action_create" value="1">
                <div class="form-group">
                    <label>User ID Code (Username)</label>
                    <input type="text" name="usercode" class="form-control" required placeholder="e.g., jsmith" autocomplete="off">
                </div>
                <div class="form-group">
                    <label>Employee Full Name</label>
                    <input type="text" name="fullname" class="form-control" required placeholder="e.g., John Smith">
                </div>
                <div class="form-group">
                    <label>Temporary Password</label>
                    <input type="password" name="password" class="form-control" required placeholder="Create initial password">
                </div>
                <div class="form-group">
                    <label>Assigned Permission Role</label>
                    <select name="rolepermissions" class="form-control">
                        <option value="Viewer">Viewer (Read-Only Logs)</option>
                        <option value="Encoder">Encoder (Can Update Parameters)</option>
                        <option value="Admin">Admin (Full Control Suite)</option>
                    </select>
                </div>
                <button type="submit" class="btn-submit" style="margin-top:10px;">Provision User Profile</button>
            </form>
        </div>
    </div>

    <div id="settingsModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <span>⚙️ Account Security Options</span>
                <button class="btn-close-modal" onclick="closeModal('settingsModal')">&times;</button>
            </div>
            <form action="admin_control.php" method="POST">
                <input type="hidden" name="action_update_admin" value="1">
                
                <div class="form-group" style="background:#fef2f2; padding:12px; border-radius:8px; border:1px solid #fca5a5; margin-bottom:15px;">
                    <label style="color:#dc2626;">Confirm Current Password *</label>
                    <input type="password" name="current_password" class="form-control" required placeholder="Verify identity to update configuration">
                </div>
                
                <div class="form-group">
                    <label>Master Admin Account Username</label>
                    <input type="text" class="form-control" value="admin" disabled style="background:#f1f5f9; color:#94a3b8; cursor:not-allowed;">
                </div>
                
                <div class="form-group">
                    <label>Update Master Password</label>
                    <input type="password" name="new_password" class="form-control" placeholder="Enter clean new account password text">
                </div>
                
                <button type="submit" class="btn-submit" style="background:var(--hospital-secondary); margin-top:10px;">Commit Security Changes</button>
            </form>
        </div>
    </div>

    <script>
        function openModal(id) { document.getElementById(id).style.display = 'flex'; }
        function closeModal(id) { document.getElementById(id).style.display = 'none'; }
        
        window.onclick = function(event) {
            if (event.target.className === 'modal-overlay') {
                event.target.style.display = 'none';
            }
        }

        // Auto-scroll terminal stream down to most recent activity item baseline safely
        var terminalBox = document.querySelector('.terminal-box');
        if(terminalBox) { terminalBox.scrollTop = terminalBox.scrollHeight; }
    </script>
</body>
</html>