<?php
$usercode = $_SESSION['usercode'];
$roleQuery = "SELECT RolePermissions FROM [LiveDB_MSHAP].[dbo].[appTrackingUsers] WHERE UserCode = ?";
$roleStmt = sqlsrv_query($conn, $roleQuery, [$usercode]);

if ($roleStmt !== false && $roleRow = sqlsrv_fetch_array($roleStmt, SQLSRV_FETCH_ASSOC)) {
    // Read the explicit database value (e.g., "Encoder" or "Admin")
    $userRole = trim($roleRow['RolePermissions']);
} else {
    // Safe fallback if the query fails
    $userRole = 'Viewer'; 
}
?>