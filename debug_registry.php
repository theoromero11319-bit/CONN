<?php
// --- FORCE PHILIPPINE STANDARD TIME ---
date_default_timezone_set('Asia/Manila');

session_start();

// Security session checkpoint
if (!isset($_SESSION['usercode'])) {
    header("Location: index.php");
    exit();
}

require_once 'db_connect.php';

// Fetch user role for write clearance
$usercode = $_SESSION['usercode'];
$roleQuery = "SELECT RolePermissions FROM [LiveDB_MSHAP].[dbo].[appTrackingUsers] WHERE UserCode = ?";
$roleStmt = sqlsrv_query($conn, $roleQuery, [$usercode]);
$userRole = ($roleStmt !== false && $row = sqlsrv_fetch_array($roleStmt, SQLSRV_FETCH_ASSOC)) ? trim($row['RolePermissions']) : 'Viewer';

$actionSuccessMessage = null;
$actionErrorMessage = null;

// -------------------------------------------------------------------------
// 1. POST ACTION: EXECUTE RENDATE UPDATE (Custom Date or Empty String '')
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute_update_rendate'])) {
    if (strtoupper($userRole) === 'VIEWER') {
        die("Security Exception: Current user role lacks write clearance.");
    }

    $targetFK = (int)$_POST['target_fk_registers'];
    // Accepts custom date input or falls back to empty string ''
    $customRendate = isset($_POST['custom_rendate_value']) ? trim($_POST['custom_rendate_value']) : ''; 

    // update psPatitem set rendate = 'value' where FK_psPatRegisters = X
    $updateSQL = "UPDATE [LiveDB_MSHAP].[dbo].[psPatitem] SET rendate = ? WHERE FK_psPatRegisters = ?";
    $updateStmt = sqlsrv_query($conn, $updateSQL, [$customRendate, $targetFK]);

    if ($updateStmt !== false) {
        $actionSuccessMessage = "Successfully updated psPatitem record! [rendate] set to '" . htmlspecialchars($customRendate) . "' for Tracking No: " . $targetFK;
        
        // Log to audit log
        $logDesc = "DEBUG MANUAL UPDATE: Set rendate = '" . $customRendate . "' where FK_psPatRegisters = " . $targetFK;
        sqlsrv_query($conn, "INSERT INTO [dbo].[appTrackingLogs] (UserCode, ActionType, LogDescription) VALUES (?, 'DEBUG_UPDATE', ?)", [$_SESSION['usercode'], $logDesc]);
    } else {
        $actionErrorMessage = sqlsrv_errors();
    }
}

// -------------------------------------------------------------------------
// 2. GET ACTION: LOOKUP IDENTITY (UNION TRACK) & ITEMS
// -------------------------------------------------------------------------
$manualLookupItems = [];
$selectedPatientName = null;
$selectedPatientID = null;
$patientTypeLabel = null;
$searchTrackingID = isset($_GET['search_tracking_id']) && !empty($_GET['search_tracking_id']) ? (int)$_GET['search_tracking_id'] : null;

if ($searchTrackingID) {
    // Robust search union for both inpatient & outpatient configurations
    $identitySQL = "
        SELECT PatientId, PatientFullname, 'Inpatient' AS PatientType FROM [LiveDB_MSHAP].[dbo].[vwInpatientMstrList] WHERE PK_psPatRegisters = ?
        UNION ALL
        SELECT PatientId, PatientFullname, 'Outpatient' AS PatientType FROM [LiveDB_MSHAP].[dbo].[vwOutPatientsMstrList] WHERE PK_psoutpatients = ?
    ";
    
    $idStmt = sqlsrv_query($conn, $identitySQL, [$searchTrackingID, $searchTrackingID]);
    if ($idStmt && $pRow = sqlsrv_fetch_array($idStmt, SQLSRV_FETCH_ASSOC)) {
        $selectedPatientName = $pRow['PatientFullname'];
        $selectedPatientID   = $pRow['PatientId'];
        $patientTypeLabel    = $pRow['PatientType'];
    }

    // select * from psPatitem where FK_psPatRegisters = X
    $itemsSQL = "SELECT * FROM [LiveDB_MSHAP].[dbo].[psPatitem] WHERE FK_psPatRegisters = ?";
    $itemsStmt = sqlsrv_query($conn, $itemsSQL, [$searchTrackingID]);
    if ($itemsStmt !== false) {
        while ($iRow = sqlsrv_fetch_array($itemsStmt, SQLSRV_FETCH_ASSOC)) {
            $manualLookupItems[] = $iRow;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Registry Modifier Console</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="style.css">
    <style>
        .search-card { background: #ffffff; border: 2px solid #64748b; border-radius: 8px; padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .search-row-layout { display: flex; gap: 12px; align-items: flex-end; }
        .input-box { border: 1px solid #cbd5e1; border-radius: 4px; padding: 10px; font-size: 0.95rem; color: #0f172a; width: 100%; font-family: 'Inter', sans-serif; }
        .btn-action { background: #475569; color: #fff; border: none; height: 42px; padding: 0 1.5rem; border-radius: 4px; font-weight: 600; cursor: pointer; font-size: 0.9rem; }
        .btn-action:hover { background: #334155; }
        
        .patient-tag-banner { background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 1.25rem; margin-top: 1rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .btn-update { background: #dc2626; color: #fff; border: none; height: 40px; padding: 0 1.25rem; border-radius: 4px; font-weight: 700; cursor: pointer; }
        .btn-update:hover { background: #b91c1c; }
        .btn-update:disabled { background: #cbd5e1; cursor: not-allowed; }

        .grid-scroll { width: 100%; overflow-x: auto; border: 1px solid #cbd5e1; border-radius: 6px; margin-top: 1rem; background: #ffffff; }
        .data-dump-table { width: 100%; border-collapse: collapse; font-size: 0.8rem; text-align: left; }
        .data-dump-table th { background: #334155; color: #ffffff; padding: 10px; font-weight: 600; white-space: nowrap; }
        .data-dump-table td { padding: 8px 12px; border-bottom: 1px solid #e2e8f0; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .data-dump-table tr:hover { background: #f1f5f9; }
    </style>
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <div class="main-wrapper">
        <header class="app-header">
            <div class="title">🔧 Registry Parameter Modifier Console</div>
            <div style="font-size:0.85rem; font-weight:600; color:#64748b;">
                Clearance: <strong style="color:#0284c7;"><?php echo htmlspecialchars(strtoupper($userRole)); ?></strong>
            </div>
        </header>

        <div class="main-content">
            
            <?php if ($actionSuccessMessage): ?>
                <div style="background:#dcfce7; border:1px solid #bbf7d0; color:#15803d; padding:12px; border-radius:6px; margin-bottom:1.5rem; font-weight:600; font-size:0.9rem;">
                    ✅ <?php echo htmlspecialchars($actionSuccessMessage); ?>
                </div>
            <?php endif; ?>

            <?php if ($actionErrorMessage): ?>
                <div style="background:#fef2f2; border:1px solid #fee2e2; color:#991b1b; padding:12px; border-radius:6px; margin-bottom:1.5rem; font-family:monospace; font-size:0.85rem;">
                    <strong>❌ Database Update Error:</strong>
                    <pre><?php print_r($actionErrorMessage); ?></pre>
                </div>
            <?php endif; ?>

            <div class="search-card">
                <form action="debug_registry.php" method="GET">
                    <div class="search-row-layout">
                        <div style="flex-grow: 1;">
                            <label style="font-size: 0.8rem; font-weight: 700; color: #1e293b; text-transform: uppercase; display:block; margin-bottom:6px;">
                                Input Target Case ID (FK_psPatRegisters)
                            </label>
                            <input type="number" name="search_tracking_id" class="input-box" 
                                   placeholder="e.g. 54032" 
                                   value="<?php echo htmlspecialchars($searchTrackingID ?? ''); ?>" required>
                        </div>
                        <button type="submit" class="btn-action">🔍 Fetch & Inspect Items</button>
                    </div>
                </form>
            </div>

            <?php if ($searchTrackingID): ?>
                <?php if ($selectedPatientName): ?>
                    
                    <div class="patient-tag-banner">
                        <form action="debug_registry.php?<?php echo htmlspecialchars(http_build_query($_GET)); ?>" method="POST"
                              onsubmit="return confirm('🚨 CONFIRM RECORD OVERWRITE\n\nPatient Name: <?php echo addslashes($selectedPatientName); ?>\nCase ID: <?php echo $searchTrackingID; ?>\n\nAre you sure you want to run this update operation?');">
                            <input type="hidden" name="target_fk_registers" value="<?php echo $searchTrackingID; ?>">
                            
                            <div style="display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 20px; background: #f8fafc; padding:1rem; border:1px solid #e2e8f0; border-radius:6px;">
                                <div>
                                    <span style="font-size:0.7rem; text-transform:uppercase; font-weight:700; color:#475569; display:block; margin-bottom:2px;">Target Identity Profile</span>
                                    <h3 style="margin:0; font-size:1.25rem; color:#0f172a; font-weight:700;">
                                        👤 Patient: <span style="color:#0284c7;"><?php echo htmlspecialchars($selectedPatientName); ?></span>
                                    </h3>
                                    <div style="font-size:0.85rem; color:#475569; margin-top:4px; font-family:monospace;">
                                        [ID: <?php echo htmlspecialchars($selectedPatientID); ?>] | Type: <strong><?php echo $patientTypeLabel; ?></strong>
                                    </div>
                                </div>

                                <div style="display:flex; align-items:flex-end; gap:12px;">
                                    <div style="display:flex; flex-direction:column; gap:4px;">
                                        <label style="font-size: 0.75rem; font-weight: 700; color: #1e293b; text-transform: uppercase;">
                                            New rendate value (Leave blank for '')
                                        </label>
                                        <input type="text" name="custom_rendate_value" class="input-box" style="width: 220px; height: 40px;" 
                                               placeholder="yyyy/mm/dd or leave blank">
                                    </div>
                                    <button type="submit" name="execute_update_rendate" class="btn-update" <?php echo (strtoupper($userRole) === 'VIEWER') ? 'disabled' : ''; ?>>
                                        Update rendate
                                    </button>
                                </div>
                            </div>
                        </form>

                        <div style="font-size:0.85rem; font-weight:600; color:#334155; margin: 1.5rem 0 4px 0;">
                            Result output for: <code>SELECT * FROM psPatitem WHERE FK_psPatRegisters = <?php echo $searchTrackingID; ?></code>
                        </div>
                        
                        <div class="grid-scroll">
                            <?php if (!empty($manualLookupItems)): ?>
                                <table class="data-dump-table">
                                    <thead>
                                        <tr>
                                            <th style="text-align:center; width:40px;">#</th>
                                            <?php 
                                            $columns = array_keys($manualLookupItems[0]);
                                            foreach ($columns as $col): 
                                                $style = (strtolower($col) === 'rendate') ? 'background:#fee2e2; color:#991b1b; font-weight:700;' : '';
                                            ?>
                                                <th style="<?php echo $style; ?>"><?php echo htmlspecialchars($col); ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $idx = 1; foreach ($manualLookupItems as $item): ?>
                                            <tr>
                                                <td style="font-weight:700; background:#f8fafc; text-align:center; color:#64748b;"><?php echo $idx++; ?></td>
                                                <?php foreach ($columns as $col): 
                                                    $val = $item[$col];
                                                    $displayStr = ($val instanceof DateTime) ? $val->format('Y-m-d H:i:s') : (($val === null) ? 'NULL' : (string)$val);
                                                    $tdStyle = (strtolower($col) === 'rendate') ? 'background:#fff5f5; font-weight:700; color:#b91c1c;' : '';
                                                ?>
                                                    <td style="<?php echo $tdStyle; ?>" title="<?php echo htmlspecialchars($displayStr); ?>">
                                                        <?php echo htmlspecialchars($displayStr); ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div style="text-align:center; padding:2.5rem; color:#64748b; font-style:italic; background:#fafafa;">
                                    🔍 Zero items found inside table [psPatitem] matching FK_psPatRegisters = <?php echo $searchTrackingID; ?>.
                                </div>
                            <?php endif; ?>
                        </div>

                    </div>

                <?php else: ?>
                    <div style="text-align:center; padding:2.5rem; color:#991b1b; background:#fef2f2; border:1px dashed #fca5a5; border-radius:6px; font-weight:600;">
                        ❌ No record found matching Case ID: <?php echo $searchTrackingID; ?> across Inpatient or Outpatient registers.
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div style="text-align:center; padding:3rem; border:2px dashed #cbd5e1; border-radius:6px; color:#64748b; background:#f8fafc; font-weight:500;">
                    💡 Type a Case ID above to check its database record parameters.
                </div>
            <?php endif; ?>

        </div>
    </div>

</body>
</html>