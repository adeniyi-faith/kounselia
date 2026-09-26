<?php
/**
 * Top navigation for public content pages (page/, blog/, newsletter/).
 * Visually the same bar as the homepage's, plus a few content links.
 * The homepage's sign-in modal lives on index.php, so the buttons here
 * link to /?auth=login|register, which opens it.
 */
$kounselia_nav_ctx     = kounselia_public_context();
$kounselia_nav_current = isset( $kounselia_nav_current ) ? $kounselia_nav_current : '';
?>
<nav class="land-nav k-nav">
  <div class="container k-nav-inner">
    <a href="/" class="logo-link" aria-label="Kounselia home">
      <img src="https://kounselia.com/img/Kounselia_Logo_IconMark_MidnightNavy.png" alt="Kounselia" class="site-logo">
    </a>
    <div class="k-nav-links">
      <a href="/?browse=1#counselors-anchor">Talk to someone</a>
      <a href="/professionals/" class="<?php echo 'professionals' === $kounselia_nav_current ? 'active' : ''; ?>">Professionals</a>
      <a href="/blog/" class="k-hide-xs<?php echo 'blog' === $kounselia_nav_current ? ' active' : ''; ?>">Journal</a>
      <a href="<?php echo esc_url( kounselia_page_url( 'our-mission' ) ); ?>" class="k-hide-sm<?php echo 'our-mission' === $kounselia_nav_current ? ' active' : ''; ?>">Our mission</a>
    </div>
    <div class="nav-right">
      <?php if ( $kounselia_nav_ctx['logged_in'] ) : ?>
        <a class="btn-nav-primary k-nav-btn" href="/dashboard.php">Dashboard</a>
      <?php else : ?>
        <a class="btn-ghost k-nav-btn k-hide-xs" href="/?auth=login">Sign in</a>
        <a class="btn-nav-primary k-nav-btn" href="/?auth=register">Start free</a>
      <?php endif; ?>
    </div>
  </div>
</nav>
