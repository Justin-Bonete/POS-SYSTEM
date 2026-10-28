<?php
$drugstoreHeaderIcon = $drugstoreHeaderIcon ?? 'fa-pills';
$drugstoreHeaderTitle = $drugstoreHeaderTitle ?? 'Drugstore';
$drugstoreHeaderSubtitle = $drugstoreHeaderSubtitle ?? '';
$drugstoreHeaderActions = $drugstoreHeaderActions ?? '';

$drugstoreUserLabel = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';
$drugstoreUserRole = ucfirst((string)($_SESSION['role'] ?? 'Staff'));
$drugstoreUserSeed = $_SESSION['username'] ?? $drugstoreUserLabel ?? 'U';
$drugstoreUserInitial = strtoupper(substr((string)$drugstoreUserSeed, 0, 1));
?>
<div class="header">
    <div class="greeting">
        <h1>
            <i class="fas <?php echo htmlspecialchars($drugstoreHeaderIcon, ENT_QUOTES, 'UTF-8'); ?>"></i>
            <?php echo htmlspecialchars($drugstoreHeaderTitle, ENT_QUOTES, 'UTF-8'); ?>
        </h1>
        <?php if ($drugstoreHeaderSubtitle !== ''): ?>
            <p><?php echo htmlspecialchars($drugstoreHeaderSubtitle, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
    </div>
    <div class="user-menu">
        <?php if (trim((string)$drugstoreHeaderActions) !== ''): ?>
            <div class="page-actions">
                <?php echo $drugstoreHeaderActions; ?>
            </div>
        <?php endif; ?>
        <div class="user-info">
            <div class="user-avatar"><?php echo htmlspecialchars($drugstoreUserInitial, ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="user-details">
                <div class="user-name"><?php echo htmlspecialchars($drugstoreUserLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="user-role"><?php echo htmlspecialchars($drugstoreUserRole, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </div>
        <a href="/pos-system/index.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </div>
</div>
