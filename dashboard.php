<?php
// --- FORCE PHILIPPINE STANDARD TIME TO SYNC SHIFT BOUNDARIES ACCURATELY ---
date_default_timezone_set('Asia/Manila');

session_start();

if (!isset($_SESSION['usercode'])) {
    header("Location: index.php");
    exit();
}

require_once 'db_connect.php';

// --- FETCH THE REAL ROLE DIRECTLY FROM THE DATABASE ---
$usercode = $_SESSION['usercode'];
$roleQuery = "SELECT RolePermissions FROM [LiveDB_MSHAP].[dbo].[appTrackingUsers] WHERE UserCode = ?";
$roleStmt = sqlsrv_query($conn, $roleQuery, [$usercode]);

if ($roleStmt !== false && $roleRow = sqlsrv_fetch_array($roleStmt, SQLSRV_FETCH_ASSOC)) {
    $userRole = trim($roleRow['RolePermissions']);
} else {
    $userRole = 'Viewer'; 
}

// --- SYSTEM ENGINE SELF-HEALING SCHEMATICS ---
$schemaCheckQuery = "
    IF NOT EXISTS (
        SELECT * FROM sys.columns 
        WHERE object_id = OBJECT_ID(N'[dbo].[psPatRegisters]') 
        AND name = N'MghDelayReason'
    )
    BEGIN
        ALTER TABLE [dbo].[psPatRegisters] ADD MghDelayReason NVARCHAR(500) NULL;
    END
";
sqlsrv_query($conn, $schemaCheckQuery);


// --- POST CONTROLLER: SUBMIT MGH DELAY LOGS (Restriction Layer: Encoder / Admin Only) ---
$updateSuccess = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_log_delay'])) {
    
    if (strtoupper($userRole) === 'VIEWER') {
        die("Security Exception: Access privilege violations occurred during structural parameter edits.");
    }
    
    $targetCaseID = (int)$_POST['target_case_id'];
    $delayReason  = trim($_POST['mgh_delay_reason']);
    
    $updateSQL = "UPDATE [dbo].[psPatRegisters] SET MghDelayReason = ? WHERE PK_psPatRegisters = ?";
    $updateStmt = sqlsrv_query($conn, $updateSQL, [$delayReason, $targetCaseID]);
    
    if ($updateStmt !== false) {
        $updateSuccess = true;
        $logDesc = "User '" . $_SESSION['usercode'] . "' updated MGH delay context criteria parameters for Case ID: " . $targetCaseID;
        sqlsrv_query($conn, "INSERT INTO [dbo].[appTrackingLogs] (UserCode, ActionType, LogDescription) VALUES (?, 'PARAMETER_UPDATE', ?)", [$_SESSION['usercode'], $logDesc]);
    }
}


// Initialize filter variables and state controls
$isSubmitted   = isset($_REQUEST['filter_submitted']);
$useRegistry   = !$isSubmitted || isset($_REQUEST['use_registry']);
$useMghDate    = $isSubmitted && isset($_REQUEST['use_mgh_date']);
$useDischDate  = $isSubmitted && isset($_REQUEST['use_disch_date']);

$regDateVal    = isset($_REQUEST['reg_date_val']) && !empty($_REQUEST['reg_date_val']) ? $_REQUEST['reg_date_val'] : date('Y-m-d');
$mghDateVal    = isset($_REQUEST['mgh_date_val']) && !empty($_REQUEST['mgh_date_val']) ? $_REQUEST['mgh_date_val'] : date('Y-m-d');
$dischDateVal  = isset($_REQUEST['disch_date_val']) && !empty($_REQUEST['disch_date_val']) ? $_REQUEST['disch_date_val'] : date('Y-m-d');

$todayDateStr  = date('Y-m-d');
$censusStartDate = '2024-01-01 00:00:00';


// =========================================================================
// SHIFT BOUNDARY CONFIGURATOR (8:00 AM TO 7:59:59 AM ENGINE)
// =========================================================================
// 1. Calculate boundaries for "Today's Live Shift" (Now perfectly synced to Manila time)
if (date('H') >= 8) {
    $todayShiftStart = date('Y-m-d 08:00:00');
    $todayShiftEnd   = date('Y-m-d 07:59:59', strtotime('+1 day'));
} else {
    $todayShiftStart = date('Y-m-d 08:00:00', strtotime('-1 day'));
    $todayShiftEnd   = date('Y-m-d 07:59:59');
}

// 2. Calculate boundaries for "Filtered Target Registry Date"
$filterRegStart  = date('Y-m-d 08:00:00', strtotime($regDateVal));
$filterRegEnd    = date('Y-m-d 07:59:59', strtotime($regDateVal . ' +1 day'));

// 3. Calculate boundaries for "Filtered MGH Date"
$filterMghStart  = date('Y-m-d 08:00:00', strtotime($mghDateVal));
$filterMghEnd    = date('Y-m-d 07:59:59', strtotime($mghDateVal . ' +1 day'));

// 4. Calculate boundaries for "Filtered Discharge Date"
$filterDischStart = date('Y-m-d 08:00:00', strtotime($dischDateVal));
$filterDischEnd   = date('Y-m-d 07:59:59', strtotime($dischDateVal . ' +1 day'));


// =========================================================================
// ENGINE 1: TODAY'S STATIC REAL-TIME VALUES (ROW 1 - 8AM TO 8AM WINDOW)
// =========================================================================
$todayTrafficQuery = "
    SELECT COUNT(*) as total FROM (
        SELECT registrydate FROM [LiveDB_MSHAP].[dbo].[vwInpatientMstrList] WHERE registrydate >= ? AND registrydate <= ?
        UNION ALL
        SELECT registrydate FROM [LiveDB_MSHAP].[dbo].[vwOutPatientsMstrList] WHERE registrydate >= ? AND registrydate <= ?
    ) as t
";
$todayTrafficStmt = sqlsrv_query($conn, $todayTrafficQuery, [$todayShiftStart, $todayShiftEnd, $todayShiftStart, $todayShiftEnd]);
$todayTrafficCount = ($todayTrafficStmt !== false && $row = sqlsrv_fetch_array($todayTrafficStmt, SQLSRV_FETCH_ASSOC)) ? $row['total'] : 0;

$liveQuery = "
    SELECT 
        SUM(CASE WHEN main.DischargeDate IS NULL THEN 1 ELSE 0 END) as live_bed_census,
        SUM(CASE WHEN main.DischargeDate IS NULL AND sub.mghdatetime IS NOT NULL THEN 1 ELSE 0 END) as live_mgh_census
    FROM [LiveDB_MSHAP].[dbo].[vwInpatientMstrList] main
    LEFT JOIN [LiveDB_MSHAP].[dbo].[psPatRegisters] sub 
        ON main.PK_psPatRegisters = sub.PK_psPatRegisters
    WHERE main.RegistryDate >= ?
";
$liveStmt = sqlsrv_query($conn, $liveQuery, [$censusStartDate]);
$liveData = sqlsrv_fetch_array($liveStmt, SQLSRV_FETCH_ASSOC);
$todayActiveCensus = $liveData['live_bed_census'] ?? 0; 
$todayMghCensus    = $liveData['live_mgh_census'] ?? 0;

$todayDischQuery = "
    SELECT COUNT(*) as total 
    FROM [LiveDB_MSHAP].[dbo].[vwInpatientMstrList]
    WHERE DischargeDate >= ? AND DischargeDate <= ? AND RegistryDate >= ?
";
$todayDischQueryStmt = sqlsrv_query($conn, $todayDischQuery, [$todayShiftStart, $todayShiftEnd, $censusStartDate]);
$todayDischargedCount = ($todayDischQueryStmt !== false && $row = sqlsrv_fetch_array($todayDischQueryStmt, SQLSRV_FETCH_ASSOC)) ? $row['total'] : 0;

$mghPercentage = ($todayActiveCensus > 0) ? round(($todayMghCensus / $todayActiveCensus) * 100, 1) : 0;
$standardActive = $todayActiveCensus - $todayMghCensus;

// --- EXEC SUMMARY SHARE RATE ENGINE ---
$totalShiftVolume = $todayActiveCensus + $todayDischargedCount;
$activeBluePct  = ($totalShiftVolume > 0) ? round(($standardActive / $totalShiftVolume) * 100, 1) : 0;
$mghYellowPct   = ($totalShiftVolume > 0) ? round(($todayMghCensus / $totalShiftVolume) * 100, 1) : 0;
$dischRedPct    = ($totalShiftVolume > 0) ? round(($todayDischargedCount / $totalShiftVolume) * 100, 1) : 0;


// =========================================================================
// ENGINE 2: DYNAMIC FILTERABLE VALUES (ROW 2 - 8AM TO 8AM WINDOW)
// =========================================================================
$patientRoster = [];
$masterTrafficQuery = "
    SELECT * FROM (
        SELECT PK_psPatRegisters AS CaseID, PatientId AS PatientID, PatientFullname AS PatientName, RegistryDate AS TxDateTime, 'Inpatient' AS PatientType
        FROM [LiveDB_MSHAP].[dbo].[vwInpatientMstrList] WHERE RegistryDate >= ? AND RegistryDate <= ?
        UNION ALL
        SELECT PK_psoutpatients AS CaseID, PatientId AS PatientID, PatientFullname AS PatientName, RegistryDate AS TxDateTime, 'Outpatient' AS PatientType
        FROM [LiveDB_MSHAP].[dbo].[vwOutPatientsMstrList] WHERE RegistryDate >= ? AND RegistryDate <= ?
    ) AS CombinedTraffic
";
$trafficStmt = sqlsrv_query($conn, $masterTrafficQuery, [$filterRegStart, $filterRegEnd, $filterRegStart, $filterRegEnd]);
while ($trafficStmt !== false && $row = sqlsrv_fetch_array($trafficStmt, SQLSRV_FETCH_ASSOC)) {
    $patientRoster[] = $row;
}
$filteredTrafficCount = count($patientRoster);

usort($patientRoster, function($a, $b) {
    $timeA = $a['TxDateTime'] instanceof DateTime ? $a['TxDateTime']->getTimestamp() : strtotime($a['TxDateTime']);
    $timeB = $b['TxDateTime'] instanceof DateTime ? $b['TxDateTime']->getTimestamp() : strtotime($b['TxDateTime']);
    return $timeB - $timeA; 
});

$censusRoster = [];
$baseCensusSQL = "
    SELECT 
        main.PK_psPatRegisters as CaseID, 
        main.PatientId as PatientID, 
        main.PatientFullname as PatientName,
        main.RegistryDate as TxDateTime,
        main.PatientType as CaseType,
        sub.mghdatetime as MghDateTime,
        RTRIM(sub.MghDelayReason) as MghDelayReason,
        ISNULL(COALESCE(soa.BalanceDue, live_bill.LiveBalance), 0) as BalanceDue
    FROM [LiveDB_MSHAP].[dbo].[vwInpatientMstrList] main
    LEFT JOIN [LiveDB_MSHAP].[dbo].[psPatRegisters] sub
        ON main.PK_psPatRegisters = sub.PK_psPatRegisters
    LEFT JOIN (
        SELECT 
            FK_psPatRegisters,
            SUM(ISNULL(debit, 0)) - SUM(ISNULL(Credit, 0)) as BalanceDue
        FROM [LiveDB_MSHAP].[dbo].[vwreportSOAHB]
        WHERE FK_psPatRegisters IS NOT NULL
        GROUP BY FK_psPatRegisters
    ) soa ON main.PK_psPatRegisters = soa.FK_psPatRegisters
    LEFT JOIN (
        SELECT 
            FK_psPatRegisters,
            SUM(CASE WHEN pattrantype IN ('CHARGES', 'DEBIT', 'CHARGE', 'ROOM', 'ROOM CHARGES', 'PROFESSIONAL FEE') THEN (ISNULL(HospitallBill, 0) + ISNULL(ProfessionalFee, 0)) ELSE 0 END) -
            SUM(CASE WHEN pattrantype IN ('PAYMENT', 'CREDIT', 'CREDIT NOTE', 'CN', 'BENEFIT', 'PHILHEALTH', 'PHIC') THEN (ISNULL(HospitallBill, 0) + ISNULL(ProfessionalFee, 0)) ELSE 0 END) as LiveBalance
        FROM [LiveDB_MSHAP].[dbo].[vwBillingDtls]
        WHERE FK_psPatRegisters IS NOT NULL
        GROUP BY FK_psPatRegisters
    ) live_bill ON main.PK_psPatRegisters = live_bill.FK_psPatRegisters
";

if ($regDateVal === $todayDateStr) {
    $snapshotCensusQuery = $baseCensusSQL . " WHERE main.DischargeDate IS NULL AND main.RegistryDate >= ? ";
    $snapshotCensusStmt = sqlsrv_query($conn, $snapshotCensusQuery, [$censusStartDate]);
} else {
    $snapshotCensusQuery = $baseCensusSQL . " WHERE main.RegistryDate >= ? AND main.RegistryDate <= ? AND main.RegistryDate >= ? ";
    $snapshotCensusStmt = sqlsrv_query($conn, $snapshotCensusQuery, [$filterRegStart, $filterRegEnd, $censusStartDate]);
}

while ($snapshotCensusStmt !== false && $row = sqlsrv_fetch_array($snapshotCensusStmt, SQLSRV_FETCH_ASSOC)) {
    $row['_Timestamp'] = $row['TxDateTime'] instanceof DateTime ? $row['TxDateTime']->getTimestamp() : strtotime($row['TxDateTime']);
    $censusRoster[] = $row;
}

usort($censusRoster, function($a, $b) {
    return $b['_Timestamp'] - $a['_Timestamp'];
});
$filteredActiveCensus = count($censusRoster);

// Determine targets based on conditional checkbox parameters
$targetMghStart = $useMghDate ? $filterMghStart : $filterRegStart;
$targetMghEnd   = $useMghDate ? $filterMghEnd : $filterRegEnd;

$snapshotMghQuery = "
    SELECT COUNT(*) as historical_mgh 
    FROM [LiveDB_MSHAP].[dbo].[vwInpatientMstrList] main
    INNER JOIN [LiveDB_MSHAP].[dbo].[psPatRegisters] sub 
        ON main.PK_psPatRegisters = sub.PK_psPatRegisters
    WHERE main.DischargeDate IS NULL 
      AND main.RegistryDate <= ?
      AND sub.mghdatetime >= ? AND sub.mghdatetime <= ?
      AND main.RegistryDate >= ?
";
$snapshotMghStmt = sqlsrv_query($conn, $snapshotMghQuery, [$targetMghEnd, $targetMghStart, $targetMghEnd, $censusStartDate]);
$filteredMghCount = ($snapshotMghStmt !== false && $row = sqlsrv_fetch_array($snapshotMghStmt, SQLSRV_FETCH_ASSOC)) ? $row['historical_mgh'] : 0;

$targetDischStart = $useDischDate ? $filterDischStart : $filterRegStart;
$targetDischEnd   = $useDischDate ? $filterDischEnd : $filterRegEnd;

$historyQuery = "
    SELECT COUNT(*) as period_discharges 
    FROM [LiveDB_MSHAP].[dbo].[vwInpatientMstrList]
    WHERE DischargeDate >= ? AND DischargeDate <= ? AND RegistryDate >= ?
";
$historyStmt = sqlsrv_query($conn, $historyQuery, [$targetDischStart, $targetDischEnd, $censusStartDate]);
$filteredDischargedCount = ($historyStmt !== false && $row = sqlsrv_fetch_array($historyStmt, SQLSRV_FETCH_ASSOC)) ? $row['period_discharges'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enterprise Operations Dashboard</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .patient-dropdown-panel { display: none; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1.5rem; margin-top: 1rem; margin-bottom: 1.5rem; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); }
        .patient-dropdown-panel.open { display: block !important; }
        .accordion-trigger-btn { cursor: pointer; transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .accordion-trigger-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 12px rgba(0,0,0,0.08); }
        .roster-table th { cursor: pointer; position: relative; user-select: none; }
        .roster-table th:not(.no-sort):after { content: ' ↕'; font-size: 0.75rem; color: #94a3b8; }
        .row-idx-cell { font-weight: 700; color: #64748b; background: #f8fafc; text-align: center; width: 45px; }
        .mgh-badge-status { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; padding: 3px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 700; display: inline-block; margin-bottom: 6px; }
        .mgh-delay-input-group { display: flex; gap: 6px; margin-top: 6px; max-width: 320px; }
        .mgh-input-field { flex-grow: 1; height: 32px; padding: 0 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 0.8rem; }
        .mgh-input-field:focus { outline: none; border-color: #3b82f6; }
        .mgh-btn-save { height: 32px; padding: 0 10px; background: #0f172a; color: white; border: none; border-radius: 4px; font-size: 0.75rem; font-weight: 600; cursor: pointer; }
        .mgh-btn-save:hover { background: #1e293b; }
        .read-only-reason-box { background: #f8fafc; border-left: 3px solid #d97706; padding: 6px 10px; font-size: 0.8rem; color: #475569; border-radius: 0 4px 4px 0; margin-top: 4px; font-style: italic; }
        .badge-role-indicator { font-weight: 700; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; }
    </style>
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <div class="main-wrapper">
        <header class="app-header">
            <div class="title">Operational Tracking Center</div>
            <div style="font-size:0.85rem; font-weight:600; display:flex; align-items:center; gap:12px;">
                <div>Security Level: 
                    <span class="badge-role-indicator" style="<?php echo (strtoupper($userRole) === 'ENCODER' || strtoupper($userRole) === 'ADMIN') ? 'color:#15803d; background:#dcfce7;' : 'color:#dc2626; background:#fee2e2;'; ?>">
                        <?php echo htmlspecialchars(strtoupper($userRole)); ?>
                    </span>
                </div>
                <div>User logged: <span style="font-weight:700; color:var(--primary);"><?php echo htmlspecialchars($_SESSION['usercode']); ?></span></div>
            </div>
        </header>

        <div class="main-content">
            
            <?php if ($updateSuccess): ?>
                <div style="background:#dcfce7; border:1px solid #bbf7d0; color:#15803d; padding:12px; border-radius:6px; margin-bottom:1.5rem; font-size:0.875rem; font-weight:600;">
                    ✅ Clinical tracking data updated. MGH delay rationale committed successfully to server list.
                </div>
            <?php endif; ?>
            
            <div class="section-divider">📊 Today's Real-Time Summary (8:00 AM - 7:59 AM Shift Cycle)</div>
            <div class="live-container-row">
                <div class="live-visual-card c-traffic">
                    <div class="live-badge">Today</div>
                    <span class="live-lbl">Admissions Today</span>
                    <span class="live-val"><?php echo number_format($todayTrafficCount); ?></span>
                    <span class="live-sub">Total registrations logged this shift</span>
                </div>

                <div class="live-visual-card c-active">
                    <div class="live-badge"><span></span>LIVE</div>
                    <span class="live-lbl">Current Active Bed Census</span>
                    <span class="live-val" style="color: #10b981;"><?php echo number_format($todayActiveCensus); ?></span>
                    <span class="live-sub">Patients physically inside beds right now</span>
                </div>

                <div class="live-visual-card c-mgh">
                    <div class="live-badge"><span></span>LIVE</div>
                    <span class="live-lbl">Active MGH Beds</span>
                    <span class="live-val" style="color: #d97706;"><?php echo number_format($todayMghCensus); ?></span>
                    <span class="live-sub">Cleared to leave, pending paperwork</span>
                </div>

                <div class="live-visual-card c-disch">
                    <div class="live-badge">Today</div>
                    <span class="live-lbl">Processed Discharges</span>
                    <span class="live-val" style="color: #ef4444;"><?php echo number_format($todayDischargedCount); ?></span>
                    <span class="live-sub">Total patients completely cleared out this shift</span>
                </div>
            </div>

            <div class="panel" style="padding:1rem; margin-bottom:1.5rem; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px;">
                <form action="dashboard.php" method="GET" style="display: grid; grid-template-columns: repeat(3, 1fr) auto; gap: 0.75rem; align-items: flex-end;">
                    <input type="hidden" name="filter_submitted" value="1">
                    
                    <div style="display:flex; flex-direction:column; gap:4px; padding:4px;">
                        <label style="font-size:0.75rem; font-weight:700; color:#475569;">
                            <input type="checkbox" name="use_registry" <?php echo $useRegistry ? 'checked' : ''; ?>> Filter Target Date (8AM-8AM)
                        </label>
                        <input type="date" name="reg_date_val" value="<?php echo htmlspecialchars($regDateVal); ?>" style="border:1px solid #cbd5e1; border-radius:6px; padding:6px; font-size:0.85rem; width:100%;">
                    </div>
                    
                    <div style="display:flex; flex-direction:column; gap:4px; padding:4px;">
                        <label style="font-size:0.75rem; font-weight:700; color:#475569;">
                            <input type="checkbox" name="use_mgh_date" <?php echo $useMghDate ? 'checked' : ''; ?>> Filter MGH Date (8AM-8AM)
                        </label>
                        <input type="date" name="mgh_date_val" value="<?php echo htmlspecialchars($mghDateVal); ?>" style="border:1px solid #cbd5e1; border-radius:6px; padding:6px; font-size:0.85rem; width:100%;">
                    </div>
                    
                    <div style="display:flex; flex-direction:column; gap:4px; padding:4px;">
                        <label style="font-size:0.75rem; font-weight:700; color:#475569;">
                            <input type="checkbox" name="use_disch_date" <?php echo $useDischDate ? 'checked' : ''; ?>> Filter Discharge Date (8AM-8AM)
                        </label>
                        <input type="date" name="disch_date_val" value="<?php echo htmlspecialchars($dischDateVal); ?>" style="border:1px solid #cbd5e1; border-radius:6px; padding:6px; font-size:0.85rem; width:100%;">
                    </div>
                    
                    <button type="submit" class="btn-submit" style="height:40px; padding:0 1.5rem; border-radius:6px; font-weight:600; font-size:0.85rem;">Run Historical Filter</button>
                </form>
            </div>

            <div class="section-divider">📅 Historical Date Parameter Logs (Changes Based on Filter)</div>
            <div class="filter-container-row">
                <div class="filter-metric-card accordion-trigger-btn" id="togglePatientDropdown">
                    <span class="f-lbl">📋 Filtered Admissions Traffic ▼</span>
                    <span class="f-val"><?php echo number_format($filteredTrafficCount); ?></span>
                    <span class="f-sub" style="color: #2563eb; font-weight:600;">Click to view admissions breakdown</span>
                </div>

                <div class="filter-metric-card accordion-trigger-btn" id="toggleCensusDropdown" style="border-left: 4px solid #10b981;">
                    <span class="f-lbl">
                        <?php echo ($regDateVal === $todayDateStr) ? '🟢 Current Live Bed Pool ▼' : '📋 Total Admitted on This Date ▼'; ?>
                    </span>
                    <span class="f-val"><?php echo number_format($filteredActiveCensus); ?></span>
                    <span class="f-sub" style="color: #10b981; font-weight:600;">Click to view active pool with operational auditing options</span>
                </div>

                <div class="filter-metric-card">
                    <span class="f-lbl">⚡ MGH Logs in View</span>
                    <span class="f-val"><?php echo number_format($filteredMghCount); ?></span>
                    <span class="f-sub">Active MGH status on target date</span>
                </div>

                <div class="filter-metric-card">
                    <span class="f-lbl">🏁 Checked Out Discharges</span>
                    <span class="f-val"><?php echo number_format($filteredDischargedCount); ?></span>
                    <span class="f-sub">Processed in execution time-frame</span>
                </div>
            </div>

            <div class="patient-dropdown-panel" id="patientDropdownPanel">
                <h3 style="margin:0 0 1rem 0; font-size:1.05rem; font-weight:700; color:#0f172a; border-bottom:1px solid #2563eb; padding-bottom:0.5rem;">
                    Target Date Admissions List (<?php echo htmlspecialchars($regDateVal); ?> Shift Frame)
                </h3>
                <?php if (!empty($patientRoster)): ?>
                    <table class="roster-table" id="sortableTrafficTable">
                        <thead>
                            <tr>
                                <th class="no-sort" style="cursor:default; width:45px; text-align:center;">#</th>
                                <th onclick="sortTable('sortableTrafficTable', 1, 'text')">Type</th>
                                <th onclick="sortTable('sortableTrafficTable', 2, 'text')">Patient Name</th>
                                <th onclick="sortTable('sortableTrafficTable', 3, 'text')">Patient ID</th>
                                <th onclick="sortTable('sortableTrafficTable', 4, 'text')">Case ID</th>
                                <th onclick="sortTable('sortableTrafficTable', 5, 'date')">Transaction Date/Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $trafficRowIndex = 1;
                            foreach ($patientRoster as $patient): 
                                $formattedDate = $patient['TxDateTime'] instanceof DateTime ? $patient['TxDateTime']->format('Y-m-d h:i A') : $patient['TxDateTime'];
                                $badgeClass = (strtolower($patient['PatientType']) == 'inpatient') ? 'inpatient' : 'outpatient';
                            ?>
                                <tr>
                                    <td class="row-idx-cell"><?php echo $trafficRowIndex++; ?></td>
                                    <td><span class="badge-type <?php echo $badgeClass; ?>"><?php echo $patient['PatientType']; ?></span></td>
                                    <td style="font-weight:700; color:#1e293b;"><?php echo htmlspecialchars($patient['PatientName']); ?></td>
                                    <td style="font-family:monospace; color:#475569;"><?php echo htmlspecialchars($patient['PatientID']); ?></td>
                                    <td><?php echo htmlspecialchars($patient['CaseID']); ?></td>
                                    <td style="color:#64748b;"><?php echo htmlspecialchars($formattedDate); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div style="text-align:center; padding:2rem; color:#64748b; font-weight:500;">
                        🔍 No recorded inpatient or outpatient transactions found for this specific shift parameter context.
                    </div>
                <?php endif; ?>
                <div style="text-align:right; margin-top:1rem;">
                    <button id="closePanelBtn" style="background:#0f172a; color:#fff; border:none; padding:6px 12px; border-radius:4px; font-weight:600; cursor:pointer; font-size:0.8rem;">Close Roster</button>
                </div>
            </div>

            <div class="patient-dropdown-panel" id="censusDropdownPanel">
                <h3 style="margin:0 0 1rem 0; font-size:1.05rem; font-weight:700; color:#0f172a; border-bottom:1px solid #10b981; padding-bottom:0.5rem;">
                    <?php echo ($regDateVal === $todayDateStr) ? 'Total Live Bed Pool Ledger Roster (Grand Total Active)' : 'Complete Admissions Record Log for Shift Date: ' . htmlspecialchars($regDateVal); ?>
                </h3>
                <?php if (!empty($censusRoster)): ?>
                    <table class="roster-table" id="sortableCensusTable">
                        <thead>
                            <tr>
                                <th class="no-sort" style="cursor:default; width:45px; text-align:center;">#</th>
                                <th onclick="sortTable('sortableCensusTable', 1, 'text')">Patient Name Field</th>
                                <th onclick="sortTable('sortableCensusTable', 2, 'text')">Patient ID / Account</th>
                                <th onclick="sortTable('sortableCensusTable', 3, 'text')">Case ID Reference</th>
                                <th onclick="sortTable('sortableCensusTable', 4, 'date')">Admission Stamp</th>
                                <th class="no-sort">Tracking Exception / MGH Delay Verification</th>
                                <th onclick="sortTable('sortableCensusTable', 6, 'currency')" style="text-align: right; padding-right: 1.5rem;">Balance Due</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $listRowIndex = 1;
                            foreach ($censusRoster as $cPat): 
                                $formattedCDate = $cPat['TxDateTime'] instanceof DateTime ? $cPat['TxDateTime']->format('Y-m-d h:i A') : $cPat['TxDateTime'];
                                $balanceRaw = (float)($cPat['BalanceDue'] ?? 0);
                                
                                if ($balanceRaw > 0) {
                                    $balanceStyle = 'color: #ef4444; font-weight: 700;';
                                } elseif ($balanceRaw < 0) {
                                    $balanceStyle = 'color: #2563eb; font-weight: 700;';
                                } else {
                                    $balanceStyle = 'color: #10b981; font-weight: 600;';
                                }
                                
                                $isPatientMgh = !empty($cPat['MghDateTime']);
                            ?>
                                <tr>
                                    <td class="row-idx-cell"><?php echo $listRowIndex++; ?></td>
                                    <td style="font-weight:700; color:#0f172a;"><?php echo htmlspecialchars($cPat['PatientName']); ?></td>
                                    <td style="font-family:monospace; color:#334155; font-weight:600;"><?php echo htmlspecialchars($cPat['PatientID'] ?? 'N/A'); ?></td>
                                    <td style="color:#475569; font-size:0.85rem;"><?php echo htmlspecialchars($cPat['CaseID']); ?></td>
                                    <td style="color:#64748b; font-weight:500;"><?php echo htmlspecialchars($formattedCDate); ?></td>
                                    
                                    <td>
                                        <?php if ($isPatientMgh): ?>
                                            <span class="mgh-badge-status">⚡ May Go Home Flagged</span>
                                            
                                            <?php if (strtoupper($userRole) === 'ENCODER' || strtoupper($userRole) === 'ADMIN'): ?>
                                                <form action="dashboard.php" method="POST" class="mgh-delay-form">
                                                    <input type="hidden" name="action_log_delay" value="1">
                                                    <input type="hidden" name="target_case_id" value="<?php echo (int)$cPat['CaseID']; ?>">
                                                    <div class="mgh-delay-input-group">
                                                        <input type="text" name="mgh_delay_reason" class="mgh-input-field" 
                                                               placeholder="e.g., Awaiting PhilHealth/LOA confirmation" 
                                                               value="<?php echo htmlspecialchars($cPat['MghDelayReason'] ?? ''); ?>">
                                                        <button type="submit" class="mgh-btn-save">Commit</button>
                                                    </div>
                                                </form>
                                            <?php else: ?>
                                                <?php if (!empty($cPat['MghDelayReason'])): ?>
                                                    <div class="read-only-reason-box">
                                                        <strong>Reason:</strong> <?php echo htmlspecialchars($cPat['MghDelayReason']); ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div style="font-size:0.75rem; color:#94a3b8; font-style:italic; margin-top:2px;">
                                                        No exception reason reported by system operators.
                                                    </div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            
                                        <?php else: ?>
                                            <span style="color:#94a3b8; font-size:0.8rem; font-style:italic;">Active Treatment Block</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td style="text-align: right; padding-right: 1.5rem; <?php echo $balanceStyle; ?>" data-value="<?php echo $balanceRaw; ?>">
                                        ₱<?php echo number_format($balanceRaw, 2); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div style="text-align:center; padding:2rem; color:#64748b; font-weight:500;">
                        🔍 No admission data matches within the targeted shift selection configuration.
                    </div>
                <?php endif; ?>
                <div style="text-align:right; margin-top:1rem;">
                    <button id="closeCensusPanelBtn" style="background:#10b981; color:#fff; border:none; padding:6px 12px; border-radius:4px; font-weight:600; cursor:pointer; font-size:0.8rem;">Close Ledger</button>
                </div>
            </div>

            <div class="panel-report-layout" style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-top: 1.5rem; margin-bottom:3rem;">
                <div class="card" style="background:#fff; padding:1.5rem; border-radius:8px; display:flex; flex-direction:column; align-items:center; justify-content:center; min-height:380px; border:1px solid var(--border-color);">
                    <h4 style="margin:0 0 1.5rem 0; color:#1e293b; font-weight:700; font-size:1rem; text-align:center; width:100%;">Daily Census & Discharge Profile</h4>
                    <?php if ($todayActiveCensus > 0 || $todayDischargedCount > 0): ?>
                        <div style="width:280px; height:280px; position:relative;"><canvas id="mghRatioChart"></canvas></div>
                    <?php else: ?>
                        <div style="text-align:center; padding:3rem; color:#64748b;">⚠️ No active patient census or discharge data to render visual assets.</div>
                    <?php endif; ?>
                </div>

                <div class="card" style="background:#fff; padding:1.5rem; border-radius:8px; display:flex; flex-direction:column; justify-content:space-between; min-height:380px; border:1px solid var(--border-color);">
                    <div>
                        <h4 style="margin:0 0 1rem 0; color:#1e293b; border-bottom:2px solid #f1f5f9; padding-bottom:0.5rem; font-size:1rem; font-weight:700;">
                            📋 Clinical Census Executive Summary
                        </h4>
                        <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:0.75rem 0; color:#64748b;">Current Active Bed Pool</td>
                                <td style="padding:0.75rem 0; text-align:right; font-weight:700; color:#0f172a;"><?php echo number_format($todayActiveCensus); ?> Patients</td>
                            </tr>
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:0.75rem 0; color:#3b82f6; font-weight:600;">🔵 Active Treatment Block</td>
                                <td style="padding:0.75rem 0; text-align:right; font-weight:700; color:#3b82f6;"><?php echo number_format($standardActive); ?> (<?php echo $activeBluePct; ?>%)</td>
                            </tr>
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:0.75rem 0; color:#d97706; font-weight:600;">🟡 Pending MGH Clearance</td>
                                <td style="padding:0.75rem 0; text-align:right; font-weight:700; color:#d97706;"><?php echo number_format($todayMghCensus); ?> (<?php echo $mghYellowPct; ?>%)</td>
                            </tr>
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:0.75rem 0; color:#ef4444; font-weight:600;">🔴 Processed Discharges</td>
                                <td style="padding:0.75rem 0; text-align:right; font-weight:700; color:#ef4444;"><?php echo number_format($todayDischargedCount); ?> (<?php echo $dischRedPct; ?>%)</td>
                            </tr>
                        </table>
                    </div>
                    <div style="background:#f8fafc; padding:1rem; border-radius:6px; border-left:4px solid #2563eb;">
                        <h5 style="margin:0 0 0.25rem 0; font-size:0.85rem; color:#0f172a; font-weight:700;">Live Floor Volume Assessment</h5>
                        <p style="margin:0; font-size:0.8rem; color:#64748b;">Percentages show individual volume share out of the global shift metrics footprint ($totalShiftVolume total instances handled) to remain perfectly aligned with your pie chart visualization slices.</p>
                    </div>
                </div>
            </div> 
        </div>
    </div>

    <script>
        const tCard = document.getElementById('togglePatientDropdown');
        const tPanel = document.getElementById('patientDropdownPanel');
        const tClose = document.getElementById('closePanelBtn');

        const cCard = document.getElementById('toggleCensusDropdown');
        const cPanel = document.getElementById('censusDropdownPanel');
        const cClose = document.getElementById('closeCensusPanelBtn');

        tCard.addEventListener('click', () => {
            tPanel.classList.toggle('open');
            cPanel.classList.remove('open');
        });
        tClose.addEventListener('click', () => tPanel.classList.remove('open'));

        cCard.addEventListener('click', () => {
            cPanel.classList.toggle('open');
            tPanel.classList.remove('open');
        });
        cClose.addEventListener('click', () => cPanel.classList.remove('open'));

        let sortDirections = {};
        
        function tableSortEngine(tableId, columnIndex, dataType) {
            const table = document.getElementById(tableId);
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            const stateKey = tableId + '_' + columnIndex;
            sortDirections[stateKey] = !sortDirections[stateKey];
            const isAscending = sortDirections[stateKey];
            
            rows.sort((rowA, rowB) => {
                let cellA = rowA.cells[columnIndex].textContent.trim();
                let cellB = rowB.cells[columnIndex].textContent.trim();
                
                if (dataType === 'currency') {
                    cellA = parseFloat(rowA.cells[columnIndex].getAttribute('data-value')) || 0;
                    cellB = parseFloat(rowB.cells[columnIndex].getAttribute('data-value')) || 0;
                    return isAscending ? cellA - cellB : cellB - cellA;
                }
                
                if (dataType === 'date') {
                    cellA = new Date(cellA);
                    cellB = new Date(cellB);
                    return isAscending ? cellA - cellB : cellB - cellA;
                }
                
                return isAscending ? cellA.localeCompare(cellB) : cellB.localeCompare(cellA);
            });
            
            rows.forEach(row => tbody.appendChild(row));
            rows.forEach((row, index) => {
                row.cells[0].textContent = index + 1;
            });
        }

        function sortTable(tableId, columnIndex, type) {
            tableSortEngine(tableId, columnIndex, type);
        }
    </script>

    <?php if ($todayActiveCensus > 0 || $todayDischargedCount > 0): ?>
<script>
    const ctx = document.getElementById('mghRatioChart').getContext('2d');
    new Chart(ctx, {
        type: 'pie',
        data: {
            labels: ['Pending MGH Clearance', 'Active Treatment Census', 'Processed Discharges'],
            datasets: [{
                data: [<?php echo $todayMghCensus; ?>, <?php echo $standardActive; ?>, <?php echo $todayDischargedCount; ?>],
                backgroundColor: ['#eab308', '#3b82f6', '#ef4444'],
                borderWidth: 1.5,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, font: { family: "'Inter'", size: 11 } } }
            }
        }
    });
</script>
    <?php endif; ?>
</body>
</html>