<?php
/**
 * Editable content pages: /page/<slug>
 *
 * Every footer link ("Our mission", "Research", ...) points here. The
 * content itself is written in Admin → Pages with the rich editor.
 * Drafts are only visible to admins (with a banner saying so).
 */
require dirname( __DIR__ ) . '/inc/kounselia-boot.php';

$kounselia_segments = kounselia_request_segments( 'page' );
$kounselia_slug     = isset( $kounselia_segments[0] ) ? $kounselia_segments[0] : '';
$kounselia_is_admin = function_exists( 'kounselia_admin_can' ) && kounselia_admin_can( 'pages' );

if ( '' === $kounselia_slug ) {
    wp_safe_redirect( '/', 302 );
    exit;
}

$kounselia_page = kounselia_get_page_by_slug( $kounselia_slug, ! $kounselia_is_admin );
if ( ! $kounselia_page ) {
    $kounselia_moved = kounselia_page_slug_redirect( $kounselia_slug );
    if ( $kounselia_moved ) {
        wp_safe_redirect( kounselia_page_url( $kounselia_moved ), 301 );
        exit;
    }
    kounselia_public_not_found( 'page' );
}

$kounselia_nav_current = $kounselia_page->slug;
$kounselia_description = $kounselia_page->meta_description ? $kounselia_page->meta_description : wp_trim_words( wp_strip_all_tags( $kounselia_page->subtitle ? $kounselia_page->subtitle : $kounselia_page->content ), 28 );

kounselia_public_head( array(
    'title'       => $kounselia_page->title . ' — Kounselia',
    'description' => $kounselia_description,
    'url'         => kounselia_page_url( $kounselia_page->slug, true ),
    'image'       => $kounselia_page->hero_image ? $kounselia_page->hero_image : 'https://kounselia.com/img/Kounselia_Banner_02_16_9.png',
    'noindex'     => 'published' !== $kounselia_page->status,
) );
?>
<body class="k-site">
<?php if ( $kounselia_is_admin ) : ?>
  <div class="k-draft-bar"<?php echo 'published' === $kounselia_page->status ? ' style="background:var(--accent)"' : ''; ?>>
    <?php echo 'published' === $kounselia_page->status ? 'You are signed in as staff.' : 'Draft preview — only staff can see this page.'; ?>
    <a href="/portal/admin/pages/pages.php?edit=<?php echo (int) $kounselia_page->id; ?>">Edit this page</a>
  </div>
<?php endif; ?>

<?php require dirname( __DIR__ ) . '/inc/kounselia-site-nav.php'; ?>

<main>
  <header class="k-page-hero">
    <?php if ( $kounselia_page->eyebrow ) : ?>
      <div class="k-eyebrow"><?php echo esc_html( $kounselia_page->eyebrow ); ?></div>
    <?php endif; ?>
    <h1 class="k-page-title"><?php echo esc_html( $kounselia_page->title ); ?></h1>
    <?php if ( $kounselia_page->subtitle ) : ?>
      <p class="k-page-sub"><?php echo esc_html( $kounselia_page->subtitle ); ?></p>
    <?php endif; ?>
  </header>

  <?php if ( $kounselia_page->hero_image ) : ?>
    <div class="k-page-hero-img"><img src="<?php echo esc_url( $kounselia_page->hero_image ); ?>" alt=""></div>
  <?php endif; ?>

  <article class="k-page-body k-prose">
    <?php echo kounselia_render_content( $kounselia_page->content ); // Cleaned with kounselia_content_kses() on save. ?>
  </article>

  <?php if ( 'safety-resources' !== $kounselia_page->slug ) : ?>
    <section class="k-cta-band">
      <h2>You deserve someone <em>to talk to</em></h2>
      <p>Private, judgement free conversations with a counselor who understands. No appointment. No waiting list.</p>
      <div class="k-cta-actions">
        <a class="k-btn" href="/?browse=1#counselors-anchor">Talk to someone now</a>
        <a class="k-btn ghost" href="/blog/">Read the journal</a>
      </div>
    </section>
  <?php endif; ?>
</main>

<?php require dirname( __DIR__ ) . '/inc/kounselia-footer.php'; ?>
</body>
</html>
