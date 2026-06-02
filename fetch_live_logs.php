<?php
session_start();
header('Content-Type: application/json');

// Security gate: block unauthorized background pings
if (!isset($_SESSION['usercode']) || strtolower(trim($_SESSION['usercode'])) !== 'admin') {
    echo json_encode([]);
    exit();
}

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
    echo json_encode([]);
    exit();
}

// Get the cutoff timestamp sent by JavaScript (defaults to 1 minute ago if not provided)
$lastSeenTime = isset($_GET['since']) ? $_GET['since'] : date('Y-m-d H:i:s', strtotime('-1 minute'));

// Query only records that are newer than the last check window
$sql = "SELECT RTRIM(UserCode) as UserCode, ActionType, LogDescription, LogDateTime 
        FROM [dbo].[appTrackingLogs] 
        WHERE LogDateTime > ? 
        ORDER BY LogDateTime ASC";

$stmt = sqlsrv_query($conn, $sql, array($lastSeenTime));
$newLogs = [];

if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $newLogs[] = [
            'UserCode' => $row['UserCode'],
            'ActionType' => trim($row['ActionType']),
            'LogDescription' => $row['LogDescription'],
            'LogDateTime' => $row['LogDateTime']->format('Y-m-d H:i:s')
        ];
    }
}

echo json_encode($newLogs);
exit();