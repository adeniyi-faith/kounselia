<?php
/**
 * Kounselia Admin — shared top navigation.
 */
$kounselia_admin_active = isset( $kounselia_admin_active ) ? $kounselia_admin_active : '';

$kounselia_nav_is_super = in_array('administrator', $kounselia_admin_user->roles);
$kounselia_nav_perms_json = get_user_meta( $kounselia_admin_user->ID, 'kounselia_permissions', true );
$kounselia_nav_perms = $kounselia_nav_perms_json ? json_decode($kounselia_nav_perms_json, true) : array();

function kounselia_nav_link( $slug, $href, $label ) {
    global $kounselia_admin_active, $kounselia_nav_is_super, $kounselia_nav_perms;
    
    // Hide links this staff member does not have permission to access
    if ( ! $kounselia_nav_is_super && $slug !== 'dashboard' && $slug !== 'settings' ) {
        if ( ! is_array($kounselia_nav_perms) || ! in_array($slug, $kounselia_nav_perms) ) {
            return; 
        }
    }

    $active_class = ( $slug === $kounselia_admin_active ) ? ' active' : '';
    printf(
        '<a class="%s" href="%s">%s</a>',
        esc_attr( trim( 'nav-link' . $active_class ) ),
        esc_url( $href ),
        esc_html( $label )
    );
}
?>
<div class="admin-topbar" style="position:relative">
  <button class="nav-toggle" id="kounseliaNavToggle" aria-label="Open menu" aria-expanded="false">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
  </button>

  <div class="admin-logo">
    <img src="https://kounselia.com/img/Kounselia_Logo_IconMark_MidnightNavy.png" alt="Kounselia" class="admin-site-logo" fetchpriority="high">
  </div>

  <nav class="admin-nav" id="kounseliaNav">
    <?php kounselia_nav_link( 'dashboard', '/portal/admin/pages/dashboard.php', 'Dashboard' ); ?>
    <?php kounselia_nav_link( 'ai-brain', '/portal/admin/pages/ai-brain.php', 'AI Brain' ); ?>
    <?php kounselia_nav_link( 'ai-collaboration', '/portal/admin/pages/ai-collaboration.php', 'AI Collaboration' ); ?>
    <?php kounselia_nav_link( 'broadcasts', '/portal/admin/pages/broadcasts.php', 'Broadcasts' ); ?>
    <?php kounselia_nav_link( 'conversations', '/portal/admin/pages/conversations.php', 'Conversations' ); ?>
    <?php kounselia_nav_link( 'audit-log', '/portal/admin/pages/audit-log.php', 'Audit Log' ); ?>
    <?php kounselia_nav_link( 'safety', '/portal/admin/pages/safety-flags.php', 'Safety' ); ?>
    <?php kounselia_nav_link( 'professionals', '/portal/admin/pages/professionals.php', 'Professionals' ); ?>
    <?php kounselia_nav_link( 'plans', '/portal/admin/pages/plans.php', 'Plans & Pricing' ); ?>
    <?php kounselia_nav_link( 'members', '/portal/admin/pages/members.php', 'Members CRM' ); ?>
    <?php kounselia_nav_link( 'memory-center', '/portal/admin/pages/memory-center.php', 'Memory Center' ); ?>
    <?php kounselia_nav_link( 'counselors', '/portal/admin/pages/counselors.php', 'Counselors' ); ?>
    <?php kounselia_nav_link( 'team', '/portal/admin/pages/team.php', 'Team' ); ?>
    <?php kounselia_nav_link( 'settings', '/portal/admin/pages/settings.php', 'Settings' ); ?>
  </nav>

  <div class="admin-who">
    <span class="who-name"><?php echo esc_html( $kounselia_admin_user->display_name ); ?></span>
    <a href="/portal/admin/logout.php">Sign out</a>
  </div>
</div>
<script>
(function(){
  var btn = document.getElementById('kounseliaNavToggle');
  var nav = document.getElementById('kounseliaNav');
  if(!btn || !nav) return;
  btn.addEventListener('click', function(){
    var open = nav.classList.toggle('open');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
  document.addEventListener('click', function(e){
    if(!nav.classList.contains('open')) return;
    if(nav.contains(e.target) || btn.contains(e.target)) return;
    nav.classList.remove('open');
    btn.setAttribute('aria-expanded','false');
  });
})();
</script>