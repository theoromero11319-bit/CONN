<?php
session_start();
if (!isset($_SESSION['usercode'])) { 
    header("Location: index.php"); 
    exit(); 
}
require_once 'db_connect.php';

// 1. INPUT FILTER HANDLERS
$searchQuery   = isset($_REQUEST['search_query']) ? trim($_REQUEST['search_query']) : '';
$isSubmitted   = isset($_REQUEST['filter_submitted']);

// CRITICAL INTERSECTION FIX: If filter isn't submitted yet, default to Registry Date Only.
$useRegistry   = !$isSubmitted || isset($_REQUEST['use_registry']);
$useMghDate    = $isSubmitted && isset($_REQUEST['use_mgh_date']);
$useDischDate  = $isSubmitted && isset($_REQUEST['use_disch_date']);

$regDateVal    = isset($_REQUEST['reg_date_val']) && !empty($_REQUEST['reg_date_val']) ? $_REQUEST['reg_date_val'] : date('Y-m-d');
$mghDateVal    = isset($_REQUEST['mgh_date_val']) && !empty($_REQUEST['mgh_date_val']) ? $_REQUEST['mgh_date_val'] : date('Y-m-d');
$dischDateVal  = isset($_REQUEST['disch_date_val']) && !empty($_REQUEST['disch_date_val']) ? $_REQUEST['disch_date_val'] : date('Y-m-d');

$dateConditions = [];
$queryParams = [];

if ($useRegistry) { 
    $dateConditions[] = "CAST(reg.registrydate AS DATE) = ?"; 
    $queryParams[] = $regDateVal; 
}
if ($useMghDate) { 
    $dateConditions[] = "CAST(reg.mghdatetime AS DATE) = ?";  
    $queryParams[] = $mghDateVal; 
}
if ($useDischDate) { 
    $dateConditions[] = "CAST(reg.dischdate AS DATE) = ?";   
    $queryParams[] = $dischDateVal; 
}

if (!empty($dateConditions)) {
    $filterConditions = "WHERE (" . implode(" OR ", $dateConditions) . ") AND outp.FK_psPatregisters IS NOT NULL";
} else {
    // IF NO CHECKBOXES SELECTED, SHOW OUTPATIENTS REGISTERED TODAY OR ANY OUTPATIENT STUCK IN MGH STATUS
    $filterConditions = "WHERE outp.FK_psPatregisters IS NOT NULL AND (CAST(reg.registrydate AS DATE) = CAST(GETDATE() AS DATE) OR (reg.mghdatetime IS NOT NULL AND reg.mghdatetime != '1900-01-01' AND (reg.untagmghdatetime IS NULL OR reg.untagmghdatetime < reg.mghdatetime) AND (reg.dischdate IS NULL OR reg.dischdate = '1900-01-01')))";
}

if ($searchQuery !== '') {
    $filterConditions .= " AND (pat.PatientName LIKE ? OR reg.patientno LIKE ? OR outp.doctorname LIKE ? OR reg.dischdiagnosis LIKE ?)";
    $searchParam = "%" . $searchQuery . "%";
    for ($i = 0; $i < 4; $i++) { $queryParams[] = $searchParam; }
}

// 2. LIVE MGH COUNTER ENGINE 
if ($isSubmitted && ($useRegistry || $useDischDate || $useMghDate)) {
    $summaryMghQuery = "
        SELECT COUNT(reg.PK_psPatRegisters) as targeted_mgh_count 
        FROM [LiveDB_MSHAP].[dbo].[psPatRegisters] reg
        INNER JOIN [LiveDB_MSHAP].[dbo].[psOutpatients] outp ON reg.PK_psPatRegisters = outp.FK_psPatregisters
        LEFT JOIN [LiveDB_MSHAP].[dbo].[vwPatientMstrList] pat ON reg.patientno = pat.PatientID
        $filterConditions 
        AND reg.mghdatetime IS NOT NULL 
        AND reg.mghdatetime != '1900-01-01'
        AND (reg.untagmghdatetime IS NULL OR reg.untagmghdatetime < reg.mghdatetime)
    ";
} else {
    // Global Outpatient Counter
    $summaryMghQuery = "
        SELECT COUNT(reg.PK_psPatRegisters) as targeted_mgh_count 
        FROM [LiveDB_MSHAP].[dbo].[psPatRegisters] reg
        INNER JOIN [LiveDB_MSHAP].[dbo].[psOutpatients] outp ON reg.PK_psPatRegisters = outp.FK_psPatregisters
        WHERE reg.mghdatetime IS NOT NULL 
        AND reg.mghdatetime != '1900-01-01'
        AND (reg.untagmghdatetime IS NULL OR reg.untagmghdatetime < reg.mghdatetime)
        AND (reg.dischdate IS NULL OR reg.dischdate = '1900-01-01')
    ";
}
$summaryStmt = sqlsrv_query($conn, $summaryMghQuery, $queryParams);
$summaryRow = sqlsrv_fetch_array($summaryStmt, SQLSRV_FETCH_ASSOC);
$mghSummaryCounter = $summaryRow['targeted_mgh_count'] ?? 0;

// 3. PAGINATION ARCHITECTURE
$limit = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$countQuery = "
    SELECT COUNT(*) as total 
    FROM [LiveDB_MSHAP].[dbo].[psPatRegisters] reg 
    INNER JOIN [LiveDB_MSHAP].[dbo].[psOutpatients] outp ON reg.PK_psPatRegisters = outp.FK_psPatregisters 
    LEFT JOIN [LiveDB_MSHAP].[dbo].[vwPatientMstrList] pat ON reg.patientno = pat.PatientID 
    $filterConditions
";
$countStmt = sqlsrv_query($conn, $countQuery, $queryParams);
$countRow = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC);
$totalRecords = $countRow['total'] ?? 0;
$totalPages = ceil($totalRecords / $limit) ?: 1;
if ($page > $totalPages) $page = $totalPages;

// 4. CORE DATA EXTRACTION
$mainQuery = "
    SELECT 
        reg.patientno, 
        pat.PatientName, 
        CONVERT(VARCHAR(16), reg.registrydate, 120) as display_regdate,
        CASE 
            WHEN reg.mghdatetime IS NULL OR reg.mghdatetime = '1900-01-01' THEN 'NONE'
            WHEN reg.untagmghdatetime IS NOT NULL AND reg.untagmghdatetime >= reg.mghdatetime THEN 'NONE'
            ELSE CONVERT(VARCHAR(16), reg.mghdatetime, 120)
        END AS display_mghdate, 
        CASE 
            WHEN reg.dischdate IS NULL OR reg.dischdate = '1900-01-01' THEN 'NONE'
            ELSE CONVERT(VARCHAR(16), reg.dischdate, 120)
        END AS display_dischdate,
        COALESCE(NULLIF(reg.dischdiagnosis, ''), 'Pending Assessment Evaluation') as diagnosis, 
        COALESCE(NULLIF(outp.doctorname, ''), 'Unassigned Consultant') as primary_doctor
    FROM [LiveDB_MSHAP].[dbo].[psPatRegisters] reg
    INNER JOIN [LiveDB_MSHAP].[dbo].[psOutpatients] outp ON reg.PK_psPatRegisters = outp.FK_psPatregisters
    LEFT JOIN [LiveDB_MSHAP].[dbo].[vwPatientMstrList] pat ON reg.patientno = pat.PatientID
    $filterConditions 
    ORDER BY 
        CASE 
            WHEN reg.mghdatetime IS NOT NULL AND reg.mghdatetime != '1900-01-01' AND (reg.untagmghdatetime IS NULL OR reg.untagmghdatetime < reg.mghdatetime) THEN 0 
            ELSE 1 
        END ASC,
        reg.registrydate DESC 
    OFFSET $offset ROWS FETCH NEXT $limit ROWS ONLY
";
$mainStmt = sqlsrv_query($conn, $mainQuery, $queryParams);
if ($mainStmt === false) {
    die("<pre>Ledger Processing Error: " . print_r(sqlsrv_errors(), true) . "</pre>");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Outpatient Directory Ledger</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="style.css">
    <style>
        .roster-table th {
            cursor: pointer;
            position: relative;
            user-select: none;
            background: #f8fafc;
            padding: 12px 10px;
            border-bottom: 2px solid #e2e8f0;
            text-align: left;
            font-size: 0.85rem;
            color: #475569;
        }
        .roster-table th:not(.no-sort):after {
            content: ' ↕';
            font-size: 0.75rem;
            color: #94a3b8;
        }
        .roster-table td {
            padding: 12px 10px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.85rem;
        }
        .row-idx-cell {
            font-weight: 700;
            color: #64748b;
            background: #f8fafc;
            text-align: center;
            width: 45px;
        }
        .panel {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }

        /* --- UNIFIED PREMIUM DESIGN FILTER CONSOLE ENGINE --- */
        .modern-filter-panel {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-top: 4px solid #2563eb;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.04);
        }
        .modern-filter-title {
            font-size: 0.9rem;
            font-weight: 700;
            color: #1e293b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .modern-filter-grid {
            display: grid;
            grid-template-columns: 1.5fr repeat(3, 1fr) auto;
            gap: 1rem;
            align-items: end;
        }
        @media (max-width: 1024px) {
            .modern-filter-grid { grid-template-columns: 1fr; gap: 1rem; }
        }
        .filter-group-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 0.75rem;
            transition: all 0.2s ease;
        }
        .filter-group-box:focus-within {
            border-color: #3b82f6;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        .modern-filter-label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.8rem;
            font-weight: 700;
            color: #475569;
            margin-bottom: 0.5rem;
            cursor: pointer;
            user-select: none;
        }
        .modern-filter-label input[type="checkbox"] {
            width: 14px;
            height: 14px;
            accent-color: #2563eb;
            cursor: pointer;
        }
        .modern-input-text {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 8px 10px;
            font-size: 0.85rem;
            color: #0f172a;
            font-family: 'Inter', sans-serif;
            background-color: #ffffff;
            outline: none;
            box-sizing: border-box;
            transition: border-color 0.15s ease;
            height: 38px;
        }
        .modern-date-input {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 6px 8px;
            font-size: 0.8rem;
            color: #0f172a;
            font-family: 'Inter', sans-serif;
            background-color: #ffffff;
            outline: none;
            box-sizing: border-box;
            transition: border-color 0.15s ease;
            height: 32px;
        }
        .modern-input-text:focus, .modern-date-input:focus {
            border-color: #2563eb;
        }
        .modern-btn-submit {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: #ffffff;
            border: none;
            height: 38px;
            padding: 0 1.5rem;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            box-shadow: 0 2px 4px rgba(37, 99, 235, 0.2);
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        .modern-btn-submit:hover {
            background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(37, 99, 235, 0.3);
        }
    </style>
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <div class="main-wrapper">
        <header class="app-header">
            <div class="title">Outpatient Tracker Center</div>
            <div style="font-size:0.85rem; font-weight:600;">
                User logged: <span style="font-weight:700; color:var(--primary);"><?php echo htmlspecialchars($_SESSION['usercode']); ?></span>
            </div>
        </header>
        
        <div class="main-content">
            
            <div style="display: grid; grid-template-columns: 1fr; margin-bottom: 1.5rem;">
                <div style="background: linear-gradient(135deg, #fef9c3 0%, #fef08a 100%); border: 1px solid #eab308; padding: 1.25rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); display: flex; align-items: center; justify-content: space-between;">
                    <div>
                        <h4 style="margin: 0; color: #854d0e; font-size: 0.9rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Outpatients Flagged MGH within Filter Parameters</h4>
                        <p style="margin: 4px 0 0 0; color: #a16207; font-size: 0.8rem; font-weight: 500;">Updates automatically based on the checkbox filters selected below.</p>
                    </div>
                    <div style="text-align: right;">
                        <span style="font-size: 2.25rem; font-weight: 800; color: #854d0e; line-height: 1;"><?php echo number_format($mghSummaryCounter); ?></span>
                        <span style="display: block; font-size: 0.7rem; font-weight: 700; color: #a16207; margin-top: 2px;">TOTAL CASES</span>
                    </div>
                </div>
            </div>

            <div class="modern-filter-panel">
                <div class="modern-filter-title">⚙️ Outpatient Parameters Control Console</div>
                <form action="outpatients.php" method="GET">
                    <input type="hidden" name="filter_submitted" value="1">
                    <div class="modern-filter-grid">
                        
                        <div class="filter-group-box">
                            <label class="modern-filter-label" style="margin-bottom:0.25rem;">Search Directory</label>
                            <input type="text" name="search_query" value="<?php echo htmlspecialchars($searchQuery); ?>" class="modern-input-text" placeholder="Name, ID, Doctor, Diagnosis...">
                        </div>
                        
                        <div class="filter-group-box">
                            <label class="modern-filter-label">
                                <input type="checkbox" name="use_registry" <?php echo $useRegistry ? 'checked' : ''; ?>> Registry Date
                            </label>
                            <input type="date" name="reg_date_val" value="<?php echo htmlspecialchars($regDateVal); ?>" class="modern-date-input">
                        </div>
                        
                        <div class="filter-group-box">
                            <label class="modern-filter-label">
                                <input type="checkbox" name="use_mgh_date" <?php echo $useMghDate ? 'checked' : ''; ?>> MGH Date
                            </label>
                            <input type="date" name="mgh_date_val" value="<?php echo htmlspecialchars($mghDateVal); ?>" class="modern-date-input">
                        </div>
                        
                        <div class="filter-group-box">
                            <label class="modern-filter-label">
                                <input type="checkbox" name="use_disch_date" <?php echo $useDischDate ? 'checked' : ''; ?>> Discharge Date
                            </label>
                            <input type="date" name="disch_date_val" value="<?php echo htmlspecialchars($dischDateVal); ?>" class="modern-date-input">
                        </div>
                        
                        <div>
                            <button type="submit" class="modern-btn-submit">Apply Parameters</button>
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="panel" style="padding:0; overflow:hidden;">
                <div style="overflow-x:auto;">
                    <table class="roster-table" id="outpatientLedgerTable" style="width:100%; border-collapse:collapse;">
                        <thead>
                            <tr>
                                <th class="no-sort" style="cursor:default; text-align:center;">#</th>
                                <th onclick="sortTable(1, 'text')">Patient ID</th>
                                <th onclick="sortTable(2, 'text')">Full Name</th>
                                <th onclick="sortTable(3, 'date')">Registry Date</th>
                                <th onclick="sortTable(4, 'date')">MGH Date</th>
                                <th onclick="sortTable(5, 'text')">Diagnosis / Evaluation</th>
                                <th onclick="sortTable(6, 'text')">Attending Physician</th>
                                <th onclick="sortTable(7, 'date')">Discharge Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($totalRecords == 0): ?>
                                <tr>
                                    <td colspan="8" style="padding:3rem; text-align:center; color:#64748b; font-weight:500;">
                                        🔍 No outpatient tracking profiles matched this filter selection.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php 
                                $listRowCounter = $offset + 1;
                                while ($row = sqlsrv_fetch_array($mainStmt, SQLSRV_FETCH_ASSOC)): 
                                ?>
                                    <tr>
                                        <td class="row-idx-cell"><?php echo $listRowCounter++; ?></td>
                                        <td><span class="badge-id" style="font-family:monospace; font-weight:600; color:#334155;">
                                            <?php echo htmlspecialchars($row['patientno']); ?>
                                        </span></td>
                                        <td style="font-weight:700; color:#0f172a;"><?php echo htmlspecialchars($row['PatientName']); ?></td>
                                        <td style="color:#475569; font-weight:500;"><?php echo htmlspecialchars($row['display_regdate'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php if ($row['display_mghdate'] !== 'NONE' && !empty($row['display_mghdate'])): ?>
                                                <span style="background:#fef9c3; color:#854d0e; padding:4px 8px; border-radius:4px; font-weight:700; font-size:0.75rem; display:inline-block; border: 1px solid #fef08a;">
                                                    ⚡ <?php echo htmlspecialchars($row['display_mghdate']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="background:#f1f5f9; color:#64748b; padding:2px 6px; border-radius:4px; font-size:0.75rem;">None Logged</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="max-width:240px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; color:#475569;" title="<?php echo htmlspecialchars($row['diagnosis']); ?>">
                                            <?php echo htmlspecialchars($row['diagnosis']); ?>
                                        </td>
                                        <td><span style="color:#2563eb; font-weight:600;">Dr. <?php echo htmlspecialchars($row['primary_doctor']); ?></span></td>
                                        <td>
                                            <?php if ($row['display_dischdate'] !== 'NONE' && !empty($row['display_dischdate'])): ?>
                                                <span style="background:#dcfce7; color:#15803d; padding:4px 8px; border-radius:4px; font-weight:700; font-size:0.75rem; display:inline-block; border:1px solid #bbf7d0;">
                                                    ✅ Out: <?php echo htmlspecialchars($row['display_dischdate']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="background:#eff6ff; color:#1d4ed8; padding:4px 8px; border-radius:4px; font-weight:700; font-size:0.75rem; display:inline-block; border:1px solid #dbeafe;">
                                                    🔄 In Clinic
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                1<?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div style="display:flex; justify-content:space-between; align-items:center; padding:1rem; background:#f8fafc; border-top:1px solid #e2e8f0; font-size:0.85rem; font-weight:500; color:#475569;">
                    <span>Total Found: <strong><?php echo number_format($totalRecords); ?></strong> records (Page <?php echo $page; ?> of <?php echo $totalPages; ?>)</span>
                    <div style="display:flex; gap:6px;">
                        <?php 
                        // Build base URL parameters cleanly in PHP backend
                        $baseParams = [
                            'search_query' => $searchQuery,
                            'reg_date_val' => $regDateVal,
                            'mgh_date_val' => $mghDateVal,
                            'disch_date_val' => $dischDateVal,
                            'filter_submitted' => '1'
                        ];
                        if ($useRegistry) $baseParams['use_registry'] = '1';
                        if ($useMghDate) $baseParams['use_mgh_date'] = '1';
                        if ($useDischDate) $baseParams['use_disch_date'] = '1';

                        // Build individual target query states safely
                        $prevParams = array_merge($baseParams, ['page' => ($page - 1)]);
                        $nextParams = array_merge($baseParams, ['page' => ($page + 1)]);
                        
                        $prevUrl = "outpatients.php?" . http_build_query($prevParams);
                        $nextUrl = "outpatients.php?" . http_build_query($nextParams);
                        ?>
                        
                        <a href="<?php echo htmlspecialchars($prevUrl); ?>" class="btn-page" style="text-decoration:none; padding:5px 12px; background:#fff; border:1px solid #cbd5e1; border-radius:4px; color:#334155; font-weight:600; font-size:0.8rem; <?php echo ($page <= 1) ? 'opacity:0.5; pointer-events:none;' : ''; ?>">Prev</a>
                        <a href="<?php echo htmlspecialchars($nextUrl); ?>" class="btn-page" style="text-decoration:none; padding:5px 12px; background:#fff; border:1px solid #cbd5e1; border-radius:4px; color:#334155; font-weight:600; font-size:0.8rem; <?php echo ($page >= $totalPages) ? 'opacity:0.5; pointer-events:none;' : ''; ?>">Next</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let sortDirections = {};
        
        function tableSortEngine(tableId, columnIndex, dataType) {
            const table = document.getElementById(tableId);
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            if(rows.length === 1 && rows[0].cells.length === 1) return;
            
            const stateKey = tableId + '_' + columnIndex;
            sortDirections[stateKey] = !sortDirections[stateKey];
            const isAscending = sortDirections[stateKey];
            
            rows.sort((rowA, rowB) => {
                let cellA = rowA.cells[columnIndex].textContent.trim();
                let cellB = rowB.cells[columnIndex].textContent.trim();
                
                if (dataType === 'date') {
                    if (cellA.includes('None Logged') || cellA === 'NONE') cellA = isAscending ? '9999-12-31' : '1900-01-01';
                    if (cellB.includes('None Logged') || cellB === 'NONE') cellB = isAscending ? '9999-12-31' : '1900-01-01';
                    
                    cellA = cellA.replace(/⚡|✅ Out:/g, '').trim();
                    cellB = cellB.replace(/⚡|✅ Out:/g, '').trim();
                    
                    return isAscending ? new Date(cellA) - new Date(cellB) : new Date(cellB) - new Date(cellA);
                }
                
                return isAscending ? cellA.localeCompare(cellB) : cellB.localeCompare(cellA);
            });
            
            rows.forEach(row => tbody.appendChild(row));
            
            const currentOffset = <?php echo (int)$offset; ?>;
            rows.forEach((row, index) => {
                row.cells[0].textContent = currentOffset + index + 1;
            });
        }

        function sortTable(columnIndex, type) {
            tableSortEngine('outpatientLedgerTable', columnIndex, type);
        }
    </script>
</body>
</html>