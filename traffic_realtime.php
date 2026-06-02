<?php
$todayTrafficQuery = "
    SELECT COUNT(*) as total FROM (
        SELECT registrydate FROM [LiveDB_MSHAP].[dbo].[vwInpatientMstrList] WHERE CONVERT(VARCHAR(10), registrydate, 120) = ?
        UNION ALL
        SELECT registrydate FROM [LiveDB_MSHAP].[dbo].[vwOutPatientsMstrList] WHERE CONVERT(VARCHAR(10), registrydate, 120) = ?
    ) as t
";
$todayTrafficStmt = sqlsrv_query($conn, $todayTrafficQuery, [$todayDateStr, $todayDateStr]);
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
    WHERE CONVERT(VARCHAR(10), DischargeDate, 120) = ? AND RegistryDate >= ?
";
$todayDischQueryStmt = sqlsrv_query($conn, $todayDischQuery, [$todayDateStr, $censusStartDate]);
$todayDischargedCount = ($todayDischQueryStmt !== false && $row = sqlsrv_fetch_array($todayDischQueryStmt, SQLSRV_FETCH_ASSOC)) ? $row['total'] : 0;
?>