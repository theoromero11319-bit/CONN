<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If a session is already active, redirect them to their proper home screen automatically
if (isset($_SESSION['usercode'])) {
    if (strtolower(trim($_SESSION['usercode'])) === 'admin') {
        header("Location: admin_control.php");
    } else {
        header("Location: dashboard.php");
    }
    exit();
}

$error_message = "";

// HARDCODED BACKING GRAPHIC PATH
$backgroundStyle = "background: url('uploads/bg1.png') no-repeat center center; background-size: cover;";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $usercode = strtolower(trim($_POST['usercode']));
    $userpass = trim($_POST['userpass']);

    if (!empty($usercode) && !empty($userpass)) {
        
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

        $tsql = "SELECT RTRIM(UserCode) AS UserCode, RTRIM(PasswordHash) AS PasswordHash, IsActive 
                 FROM [dbo].[appTrackingUsers] 
                 WHERE LOWER(RTRIM(UserCode)) = LOWER(?)";
        
        $params = array($usercode);
        $stmt = sqlsrv_query($conn, $tsql, $params);

        if ($stmt === false) {
            die("<pre>Security Architecture Fault: " . print_r(sqlsrv_errors(), true) . "</pre>");
        }

        if (sqlsrv_has_rows($stmt)) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            
            $dbUserCode = trim($row['UserCode']);
            $dbHash     = trim($row['PasswordHash']);
            $isActive   = (int)$row['IsActive'];

            if ($isActive === 1) {
                if (password_verify($userpass, trim($dbHash))) {
                    
                    $updateSql = "UPDATE [dbo].[appTrackingUsers] SET LastLoginDateTime = GETDATE() WHERE UserCode = ?";
                    sqlsrv_query($conn, $updateSql, array($dbUserCode));

                    $logDesc = "User account '{$dbUserCode}' requested access validation. Handshake verified.";
                    $logSql = "INSERT INTO [dbo].[appTrackingLogs] (UserCode, ActionType, LogDescription) VALUES (?, 'LOGIN', ?)";
                    sqlsrv_query($conn, $logSql, array($dbUserCode, $logDesc));

                    $_SESSION['usercode'] = $dbUserCode;
                    
                    if ($dbUserCode === 'admin') {
                        header("Location: admin_control.php");
                    } else {
                        header("Location: dashboard.php");
                    }
                    exit();
                    
                } else {
                    $error_message = "Invalid password credential validation mapping.";
                }
            } else {
                $error_message = "This system tracking access profile has been suspended.";
            }
        } else {
            $error_message = "User code credential not found in tracking module registry.";
        }

        sqlsrv_free_stmt($stmt);
        sqlsrv_close($conn);

    } else {
        $error_message = "Please complete both credential entry parameters.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tracking Center Gateway</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <style>
        :root {
            --mshap-green: #0b7c2a;     /* Deep rich green matching your button exactly */
            --mshap-hover: #086120;     /* Slightly darker tone for button hover state */
            --text-dark: #203126;       /* Clean forest-tint gray for titles */
            --text-muted: #4a5d51;      /* Balanced secondary labels */
            --border-color: #d1dcd4;    /* Light gray-green border matching input boundaries */
        }

        * { 
            box-sizing: border-box; 
            margin: 0; 
            padding: 0; 
            font-family: 'Inter', sans-serif; 
        }

        body {
            min-height: 100vh;
            display: flex;
            margin: 0;
            overflow: hidden;
        }

        /* FULL SCREEN BACKGROUND BANNER */
        .page-wrapper {
            width: 100%;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: flex-end; /* Pushes the login container tightly to the right */
            padding-right: 5%; /* Aligns the login panel perfectly over the white area of your bg */
            position: relative;
        }

        /* EXACT FLOATING WHITE LOGIN CARD DESIGN */
        .login-container {
            background: #ffffff;
            width: 100%;
            max-width: 420px;
            padding: 3.5rem 2.5rem;
            border-radius: 24px; /* Smooth rounded corners matching your picture */
            box-shadow: 0 15px 45px rgba(0, 0, 0, 0.12), 
                        0 5px 15px rgba(0, 0, 0, 0.06);
            z-index: 10;
        }

        /* BRAND SYSTEM HIGHLIGHTS */
        .brand-header { 
            text-align: center; 
            margin-bottom: 2.5rem; 
        }

        /* CUSTOM CSS GRID LOGO LOGIC (Matches the 4-block square icon in your image) */
        .custom-logo-icon {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 4px;
            width: 32px;
            height: 32px;
            margin: 0 auto 1rem auto;
        }
        .custom-logo-icon span {
            background-color: var(--mshap-green);
            border-radius: 2px;
        }
        
        .brand-logo { 
            font-size: 1.75rem; 
            font-weight: 800; 
            color: var(--text-dark); 
            letter-spacing: -0.5px; 
            margin-bottom: 8px; 
        }
        
        /* Modern accent lines separating subtitle */
        .brand-subtitle { 
            font-size: 0.75rem; 
            color: var(--text-muted); 
            font-weight: 700; 
            text-transform: uppercase; 
            letter-spacing: 1px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .brand-subtitle::before, .brand-subtitle::after {
            content: "";
            height: 1px;
            width: 25px;
            background-color: #cbd5e1;
        }

        .error-banner {
            background: #fee2e2;
            border: 1px solid #f87171;
            color: #991b1b;
            padding: 12px 14px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 500;
            margin-bottom: 1.5rem;
        }

        .form-group { 
            margin-bottom: 1.5rem; 
        }
        
        .form-group label { 
            display: block; 
            font-size: 0.7rem; 
            font-weight: 800; 
            color: var(--text-dark); 
            margin-bottom: 8px; 
            text-transform: uppercase; 
            letter-spacing: 0.75px; 
        }

        /* CONTROL ELEMENTS WITH BUILT-IN ICON ACCOMMODATION */
        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        /* Stylized custom vector placeholders for user & lock icon graphics */
        .input-icon {
            position: absolute;
            left: 14px;
            color: #94a3b8;
            font-size: 1rem;
            pointer-events: none;
        }

        .input-control {
            width: 100%;
            height: 46px;
            padding: 0 14px 0 40px; /* Indented text gracefully to make room for input icon placeholders */
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.925rem;
            font-weight: 500;
            color: #0f172a;
            background: #ffffff;
            transition: all 0.2s ease-in-out;
        }
        
        .input-control:focus { 
            outline: none; 
            border-color: var(--mshap-green); 
            box-shadow: 0 0 0 4px rgba(11, 124, 42, 0.12); 
        }

        .input-control::placeholder {
            color: #b2c1b7;
        }

        /* BRIGHT IMMERSIVE BUTTON LOGIC */
        .btn-login {
            width: 100%;
            height: 48px;
            background: var(--mshap-green);
            color: #ffffff;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 700;
            cursor: pointer;
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background 0.15s ease;
        }
        
        .btn-login:hover { 
            background: var(--mshap-hover); 
        }

        /* BOTTOM SECURED INDICATOR */
        .system-footer { 
            text-align: center; 
            margin-top: 2rem; 
            font-size: 0.75rem; 
            color: var(--text-muted); 
            font-weight: 600; 
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .system-footer span {
            color: var(--mshap-green);
            font-size: 0.9rem;
        }

        /* RESPONSIVE LAYOUT CALIBRATION FOR TABLETS AND CELLPHONES */
        @media (max-width: 900px) {
            .page-wrapper {
                justify-content: center;
                padding: 20px;
            }
            .login-container {
                max-width: 400px;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
            }
        }
    </style>
</head>
<body style="<?php echo $backgroundStyle; ?>">

    <div class="page-wrapper">
        
        <div class="login-container">
            <div class="brand-header">
                <div class="custom-logo-icon">
                    <span></span><span></span>
                    <span></span><span></span>
                </div>
                <div class="brand-logo">MSHAP Tracker</div>
                <div class="brand-subtitle">Operational Core Gateway</div>
            </div>

            <?php if (!empty($error_message)): ?>
                <div class="error-banner">
                    ⚠️ <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <form action="index.php" method="POST">
                <div class="form-group">
                    <label for="usercode">User ID Code</label>
                    <div class="input-wrapper">
                        <span class="input-icon">👤</span>
                        <input type="text" id="usercode" name="usercode" class="input-control" required autocomplete="username" placeholder="e.g., admin" autofocus>
                    </div>
                </div>

                <div class="form-group">
                    <label for="userpass">Security Password</label>
                    <div class="input-wrapper">
                        <span class="input-icon">🔒</span>
                        <input type="password" id="userpass" name="userpass" class="input-control" required autocomplete="current-password" placeholder="••••••••">
                    </div>
                </div>

                <button type="submit" class="btn-login">
                    <span>🔒</span> Verify Gateway Access
                </button>
            </form>

            <div class="system-footer">
                <span>✔</span> Internal Application Architecture • Secured Data Stream
            </div>
        </div>

    </div>

</body>
</html>