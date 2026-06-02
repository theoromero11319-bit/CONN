<?php
// --- FORCE PHILIPPINE STANDARD TIME TO SYNC SHIFT BOUNDARIES ACCURATELY ---
date_default_timezone_set('Asia/Manila');

session_start();

// --- AUTHENTICATION SHIELD ---
if (!isset($_SESSION['usercode'])) {
    header("Location: index.php");
    exit();
}

require_once 'db_connect.php';

// --- FETCH USER ROLE PRIVILEGES DIRECTLY ---
$usercode = $_SESSION['usercode'];
$roleQuery = "SELECT RolePermissions FROM [LiveDB_MSHAP].[dbo].[appTrackingUsers] WHERE UserCode = ?";
$roleStmt = sqlsrv_query($conn, $roleQuery, [$usercode]);

if ($roleStmt !== false && $roleRow = sqlsrv_fetch_array($roleStmt, SQLSRV_FETCH_ASSOC)) {
    $userRole = trim($roleRow['RolePermissions']);
} else {
    $userRole = 'Viewer'; 
}

$patient_breakdowns = [];

try {
    // --- UNIFIED HIGH-PERFORMANCE MASTER FINANCIAL LEDGER QUERY ---
    // Added explicit CAST(... AS DECIMAL(38,2)) to prevent arithmetic overflows on big aggregations
    $breakdownSQL = "
        SELECT 
            combined.CaseID,
            combined.PatientID,
            combined.PatientName,
            combined.CaseTypeLabel,
            combined.TxDateTime,
            ISNULL(items.RoomCharges, 0) as RoomCharges,
            ISNULL(items.PharmaCharges, 0) as PharmaCharges,
            ISNULL(items.LabMiscCharges, 0) as LabMiscCharges,
            ISNULL(items.GrossTotal, 0) as GrossTotal,
            ISNULL(items.PaidTotal, 0) as PaidTotal,
            ISNULL(COALESCE(soa.BalanceDue, items.LiveBalance), 0) as BalanceDue
        FROM (
            SELECT 
                PK_psPatRegisters as CaseID, 
                PatientId as PatientID, 
                PatientFullname as PatientName, 
                RegistryDate as TxDateTime, 
                'INPATIENT' as CaseTypeLabel
            FROM [LiveDB_MSHAP].[dbo].[vwInpatientMstrList]
            WHERE DischargeDate IS NULL
            
            UNION ALL
            
            SELECT 
                PK_psoutpatients as CaseID, 
                PatientId as PatientID, 
                PatientFullname as PatientName, 
                RegistryDate as TxDateTime, 
                'OUTPATIENT' as CaseTypeLabel
            FROM [LiveDB_MSHAP].[dbo].[vwOutPatientsMstrList]
        ) combined

        /* 1. EXTRACT BALANCES COMPILING FROM FINALIZED STATEMENT OF ACCOUNTS (SOA) */
        LEFT JOIN (
            SELECT FK_psPatRegisters, 
                   CAST(SUM(ISNULL(debit, 0)) - SUM(ISNULL(Credit, 0)) AS DECIMAL(38,2)) as BalanceDue
            FROM [LiveDB_MSHAP].[dbo].[vwreportSOAHB] 
            WHERE FK_psPatRegisters IS NOT NULL 
            GROUP BY FK_psPatRegisters
        ) soa ON combined.CaseID = soa.FK_psPatRegisters

        /* 2. EXTRACT LIVE RUNNING ITEMIZATION BILLING BREAKDOWNS WITH OVERFLOW PROTECTION */
        LEFT JOIN (
            SELECT FK_psPatRegisters,
                -- Room charges allocation
                SUM(CASE WHEN UPPER(RTRIM(pattrantype)) IN ('ROOM', 'ROOM CHARGES') THEN CAST((ISNULL(HospitallBill, 0) + ISNULL(ProfessionalFee, 0)) AS DECIMAL(38,2)) ELSE 0 END) as RoomCharges,
                -- Pharmacy charges allocation
                SUM(CASE WHEN UPPER(RTRIM(pattrantype)) IN ('PHARMACY', 'MEDICINE', 'DRUGS', 'PHARMA') THEN CAST((ISNULL(HospitallBill, 0) + ISNULL(ProfessionalFee, 0)) AS DECIMAL(38,2)) ELSE 0 END) as PharmaCharges,
                -- Laboratory/Miscellaneous charges allocation
                SUM(CASE WHEN UPPER(RTRIM(pattrantype)) IN ('CHARGES', 'DEBIT', 'CHARGE', 'LABORATORY', 'LAB', 'XRAY', 'PROFESSIONAL FEE') AND UPPER(RTRIM(pattrantype)) NOT IN ('ROOM', 'ROOM CHARGES', 'PHARMACY', 'MEDICINE', 'DRUGS', 'PHARMA') THEN CAST((ISNULL(HospitallBill, 0) + ISNULL(ProfessionalFee, 0)) AS DECIMAL(38,2)) ELSE 0 END) as LabMiscCharges,
                -- Gross total bill accumulator
                SUM(CASE WHEN UPPER(RTRIM(pattrantype)) IN ('CHARGES', 'DEBIT', 'CHARGE', 'ROOM', 'ROOM CHARGES', 'PROFESSIONAL FEE') THEN CAST((ISNULL(HospitallBill, 0) + ISNULL(ProfessionalFee, 0)) AS DECIMAL(38,2)) ELSE 0 END) as GrossTotal,
                -- Payments/Credits total accumulator
                SUM(CASE WHEN UPPER(RTRIM(pattrantype)) IN ('PAYMENT', 'CREDIT', 'CREDIT NOTE', 'CN', 'BENEFIT', 'PHILHEALTH', 'PHIC') THEN CAST((ISNULL(HospitallBill, 0) + ISNULL(ProfessionalFee, 0)) AS DECIMAL(38,2)) ELSE 0 END) as PaidTotal,
                -- Realtime backup calculations balance
                SUM(CASE WHEN UPPER(RTRIM(pattrantype)) IN ('CHARGES', 'DEBIT', 'CHARGE', 'ROOM', 'ROOM CHARGES', 'PROFESSIONAL FEE') THEN CAST((ISNULL(HospitallBill, 0) + ISNULL(ProfessionalFee, 0)) AS DECIMAL(38,2)) ELSE 0 END) -
                SUM(CASE WHEN UPPER(RTRIM(pattrantype)) IN ('PAYMENT', 'CREDIT', 'CREDIT NOTE', 'CN', 'BENEFIT', 'PHILHEALTH', 'PHIC') THEN CAST((ISNULL(HospitallBill, 0) + ISNULL(ProfessionalFee, 0)) AS DECIMAL(38,2)) ELSE 0 END) as LiveBalance
            FROM [LiveDB_MSHAP].[dbo].[vwBillingDtls] 
            WHERE FK_psPatRegisters IS NOT NULL 
            GROUP BY FK_psPatRegisters
        ) items ON combined.CaseID = items.FK_psPatRegisters

        -- Only return profiles with an outstanding balance
        WHERE ISNULL(COALESCE(soa.BalanceDue, items.LiveBalance), 0) > 0
        ORDER BY combined.TxDateTime DESC
    ";

    $stmt = sqlsrv_query($conn, $breakdownSQL);
    if ($stmt === false) {
        die("<pre>" . print_r(sqlsrv_errors(), true) . "</pre>");
    }

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $patient_breakdowns[] = $row;
    }
    sqlsrv_free_stmt($stmt);

} catch (Exception $e) {
    $patient_breakdowns = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Master Patient Financial Breakdown Ledger</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .text-right-monospace { text-align: right; font-family: monospace; font-weight: 600; }
        .total-highlight-cell { background: rgba(248, 250, 252, 0.75); font-weight: 700; color: #0f172a; }
        .balance-alert-cell { font-weight: 700; color: #b45309; background: #fffbeb !important; }
    </style>
</head>
<body>

    <?php include('sidebar.php'); ?>

    <div class="main-wrapper">
        
        <header class="app-header">
            <div class="title">📋 SYSTEM FINANCIAL TOOLS</div>
            <div style="font-size: 0.85rem; font-weight: 600; color: var(--text-muted);">
                User Instance Code ID: <?php echo htmlspecialchars($usercode); ?> (<?php echo htmlspecialchars($userRole); ?>)
            </div>
        </header>

        <main class="main-content">
            <div class="panel-table-container">
                <div style="margin-bottom: 0.75rem;">
                    <h2 class="panel-title" style="font-size: 1.1rem;">Unified Patient Ledger Financial Breakdown</h2>
                    <span style="font-size: 0.75rem; color: var(--text-muted);">
                        Real-time master dashboard fetching open profiles with pending account balances across both active outpatients and inpatient admissions.
                    </span>
                </div>

                <div class="table-scroll-area">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th style="width: 120px;">Patient ID</th>
                                <th>Full Patient Name</th>
                                <th style="width: 130px;">Classification</th>
                                <th>Registry Date Time</th>
                                <th class="text-right-monospace" style="width: 120px;">Room/Bed (₱)</th>
                                <th class="text-right-monospace" style="width: 120px;">Pharmacy (₱)</th>
                                <th class="text-right-monospace" style="width: 120px;">Lab/Misc (₱)</th>
                                <th class="text-right-monospace total-highlight-cell" style="width: 130px;">Gross Total (₱)</th>
                                <th class="text-right-monospace" style="width: 120px;">Amt Paid (₱)</th>
                                <th class="text-right-monospace balance-alert-cell" style="width: 130px;">Balance Due (₱)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(!empty($patient_breakdowns)): ?>
                                <?php foreach($patient_breakdowns as $row): ?>
                                <tr>
                                    <td><span class="badge-id"><?php echo htmlspecialchars($row['PatientID']); ?></span></td>
                                    <td style="font-weight: 700; color: #0f172a;"><?php echo htmlspecialchars($row['PatientName']); ?></td>
                                    <td>
                                        <span class="badge-type <?php echo strtolower($row['CaseTypeLabel']); ?>">
                                            <?php echo $row['CaseTypeLabel']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="status-chip standard" style="font-weight: 500;">
                                            <?php 
                                                if ($row['TxDateTime'] instanceof DateTime) {
                                                    echo $row['TxDateTime']->format('M d, Y h:i A');
                                                } else if (!empty($row['TxDateTime'])) {
                                                    echo date('M d, Y h:i A', strtotime($row['TxDateTime']));
                                                } else {
                                                    echo '---';
                                                }
                                            ?>
                                        </div>
                                    </td>
                                    <td class="text-right-monospace"><?php echo number_format($row['RoomCharges'], 2); ?></td>
                                    <td class="text-right-monospace"><?php echo number_format($row['PharmaCharges'], 2); ?></td>
                                    <td class="text-right-monospace"><?php echo number_format($row['LabMiscCharges'], 2); ?></td>
                                    <td class="text-right-monospace total-highlight-cell"><?php echo number_format($row['GrossTotal'], 2); ?></td>
                                    <td class="text-right-monospace" style="color: var(--success);"><?php echo number_format($row['PaidTotal'], 2); ?></td>
                                    <td class="text-right-monospace balance-alert-cell">
                                        <?php echo number_format($row['BalanceDue'], 2); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10" style="text-align: center; color: var(--text-muted); padding: 3rem; font-weight: 600;">
                                        No active ledger balance records found matching search filters.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

</body>
</html>