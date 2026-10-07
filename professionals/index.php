<?php
/**
 * Licensed professionals, publicly.
 *
 *   /professionals/            directory of verified professionals
 *   /professionals/<name>-<id> one professional's profile
 *   /professionals/join        for professionals: why and how to join
 *
 * Kept separate from the AI counselors on purpose: people must always
 * know whether they're talking to an AI or a licensed human. The two
 * pages link to each other. Logic lives in includes/professionals-public.php.
 */
require dirname( __DIR__ ) . '/inc/kounselia-boot.php';
require dirname( __DIR__ ) . '/inc/kounselia-community.php';

/** Translated, HTML-escaped text for this page (visitor's language). */
function kounselia_pros_e( $key, $vars = array() ) {
    return esc_html( kounselia_t( $key, $vars ) );
}

/** A heading with one emphasised phrase: $key holds {em}, $em_key the phrase. */
function kounselia_pros_em( $key, $em_key, $vars = array() ) {
    $vars['em'] = '@@em@@';
    return str_replace( '@@em@@', '<em>' . kounselia_pros_e( $em_key ) . '</em>', esc_html( kounselia_t( $key, $vars ) ) );
}

$kounselia_segments    = kounselia_request_segments( 'professionals' );
$kounselia_nav_current = 'professionals';
$kounselia_view        = isset( $kounselia_segments[0] ) ? $kounselia_segments[0] : '';
$kounselia_pro_discount = function_exists( 'kounselia_plan_benefits' ) ? min( (float) kounselia_plan_benefits()['pro']['booking_discount'], (float) kounselia_booking_commission_percent() ) : 0;
$kounselia_discount_txt = rtrim( rtrim( number_format( $kounselia_pro_discount, 1 ), '0' ), '.' );

/* -------------------------------------------------------------------------
 * For professionals: join
 * ---------------------------------------------------------------------- */
if ( 'join' === $kounselia_view ) {
    $commission = rtrim( rtrim( number_format( (float) kounselia_booking_commission_percent(), 1 ), '0' ), '.' );
    kounselia_public_head( array(
        'title'       => kounselia_t( 's.pro.join_title' ),
        'description' => kounselia_t( 's.pro.join_desc' ),
        'url'         => kounselia_site_url( '/professionals/join' ),
    ) );
    ?>
<body class="k-site">
<?php require dirname( __DIR__ ) . '/inc/kounselia-site-nav.php'; ?>
<main>
  <header class="k-page-hero k-join-hero">
    <div class="k-eyebrow"><?php echo kounselia_pros_e( 's.pro.join_eyebrow' ); ?></div>
    <h1 class="k-page-title"><?php echo kounselia_pros_em( 's.pro.join_h1', 's.pro.join_h1_em' ); ?></h1>
    <p class="k-page-sub"><?php echo kounselia_pros_e( 's.pro.join_sub' ); ?></p>
    <div class="k-cta-actions" style="margin-top:30px">
      <a class="k-btn" href="/apply.php"><?php echo kounselia_pros_e( 's.pro.apply_to_join' ); ?></a>
      <a class="k-btn outline" href="/professionals/"><?php echo kounselia_pros_e( 's.pro.see_directory' ); ?></a>
    </div>
  </header>

  <section class="k-wrap k-join-grid">
    <?php
    $benefits = array(
        array( 'users', 's.pro.b1_t', 's.pro.b1_d' ),
        array( 'coin', 's.pro.b2_t', 's.pro.b2_d' ),
        array( 'video', 's.pro.b3_t', 's.pro.b3_d' ),
        array( 'calendar-time', 's.pro.b4_t', 's.pro.b4_d' ),
        array( 'star', 's.pro.b5_t', 's.pro.b5_d' ),
        array( 'shield-check', 's.pro.b6_t', 's.pro.b6_d' ),
    );
    foreach ( $benefits as $b ) : ?>
      <div class="k-join-card"><i class="ti ti-<?php echo esc_attr( $b[0] ); ?>"></i><h3><?php echo kounselia_pros_e( $b[1] ); ?></h3><p><?php echo kounselia_pros_e( $b[2] ); ?></p></div>
    <?php endforeach; ?>
  </section>

  <section class="k-wrap k-join-steps">
    <h2 class="k-more-title"><?php echo kounselia_pros_e( 's.pro.steps_h' ); ?></h2>
    <ol>
      <li><b><?php echo kounselia_pros_e( 's.pro.step1_t' ); ?></b><span><?php echo kounselia_pros_e( 's.pro.step1_d' ); ?></span></li>
      <li><b><?php echo kounselia_pros_e( 's.pro.step2_t' ); ?></b><span><?php echo kounselia_pros_e( 's.pro.step2_d' ); ?></span></li>
      <li><b><?php echo kounselia_pros_e( 's.pro.step3_t' ); ?></b><span><?php echo kounselia_pros_e( 's.pro.step3_d' ); ?></span></li>
      <li><b><?php echo kounselia_pros_e( 's.pro.step4_t' ); ?></b><span><?php echo kounselia_pros_e( 's.pro.step4_d' ); ?></span></li>
    </ol>
  </section>

  <section class="k-wrap k-join-faq k-prose">
    <h2><?php echo kounselia_pros_e( 's.pro.faq_h' ); ?></h2>
    <h3><?php echo kounselia_pros_e( 's.pro.faq1_q' ); ?></h3>
    <p><?php echo kounselia_pros_e( 's.pro.faq1_a', array( 'commission' => $commission ) ); ?></p>
    <h3><?php echo kounselia_pros_e( 's.pro.faq2_q' ); ?></h3>
    <p><?php echo kounselia_pros_e( 's.pro.faq2_a' ); ?></p>
    <h3><?php echo kounselia_pros_e( 's.pro.faq3_q' ); ?></h3>
    <p><?php echo kounselia_pros_e( 's.pro.faq3_a' ); ?></p>
  </section>

  <section class="k-cta-band">
    <h2><?php echo kounselia_pros_em( 's.pro.join_cta_h', 's.pro.join_cta_em' ); ?></h2>
    <p><?php echo kounselia_pros_e( 's.pro.join_cta_p' ); ?></p>
    <div class="k-cta-actions"><a class="k-btn" href="/apply.php"><?php echo kounselia_pros_e( 's.pro.apply_to_join' ); ?></a></div>
  </section>
</main>
<?php require dirname( __DIR__ ) . '/inc/kounselia-footer.php'; ?>
</body>
</html>
    <?php
    exit;
}

/* -------------------------------------------------------------------------
 * One professional
 * ---------------------------------------------------------------------- */
if ( '' !== $kounselia_view ) {
    $pro = kounselia_public_professional_by_slug( $kounselia_view );
    if ( ! $pro ) {
        kounselia_public_not_found( 'professional' );
    }
    if ( $kounselia_view !== ltrim( substr( $pro->url, strlen( '/professionals/' ) ), '/' ) ) {
        wp_safe_redirect( $pro->url, 301 ); // Their name changed: send people to the current address.
        exit;
    }
    $reviews  = kounselia_public_professional_reviews( $pro->id );
    $articles = function_exists( 'kounselia_blog_query' ) ? kounselia_blog_query( array( 'professional_id' => $pro->id, 'per_page' => 6 ) ) : array( 'items' => array(), 'total' => 0 );
    $price   = kounselia_public_session_price( $pro );
    // "Dr. Amara Nwosu" -> "Dr. Amara" (a title alone reads oddly: "About Dr.").
    $name_parts = preg_split( '/\s+/', trim( $pro->display_name ) );
    $first      = $name_parts[0];
    if ( isset( $name_parts[1] ) && preg_match( '/^(dr|prof|mr|mrs|ms|miss|rev|pastor)\.?$/i', $first ) ) {
        $first .= ' ' . $name_parts[1];
    }
    $summary = wp_trim_words( wp_strip_all_tags( (string) $pro->bio ), 28 );

    kounselia_public_head( array(
        'title'       => $pro->display_name . ( $pro->title ? ', ' . $pro->title : '' ) . ' — Kounselia',
        'description' => $summary ? $summary : kounselia_t( 's.pro.profile_desc', array( 'name' => $pro->display_name ) ),
        'url'         => kounselia_professional_url( $pro, true ),
        'image'       => $pro->avatar ? $pro->avatar : 'https://kounselia.com/img/Kounselia_Banner_02_16_9.png',
        'type'        => 'profile',
    ) );
    ?>
<body class="k-site">
<?php require dirname( __DIR__ ) . '/inc/kounselia-site-nav.php'; ?>
<main class="k-wrap k-profile">
  <a class="k-back" href="/professionals/"><i class="ti ti-arrow-left"></i> <?php echo kounselia_pros_e( 's.pro.all_pros' ); ?></a>
  <div class="k-profile-grid">
    <article>
      <header class="k-profile-head">
        <span class="k-pro-photo big"><?php echo $pro->avatar ? '<img src="' . esc_url( $pro->avatar ) . '" alt="">' : '<span>' . esc_html( $pro->initial ) . '</span>'; ?></span>
        <div>
          <div class="k-verified-line"><i class="ti ti-discount-check-filled"></i> <?php echo kounselia_pros_e( 's.pro.verified_line' ); ?></div>
          <h1><?php echo esc_html( $pro->display_name ); ?></h1>
          <p class="k-profile-title"><?php echo esc_html( $pro->title ); ?><?php echo $pro->years_experience ? ' · ' . kounselia_pros_e( 1 === (int) $pro->years_experience ? 's.pro.years_exp_one' : 's.pro.years_exp_other', array( 'n' => (int) $pro->years_experience ) ) : ''; ?></p>
          <?php if ( $pro->rating['count'] ) : ?>
            <p class="k-profile-rating"><span class="stars"><?php echo str_repeat( '★', (int) round( $pro->rating['average'] ) ) . str_repeat( '☆', 5 - (int) round( $pro->rating['average'] ) ); ?></span> <?php echo kounselia_pros_e( 1 === (int) $pro->rating['count'] ? 's.pro.rating_one' : 's.pro.rating_other', array( 'avg' => number_format( (float) $pro->rating['average'], 1 ), 'n' => (int) $pro->rating['count'] ) ); ?></p>
          <?php endif; ?>
          <?php $follow_html = kounselia_follow_button_html( $pro->id, array( 'count' => true ) ); ?>
          <?php if ( $follow_html ) : ?><div class="k-profile-follow"><?php echo $follow_html; ?></div><?php endif; ?>
        </div>
      </header>

      <?php if ( $pro->specialties ) : ?>
        <div class="k-pro-chips big"><?php foreach ( $pro->specialties as $s ) { echo '<span>' . esc_html( $s ) . '</span>'; } ?></div>
      <?php endif; ?>

      <section class="k-prose k-profile-bio">
        <h2><?php echo kounselia_pros_e( 's.pro.about', array( 'name' => $first ) ); ?></h2>
        <?php echo $pro->bio ? wpautop( esc_html( $pro->bio ) ) : '<p>' . kounselia_pros_e( 's.pro.no_bio', array( 'name' => $first ) ) . '</p>'; ?>
      </section>

      <?php if ( $articles['items'] ) : ?>
        <section class="k-profile-articles">
          <h2><?php echo kounselia_pros_e( 's.pro.articles_by', array( 'name' => $first ) ); ?></h2>
          <div class="k-cards">
            <?php foreach ( $articles['items'] as $a ) : ?>
              <a class="k-card" href="<?php echo esc_url( kounselia_blog_url( $a->slug ) ); ?>">
                <div class="k-card-img"><?php if ( $a->cover_image ) : ?><img src="<?php echo esc_url( $a->cover_image ); ?>" alt="" loading="lazy"><?php endif; ?></div>
                <h4><?php echo esc_html( $a->title ); ?></h4>
                <p><?php echo esc_html( kounselia_blog_summary( $a, 24 ) ); ?></p>
                <div class="k-meta"><?php echo kounselia_pros_e( 's.blog.min_read', array( 'n' => (int) $a->reading_minutes ) ); ?><?php echo $a->love_count ? ' <span class="dot"></span> <i class="ti ti-heart"></i> ' . esc_html( number_format_i18n( $a->love_count ) ) : ''; ?></div>
              </a>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>

      <?php if ( $reviews ) : ?>
        <section class="k-profile-reviews">
          <h2><?php echo kounselia_pros_e( 's.pro.clients_say' ); ?></h2>
          <?php foreach ( $reviews as $r ) : ?>
            <blockquote>
              <div class="stars"><?php echo str_repeat( '★', $r['rating'] ) . str_repeat( '☆', 5 - $r['rating'] ); ?></div>
              <p><?php echo esc_html( $r['comment'] ); ?></p>
              <cite><?php echo kounselia_pros_e( 's.pro.verified_client' ); ?> · <?php echo esc_html( date_i18n( 'M Y', strtotime( $r['date'] ) ) ); ?></cite>
            </blockquote>
          <?php endforeach; ?>
          <p class="k-note"><i class="ti ti-lock"></i> <?php echo kounselia_pros_e( 's.pro.reviews_note' ); ?></p>
        </section>
      <?php endif; ?>
    </article>

    <aside class="k-book-card">
      <?php
        $free_all   = defined( 'KOUNSELIA_FREE_ALWAYS' ) && (int) $pro->free_sessions_per_client >= KOUNSELIA_FREE_ALWAYS;
        $free_label = function_exists( 'kounselia_free_sessions_label' ) ? kounselia_free_sessions_label( $pro, get_current_user_id() ) : '';
      ?>
      <?php if ( $free_all ) : ?>
        <div class="k-book-price"><?php echo kounselia_pros_e( 's.pro.free' ); ?><small> <?php echo kounselia_pros_e( 's.pro.free_sessions' ); ?></small></div>
      <?php elseif ( $price ) : ?><div class="k-book-price"><?php echo esc_html( $price ); ?><small> <?php echo kounselia_pros_e( 's.pro.per_session' ); ?></small></div><?php endif; ?>
      <?php if ( $free_label && ! $free_all ) : ?><div class="k-book-perk k-book-free"><i class="ti ti-gift"></i> <?php echo esc_html( $free_label ); ?></div><?php endif; ?>
      <?php if ( $kounselia_pro_discount > 0 ) : ?><div class="k-book-perk"><i class="ti ti-sparkles"></i> <?php echo kounselia_pros_e( 's.pro.members_save', array( 'pct' => $kounselia_discount_txt ) ); ?></div><?php endif; ?>
      <ul>
        <?php $video_on = function_exists( 'kounselia_professional_video_provider' ) ? kounselia_professional_video_provider( $pro ) : ''; ?>
        <li><i class="ti ti-video"></i> <?php echo $video_on ? kounselia_pros_e( 's.pro.video_on', array( 'provider' => $video_on ) ) : kounselia_pros_e( 's.pro.private_video' ); ?></li>
        <li><i class="ti ti-calendar-event"></i> <?php echo kounselia_pros_e( 's.pro.pick_time' ); ?></li>
        <li><i class="ti ti-lock"></i> <?php echo kounselia_pros_e( 's.pro.secure_pay' ); ?></li>
        <li><i class="ti ti-message-circle"></i> <?php echo kounselia_pros_e( 's.pro.message_before_after', array( 'name' => $first ) ); ?></li>
      </ul>
      <a class="k-btn" href="<?php echo esc_url( kounselia_professional_book_url( $pro ) ); ?>"><?php echo kounselia_pros_e( $free_label ? 's.pro.book_free' : 's.blog.book_session' ); ?></a>
      <p class="k-note"><?php echo kounselia_pros_e( is_user_logged_in() ? 's.pro.opens_dashboard' : 's.pro.create_to_book' ); ?></p>
    </aside>
  </div>

  <div class="k-ai-note">
    <i class="ti ti-message-heart"></i>
    <div><b><?php echo kounselia_pros_e( 's.pro.ai_note_b' ); ?></b> <?php echo kounselia_pros_e( 's.pro.ai_note_t' ); ?></div>
    <a href="/page/our-counselors"><?php echo kounselia_pros_e( 's.pro.meet_counselors' ); ?></a>
  </div>
</main>
<?php require dirname( __DIR__ ) . '/inc/kounselia-footer.php'; ?>
<?php kounselia_community_assets(); ?>
</body>
</html>
    <?php
    exit;
}

/* -------------------------------------------------------------------------
 * Directory
 * ---------------------------------------------------------------------- */
$pros        = kounselia_public_professionals();
$specialties = array();
foreach ( $pros as $p ) {
    foreach ( $p->specialties as $s ) {
        $specialties[ strtolower( $s ) ] = $s;
    }
}
kounselia_public_head( array(
    'title'       => kounselia_t( 's.pro.dir_title' ),
    'description' => kounselia_t( 's.pro.dir_desc' ),
    'url'         => kounselia_site_url( '/professionals/' ),
) );
?>
<body class="k-site">
<?php require dirname( __DIR__ ) . '/inc/kounselia-site-nav.php'; ?>
<main>
  <header class="k-page-hero">
    <div class="k-eyebrow"><?php echo kounselia_pros_e( 's.home.pros_eyebrow' ); ?></div>
    <h1 class="k-page-title"><?php echo kounselia_pros_em( 's.pro.dir_h1', 's.pro.dir_h1_em' ); ?></h1>
    <p class="k-page-sub"><?php echo kounselia_pros_e( 's.pro.dir_sub' ); ?></p>
  </header>

  <section class="k-wrap">
    <div class="k-trust-row">
      <span><i class="ti ti-discount-check"></i> <?php echo kounselia_pros_e( 's.home.pros_e1_title' ); ?></span>
      <span><i class="ti ti-video"></i> <?php echo kounselia_pros_e( 's.home.pros_e2_title' ); ?></span>
      <span><i class="ti ti-star"></i> <?php echo kounselia_pros_e( 's.pro.reviewed_by_clients' ); ?></span>
      <?php if ( $kounselia_pro_discount > 0 ) : ?><span><i class="ti ti-sparkles"></i> <?php echo kounselia_pros_e( 's.pro.off_with_pro', array( 'pct' => $kounselia_discount_txt ) ); ?></span><?php endif; ?>
    </div>

    <?php if ( $pros ) : ?>
      <div class="k-dir-tools">
        <div class="k-blog-search" style="margin:0;max-width:340px">
          <i class="ti ti-search"></i><input type="search" id="k-dir-q" placeholder="<?php echo esc_attr( kounselia_t( 's.pro.search_ph' ) ); ?>" aria-label="<?php echo esc_attr( kounselia_t( 's.pro.search_aria' ) ); ?>">
        </div>
        <?php if ( count( $specialties ) > 1 ) : ?>
          <div class="k-topics" style="padding:0;margin:0;justify-content:flex-start" id="k-dir-specs">
            <button class="k-pill active" data-spec=""><?php echo kounselia_pros_e( 's.pro.all' ); ?></button>
            <?php foreach ( array_slice( $specialties, 0, 10 ) as $key => $label ) : ?><button class="k-pill" data-spec="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></button><?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <div class="k-pro-grid" id="k-dir">
        <?php foreach ( $pros as $p ) { echo kounselia_professional_card_html( $p ); } ?>
      </div>
      <div class="k-empty" id="k-dir-none" hidden><i class="ti ti-search"></i><h3><?php echo kounselia_pros_e( 's.pro.no_match_h' ); ?></h3><p><?php echo kounselia_pros_e( 's.pro.no_match_p' ); ?></p></div>
    <?php else : ?>
      <div class="k-empty"><i class="ti ti-stethoscope"></i><h3><?php echo kounselia_pros_e( 's.pro.empty_h' ); ?></h3><p><?php echo kounselia_pros_e( 's.pro.empty_p' ); ?></p><p style="margin-top:14px"><a class="k-btn outline" href="/page/our-counselors"><?php echo kounselia_pros_e( 's.pro.meet_counselors' ); ?></a></p></div>
    <?php endif; ?>

    <div class="k-ai-note">
      <i class="ti ti-message-heart"></i>
      <div><b><?php echo kounselia_pros_e( 's.pro.dir_ai_b' ); ?></b> <?php echo kounselia_pros_e( 's.pro.dir_ai_t' ); ?></div>
      <a href="/page/our-counselors"><?php echo kounselia_pros_e( 's.pro.meet_counselors' ); ?></a>
    </div>
  </section>

  <section class="k-cta-band">
    <h2><?php echo kounselia_pros_em( 's.pro.dir_cta_h', 's.pro.dir_cta_em' ); ?></h2>
    <p><?php echo kounselia_pros_e( 's.pro.dir_cta_p' ); ?></p>
    <div class="k-cta-actions"><a class="k-btn" href="/professionals/join"><?php echo kounselia_pros_e( 's.pro.learn_joining' ); ?></a></div>
  </section>
</main>
<?php require dirname( __DIR__ ) . '/inc/kounselia-footer.php'; ?>
<script>
(function(){
  var grid = document.getElementById('k-dir'); if(!grid) return;
  var q = document.getElementById('k-dir-q'), none = document.getElementById('k-dir-none'), spec = '';
  function apply(){
    var term = (q.value || '').trim().toLowerCase(), shown = 0;
    grid.querySelectorAll('.k-pro-card').forEach(function(c){
      var ok = (!term || c.dataset.name.indexOf(term) !== -1) && (!spec || c.dataset.spec.split('|').indexOf(spec) !== -1);
      c.hidden = !ok; if(ok) shown++;
    });
    none.hidden = shown > 0;
  }
  q.addEventListener('input', apply);
  var specs = document.getElementById('k-dir-specs');
  if(specs) specs.addEventListener('click', function(e){
    var b = e.target.closest('[data-spec]'); if(!b) return;
    specs.querySelectorAll('.k-pill').forEach(function(x){ x.classList.toggle('active', x === b); });
    spec = b.dataset.spec; apply();
  });
})();
</script>
</body>
</html>
