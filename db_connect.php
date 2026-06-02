<?php
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
    die("<pre>" . print_r(sqlsrv_errors(), true) . "</pre>");
}
?>