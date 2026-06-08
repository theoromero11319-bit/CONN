<?php
// Get the current filename to determine active status
$current_page = basename($_SERVER['PHP_SELF']);
?>

<div class="sidebar">
    <div class="sidebar-brand">
        <div class="brand-title-group">
            <div style="width:10px; height:10px; background:var(--primary); border-radius:50%; flex-shrink:0;"></div>
            <span>M. SIMON HOSPITAL & PHARMACY</span>
        </div>
        <div class="brand-address-text">
            General Malvar Street, Poblacion, Ipil,<br>Zamboanga Sibugay
        </div>
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

    <div class="sidebar-motivation-widget">
        <div class="motivation-tag">✨ System Mission</div>
        <div class="motivation-quote">
            "Your dedication transforms complex data into compassionate patient care. Keep up the excellent work today."
        </div>
    </div>

    <div class="sidebar-footer">
        <a href="logout.php" class="btn-signout" style="display: block; text-align: center;">Sign Out System</a>
    </div>
</div>