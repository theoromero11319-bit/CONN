<?php
// Get the current filename to determine active status
$current_page = basename($_SERVER['PHP_SELF']);
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enterprise Operations Dashboard</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="style.css">
</head>
<body>


 <?php
$current_page = basename($_SERVER['PHP_SELF']);
?>
<div class="sidebar">
    <div class="sidebar-brand">
        <div style="width:10px; height:10px; background:var(--primary); border-radius:50%;"></div>
        M.SIMON HOSPITAL AND PHARMACY
    </div>
    <ul class="sidebar-menu">
        <li class="sidebar-item <?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
            <a href="dashboard.php">📊 MAIN DASHBOARD</a>
        </li>
        <li class="sidebar-item <?php echo ($current_page == 'outpatients.php') ? 'active' : ''; ?>">
            <a href="outpatients.php">📋 OUTPATIENT</a>
        </li>
        <li class="sidebar-item <?php echo ($current_page == 'inpatients.php') ? 'active' : ''; ?>">
            <a href="inpatients.php">📋 INPATIENT</a>
        </li>
    </ul>
    <div class="sidebar-footer">
        <a href="logout.php" class="btn-signout">Sign Out System</a>
    </div>
</div>


</body>