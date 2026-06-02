<?php
$updateSuccess = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_log_delay'])) {
    
    // Check uppercase comparison against the freshly queried database role
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
?>