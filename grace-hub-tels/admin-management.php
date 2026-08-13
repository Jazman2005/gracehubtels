<?php
require_once __DIR__.'/core/auth.php';
auth_admin();
// Superseded by staff-management.php, which operates on the same `admin`
// table but additionally handles username and the granular JSON permissions
// list the rest of this panel's nav-gating depends on (_has_perm() in
// partials/sidebar.php) — this page's create_admin() never set permissions
// at all, so an admin created here would end up locked out of everything
// except whatever the role's bypass covers. Kept as a redirect rather than
// deleted so any bookmarked/old link still lands somewhere correct.
header('Location: staff-management.php');
exit;
