<?php
$success_message = "";
$error_message = "";

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

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $usercode = strtolower(trim($_POST['usercode']));
    $fullname = trim($_POST['fullname']);
    $userpass = trim($_POST['userpass']);

    if (!empty($usercode) && !empty($fullname) && !empty($userpass)) {
        
        // Double check if username exists
        $checkSql = "SELECT UserCode FROM [dbo].[appTrackingUsers] WHERE LOWER(RTRIM(UserCode)) = LOWER(?)";
        $checkStmt = sqlsrv_query($conn, $checkSql, array($usercode));

        if (sqlsrv_has_rows($checkStmt)) {
            $error_message = "The User ID Code '$usercode' already exists.";
        } else {
            // THE FIX: Let PHP dynamically generate a mathematically perfect 60-character bcrypt hash
            $secureHash = password_hash($userpass, PASSWORD_BCRYPT);

            $insertSql = "INSERT INTO [dbo].[appTrackingUsers] 
                            (UserCode, FullName, PasswordHash, RolePermissions, IsActive, DateCreated) 
                          VALUES (?, ?, ?, 'Admin', 1, GETDATE())";
            
            $stmt = sqlsrv_query($conn, $insertSql, array($usercode, $fullname, $secureHash));

            if ($stmt === false) {
                die("<pre>Registration Execution Fault: " . print_r(sqlsrv_errors(), true) . "</pre>");
            } else {
                $success_message = "Super Admin created successfully via Web Engine!";
            }
        }
    } else {
        $error_message = "Please complete all fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Setup • Super Admin Creation</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <style>
        :root {
            --bg-gradient: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            --panel-bg: #ffffff;
            --primary-blue: #2563eb;
            --text-dark: #0f172a;
            --border-color: #e2e8f0;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
        body { background: var(--bg-gradient); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .setup-container { background: var(--panel-bg); width: 100%; max-width: 440px; padding: 2.5rem; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.4); }
        h2 { font-size: 1.5rem; color: var(--text-dark); margin-bottom: 5px; text-align: center; }
        p.subtitle { color: #64748b; font-size: 0.85rem; text-align: center; margin-bottom: 2rem; }
        .form-group { margin-bottom: 1.25rem; }
        .form-group label { display: block; font-size: 0.75rem; font-weight: 700; color: #475569; margin-bottom: 6px; text-transform: uppercase; }
        .input-control { width: 100%; height: 42px; padding: 0 12px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 0.9rem; }
        .input-control:focus { outline: none; border-color: var(--primary-blue); box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15); }
        .btn-submit { width: 100%; height: 44px; background: var(--primary-blue); color: #ffffff; border: none; border-radius: 6px; font-size: 0.9rem; font-weight: 600; cursor: pointer; margin-top: 1rem; }
        .alert { padding: 12px; border-radius: 6px; font-size: 0.85rem; font-weight: 500; margin-bottom: 1.5rem; text-align: center; }
        .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
        .alert-danger { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; }
        .nav-link { display: block; text-align: center; margin-top: 1.5rem; font-size: 0.85rem; color: var(--primary-blue); text-decoration: none; font-weight: 500; }
    </style>
</head>
<body>

    <div class="setup-container">
        <h2>Initial System Setup</h2>
        <p class="subtitle">Create Master Super Admin Account</p>

        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">🎉 <?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger">⚠️ <?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <form action="register_admin.php" method="POST">
            <div class="form-group">
                <label>User ID Code (Username)</label>
                <input type="text" name="usercode" class="input-control" required placeholder="admin" value="admin">
            </div>

            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="fullname" class="input-control" required placeholder="System Administrator" value="System Administrator">
            </div>

            <div class="form-group">
                <label>Password</label>
                <input type="password" name="userpass" class="input-control" required placeholder="e.g., 12345">
            </div>

            <button type="submit" class="btn-submit">Create Account</button>
        </form>

        <a href="index.php" class="nav-link">Go to Login Form →</a>
    </div>

</body>
</html>