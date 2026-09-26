<?php
/**
 * Kounselia Admin — navigation sidebar.
 *
 * Desktop: a fixed sidebar on the left with the admin areas grouped by
 * purpose, small "needs attention" counts (open safety alerts, waiting
 * professional applications), and the signed-in admin at the bottom.
 * Phones/tablets (< 960px): a slim top bar with a menu button that slides
 * the same sidebar in as a drawer (Esc, the backdrop or a link closes it).
 *
 * Every page requires this right after <body>; admin-styles.php shifts
 * the page content over when the sidebar is present.
 */
$kounselia_admin_active = isset( $kounselia_admin_active ) ? $kounselia_admin_active : '';

$kounselia_nav_is_super   = in_array( 'administrator', $kounselia_admin_user->roles, true );
$kounselia_nav_perms_json = get_user_meta( $kounselia_admin_user->ID, 'kounselia_permissions', true );
$kounselia_nav_perms      = $kounselia_nav_perms_json ? json_decode( $kounselia_nav_perms_json, true ) : array();

if ( ! function_exists( 'kounselia_nav_allowed' ) ) {
    /**
     * Staff only see the areas they were given on the Team page. The
     * Dashboard and Settings are always visible (as before).
     */
    function kounselia_nav_allowed( $slug ) {
        global $kounselia_nav_is_super, $kounselia_nav_perms;
        if ( $kounselia_nav_is_super || 'dashboard' === $slug || 'settings' === $slug ) {
            return true;
        }
        return is_array( $kounselia_nav_perms ) && in_array( $slug, $kounselia_nav_perms, true );
    }
}

// Small counts that tell an admin where they're needed, computed only for
// areas this person can open. Cheap, indexed queries.
global $wpdb;
$kounselia_nav_badges = array();
$kounselia_nav_quiet  = $wpdb->suppress_errors();
if ( kounselia_nav_allowed( 'safety' ) ) {
    $kounselia_nav_badges['safety'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_safety_escalations WHERE status = 'open'" );
}
if ( kounselia_nav_allowed( 'professionals' ) ) {
    $kounselia_nav_badges['professionals'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_professionals WHERE status = 'pending'" );
}
$wpdb->suppress_errors( $kounselia_nav_quiet );

$kounselia_nav_groups = array(
    ''                  => array(
        array( 'dashboard', '/portal/admin/pages/dashboard.php', 'Dashboard', 'layout-dashboard' ),
    ),
    'People'            => array(
        array( 'members', '/portal/admin/pages/members.php', 'Members CRM', 'users' ),
        array( 'professionals', '/portal/admin/pages/professionals.php', 'Professionals', 'stethoscope' ),
        array( 'bookings', '/portal/admin/pages/bookings.php', 'Bookings', 'calendar-event' ),
    ),
    'Care & safety'     => array(
        array( 'safety', '/portal/admin/pages/safety-flags.php', 'Safety', 'shield-heart' ),
        array( 'conversations', '/portal/admin/pages/conversations.php', 'Conversations', 'messages' ),
        array( 'counselors', '/portal/admin/pages/counselors.php', 'Counselors', 'user-heart' ),
        array( 'memory-center', '/portal/admin/pages/memory-center.php', 'Memory Center', 'brain' ),
        array( 'ai-brain', '/portal/admin/pages/ai-brain.php', 'AI Brain', 'cpu' ),
        array( 'ai-collaboration', '/portal/admin/pages/ai-collaboration.php', 'AI Collaboration', 'affiliate' ),
    ),
    'Content & outreach' => array(
        array( 'broadcasts', '/portal/admin/pages/newsletter.php', 'Newsletter', 'mail' ),
        array( 'blog', '/portal/admin/pages/blog.php', 'Blog', 'feather' ),
        array( 'pages', '/portal/admin/pages/pages.php', 'Pages', 'file-text' ),
    ),
    'Business'          => array(
        array( 'plans', '/portal/admin/pages/plans.php', 'Plans & Pricing', 'credit-card' ),
    ),
    'Administration'    => array(
        array( 'team', '/portal/admin/pages/team.php', 'Team', 'user-shield' ),
        array( 'audit-log', '/portal/admin/pages/audit-log.php', 'Audit Log', 'list-search' ),
        array( 'settings', '/portal/admin/pages/settings.php', 'Settings', 'settings' ),
    ),
);
$kounselia_nav_initial = mb_strtoupper( mb_substr( $kounselia_admin_user->display_name ? $kounselia_admin_user->display_name : $kounselia_admin_user->user_email, 0, 1 ) );
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">

<header class="admin-mobilebar">
  <button class="nav-toggle" id="kounseliaNavToggle" aria-label="Open menu" aria-expanded="false" aria-controls="kounseliaNav">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
  </button>
  <a class="admin-logo" href="/portal/admin/pages/dashboard.php">
    <img src="https://kounselia.com/img/Kounselia_Logo_IconMark_MidnightNavy.png" alt="Kounselia" class="admin-site-logo">
    <small>Admin</small>
  </a>
  <span class="admin-mobilebar-av" aria-hidden="true"><?php echo esc_html( $kounselia_nav_initial ); ?></span>
</header>

<div class="admin-nav-backdrop" id="kounseliaNavBackdrop"></div>

<aside class="admin-sidebar" id="kounseliaNav" aria-label="Admin navigation">
  <div class="admin-sidebar-head">
    <a class="admin-logo" href="/portal/admin/pages/dashboard.php">
      <img src="https://kounselia.com/img/Kounselia_Logo_IconMark_MidnightNavy.png" alt="Kounselia" class="admin-site-logo">
      <small>Admin</small>
    </a>
    <button class="admin-sidebar-close" id="kounseliaNavClose" aria-label="Close menu"><i class="ti ti-x"></i></button>
  </div>

  <nav class="admin-nav">
    <?php foreach ( $kounselia_nav_groups as $kounselia_group => $kounselia_links ) :
        $kounselia_links = array_filter( $kounselia_links, function ( $l ) {
            return kounselia_nav_allowed( $l[0] );
        } );
        if ( ! $kounselia_links ) {
            continue;
        }
        ?>
      <div class="admin-nav-group">
        <?php if ( $kounselia_group ) : ?><div class="admin-nav-label"><?php echo esc_html( $kounselia_group ); ?></div><?php endif; ?>
        <?php foreach ( $kounselia_links as $kounselia_link ) :
            $kounselia_is_active = $kounselia_link[0] === $kounselia_admin_active;
            $kounselia_badge     = isset( $kounselia_nav_badges[ $kounselia_link[0] ] ) ? $kounselia_nav_badges[ $kounselia_link[0] ] : 0;
            ?>
          <a class="nav-link<?php echo $kounselia_is_active ? ' active' : ''; ?>" href="<?php echo esc_url( $kounselia_link[1] ); ?>"<?php echo $kounselia_is_active ? ' aria-current="page"' : ''; ?>>
            <i class="ti ti-<?php echo esc_attr( $kounselia_link[3] ); ?>"></i>
            <span><?php echo esc_html( $kounselia_link[2] ); ?></span>
            <?php if ( $kounselia_badge ) : ?><b class="nav-badge<?php echo 'safety' === $kounselia_link[0] ? ' urgent' : ''; ?>" title="Needs attention"><?php echo $kounselia_badge > 99 ? '99+' : (int) $kounselia_badge; ?></b><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </nav>

  <div class="admin-who">
    <span class="who-av"><?php echo esc_html( $kounselia_nav_initial ); ?></span>
    <span class="who-meta">
      <span class="who-name"><?php echo esc_html( $kounselia_admin_user->display_name ); ?></span>
      <span class="who-role"><?php echo $kounselia_nav_is_super ? 'Super admin' : 'Staff'; ?></span>
    </span>
    <a class="who-out" href="/portal/admin/logout.php" title="Sign out" aria-label="Sign out"><i class="ti ti-logout"></i></a>
  </div>
</aside>

<script>
(function(){
  var nav = document.getElementById('kounseliaNav');
  var btn = document.getElementById('kounseliaNavToggle');
  var backdrop = document.getElementById('kounseliaNavBackdrop');
  var closeBtn = document.getElementById('kounseliaNavClose');
  if(!nav || !btn) return;
  function setOpen(open){
    document.documentElement.classList.toggle('admin-nav-open', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if(open){ var first = nav.querySelector('a.nav-link'); if(first) first.focus({ preventScroll: true }); }
  }
  btn.addEventListener('click', function(){ setOpen(!document.documentElement.classList.contains('admin-nav-open')); });
  backdrop.addEventListener('click', function(){ setOpen(false); });
  closeBtn.addEventListener('click', function(){ setOpen(false); btn.focus(); });
  document.addEventListener('keydown', function(e){ if(e.key === 'Escape' && document.documentElement.classList.contains('admin-nav-open')){ setOpen(false); btn.focus(); } });
  // Keep the current page's link in view in a long sidebar.
  var current = nav.querySelector('a.nav-link.active');
  if(current && current.scrollIntoView){ current.scrollIntoView({ block: 'nearest' }); }
})();
</script>
