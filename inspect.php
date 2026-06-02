<?php
// Database Connection Parameters
$serverName = "MSHPSERVER";
$connectionOptions = [
    "Database" => "TrainingDB_MSHAP",
    "Uid" => "sa",
    "PWD" => "p@ssw0rd",
    "TrustServerCertificate" => true,
    "Encrypt" => false
];

$conn = sqlsrv_connect($serverName, $connectionOptions);
if ($conn === false) {
    die("<pre>" . print_r(sqlsrv_errors(), true) . "</pre>");
}


$tsql = "SELECT TOP 1 usercode, userpass FROM appsysUsers";
$stmt = sqlsrv_query($conn, $tsql);

if ($stmt === false) {
    die("<pre>" . print_r(sqlsrv_errors(), true) . "</pre>");
}

if ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $rawPassword = $row['userpass'];
    $length = strlen($rawPassword);
    
    echo "<h2>Database Password Inspection</h2>";
    echo "<strong>User Code:</strong> " . htmlspecialchars($row['usercode']) . "<br><br>";
    echo "<strong>Stored Password Value:</strong> <style>code{background:#f4f4f4;padding:2px 6px;font-size:1.2em;}</style><code>" . htmlspecialchars($rawPassword) . "</code><br><br>";
    echo "<strong>Character Length:</strong> " . $length . " characters long.<br><br>";
    
    echo "<hr><h3>What this length usually means:</h3>";
    if ($length === 32) {
        echo "💡 <strong>32 Characters:</strong> This is highly likely an <strong>MD5</strong> hash.";
    } elseif ($length === 40) {
        echo "💡 <strong>40 Characters:</strong> This is highly likely a <strong>SHA-1</strong> hash.";
    } elseif ($length === 64) {
        echo "💡 <strong>64 Characters:</strong> This is highly likely a <strong>SHA-256</strong> hash.";
    } elseif ($length === 60 && str_starts_with($rawPassword, '$2')) {
        echo "💡 <strong>60 Characters starting with $:</strong> This is standard PHP <strong>Bcrypt (password_hash)</strong>.";
    } else {
        echo "❓ <strong>Custom/Other Length:</strong> If it's short and readable, it's plain text. If it contains symbols like '+' or '=', it might be Base64 encoded or triple-DES encrypted.";
    }
} else {
    echo "The appsysUser table appears to be empty.";
}

sqlsrv_free_stmt($stmt);
sqlsrv_close($conn);
?>