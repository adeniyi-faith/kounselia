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
        'title'       => 'For licensed professionals — Kounselia',
        'description' => 'Join Kounselia as a licensed mental health professional. Set your own rate, meet clients by video, and get paid securely.',
        'url'         => kounselia_site_url( '/professionals/join' ),
    ) );
    ?>
<body class="k-site">
<?php require dirname( __DIR__ ) . '/inc/kounselia-site-nav.php'; ?>
<main>
  <header class="k-page-hero k-join-hero">
    <div class="k-eyebrow">For licensed professionals</div>
    <h1 class="k-page-title">Your practice, <em>closer to the people who need it</em></h1>
    <p class="k-page-sub">Kounselia helps people take the first step with our AI counselors — and when they're ready for a licensed human, they find you.</p>
    <div class="k-cta-actions" style="margin-top:30px">
      <a class="k-btn" href="/apply.php">Apply to join</a>
      <a class="k-btn outline" href="/professionals/">See the directory</a>
    </div>
  </header>

  <section class="k-wrap k-join-grid">
    <?php
    $benefits = array(
        array( 'users', 'Clients who are ready', 'Members who have already started talking about what they carry come to you when they want a professional — no cold marketing.' ),
        array( 'coin', 'You set your rate', 'Charge what your time is worth. Clients abroad pay in dollars; you are always paid in naira, straight to your bank.' ),
        array( 'video', 'Sessions built in', 'Private video sessions, a message thread per booking, reminders for both of you, and weekly recurring bookings.' ),
        array( 'calendar-time', 'Your hours, your way', 'Open the times that suit you. Clients book and pay upfront, so no chasing invoices.' ),
        array( 'star', 'A reputation that grows', 'Clients rate sessions. Good reviews move you up the directory — anonymously, so your clients stay private.' ),
        array( 'shield-check', 'Safety you can rely on', 'Crisis language in messages is flagged to our safety team, so no one falls through the cracks.' ),
    );
    foreach ( $benefits as $b ) : ?>
      <div class="k-join-card"><i class="ti ti-<?php echo esc_attr( $b[0] ); ?>"></i><h3><?php echo esc_html( $b[1] ); ?></h3><p><?php echo esc_html( $b[2] ); ?></p></div>
    <?php endforeach; ?>
  </section>

  <section class="k-wrap k-join-steps">
    <h2 class="k-more-title">How joining works</h2>
    <ol>
      <li><b>Apply</b><span>Tell us about your training and upload your licence or registration. It takes about ten minutes.</span></li>
      <li><b>We verify</b><span>Our team checks your credentials with the issuing body. We'll email you with a decision.</span></li>
      <li><b>Set up your profile</b><span>Add your bio, specialties and rate, open your availability, and add your payout account.</span></li>
      <li><b>Get booked</b><span>Members find you in the app and on the public directory, book a time, and pay upfront.</span></li>
    </ol>
  </section>

  <section class="k-wrap k-join-faq k-prose">
    <h2>Questions</h2>
    <h3>What does it cost?</h3>
    <p>Nothing to join. Kounselia keeps <?php echo esc_html( $commission ); ?>% of each session fee to run the platform; the rest is yours, paid in naira.</p>
    <h3>Who can join?</h3>
    <p>Licensed or registered mental health professionals — psychologists, counsellors, psychotherapists, psychiatrists and clinical social workers — with credentials we can verify.</p>
    <h3>Can I stay off the public website?</h3>
    <p>Yes. You can hide your public profile at any time; signed-in members can still find and book you in the app.</p>
  </section>

  <section class="k-cta-band">
    <h2>Ready to <em>join us</em>?</h2>
    <p>Help us make support accessible to anyone, anywhere.</p>
    <div class="k-cta-actions"><a class="k-btn" href="/apply.php">Apply to join</a></div>
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
    $reviews = kounselia_public_professional_reviews( $pro->id );
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
        'description' => $summary ? $summary : $pro->display_name . ' is a licensed professional on Kounselia. Book a private video session.',
        'url'         => kounselia_professional_url( $pro, true ),
        'image'       => $pro->avatar ? $pro->avatar : 'https://kounselia.com/img/Kounselia_Banner_02_16_9.png',
        'type'        => 'profile',
    ) );
    ?>
<body class="k-site">
<?php require dirname( __DIR__ ) . '/inc/kounselia-site-nav.php'; ?>
<main class="k-wrap k-profile">
  <a class="k-back" href="/professionals/"><i class="ti ti-arrow-left"></i> All professionals</a>
  <div class="k-profile-grid">
    <article>
      <header class="k-profile-head">
        <span class="k-pro-photo big"><?php echo $pro->avatar ? '<img src="' . esc_url( $pro->avatar ) . '" alt="">' : '<span>' . esc_html( $pro->initial ) . '</span>'; ?></span>
        <div>
          <div class="k-verified-line"><i class="ti ti-rosette-discount-check-filled"></i> Licence verified by Kounselia</div>
          <h1><?php echo esc_html( $pro->display_name ); ?></h1>
          <p class="k-profile-title"><?php echo esc_html( $pro->title ); ?><?php echo $pro->years_experience ? ' · ' . (int) $pro->years_experience . ' years of experience' : ''; ?></p>
          <?php if ( $pro->rating['count'] ) : ?>
            <p class="k-profile-rating"><span class="stars"><?php echo str_repeat( '★', (int) round( $pro->rating['average'] ) ) . str_repeat( '☆', 5 - (int) round( $pro->rating['average'] ) ); ?></span> <?php echo esc_html( number_format( (float) $pro->rating['average'], 1 ) ); ?> from <?php echo (int) $pro->rating['count']; ?> session review<?php echo 1 === $pro->rating['count'] ? '' : 's'; ?></p>
          <?php endif; ?>
        </div>
      </header>

      <?php if ( $pro->specialties ) : ?>
        <div class="k-pro-chips big"><?php foreach ( $pro->specialties as $s ) { echo '<span>' . esc_html( $s ) . '</span>'; } ?></div>
      <?php endif; ?>

      <section class="k-prose k-profile-bio">
        <h2>About <?php echo esc_html( $first ); ?></h2>
        <?php echo $pro->bio ? wpautop( esc_html( $pro->bio ) ) : '<p>' . esc_html( $first ) . ' hasn\'t written a bio yet.</p>'; ?>
      </section>

      <?php if ( $reviews ) : ?>
        <section class="k-profile-reviews">
          <h2>What clients say</h2>
          <?php foreach ( $reviews as $r ) : ?>
            <blockquote>
              <div class="stars"><?php echo str_repeat( '★', $r['rating'] ) . str_repeat( '☆', 5 - $r['rating'] ); ?></div>
              <p><?php echo esc_html( $r['comment'] ); ?></p>
              <cite>Verified client · <?php echo esc_html( date_i18n( 'M Y', strtotime( $r['date'] ) ) ); ?></cite>
            </blockquote>
          <?php endforeach; ?>
          <p class="k-note"><i class="ti ti-lock"></i> Reviews come from real, completed sessions. We never show who wrote them.</p>
        </section>
      <?php endif; ?>
    </article>

    <aside class="k-book-card">
      <?php if ( $price ) : ?><div class="k-book-price"><?php echo esc_html( $price ); ?><small> / session</small></div><?php endif; ?>
      <?php if ( $kounselia_pro_discount > 0 ) : ?><div class="k-book-perk"><i class="ti ti-sparkles"></i> Pro members save <?php echo esc_html( $kounselia_discount_txt ); ?>%</div><?php endif; ?>
      <ul>
        <li><i class="ti ti-video"></i> Private video session</li>
        <li><i class="ti ti-calendar-event"></i> Pick a time that suits you</li>
        <li><i class="ti ti-lock"></i> Secure payment by Paystack</li>
        <li><i class="ti ti-message-circle"></i> Message <?php echo esc_html( $first ); ?> before and after</li>
      </ul>
      <a class="k-btn" href="<?php echo esc_url( kounselia_professional_book_url( $pro ) ); ?>">Book a session</a>
      <p class="k-note"><?php echo is_user_logged_in() ? 'Opens your dashboard to choose a time.' : 'Create a free account to book — it takes a minute.'; ?></p>
    </aside>
  </div>

  <div class="k-ai-note">
    <i class="ti ti-message-heart"></i>
    <div><b>Need to talk right now?</b> Our AI counselors are available 24/7, free. They're not a replacement for a licensed professional — and they'll always tell you they're AI.</div>
    <a href="/page/our-counselors">Meet the counselors</a>
  </div>
</main>
<?php require dirname( __DIR__ ) . '/inc/kounselia-footer.php'; ?>
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
    'title'       => 'Licensed professionals — Kounselia',
    'description' => 'Book a private video session with a licensed, verified mental health professional on Kounselia.',
    'url'         => kounselia_site_url( '/professionals/' ),
) );
?>
<body class="k-site">
<?php require dirname( __DIR__ ) . '/inc/kounselia-site-nav.php'; ?>
<main>
  <header class="k-page-hero">
    <div class="k-eyebrow">Licensed professionals</div>
    <h1 class="k-page-title">Sometimes you want <em>a person in the room</em></h1>
    <p class="k-page-sub">Book a private video session with a licensed psychologist, counsellor or therapist. Every professional here has had their credentials checked by our team.</p>
  </header>

  <section class="k-wrap">
    <div class="k-trust-row">
      <span><i class="ti ti-rosette-discount-check"></i> Licences verified</span>
      <span><i class="ti ti-video"></i> Private video sessions</span>
      <span><i class="ti ti-star"></i> Reviewed by real clients</span>
      <?php if ( $kounselia_pro_discount > 0 ) : ?><span><i class="ti ti-sparkles"></i> <?php echo esc_html( $kounselia_discount_txt ); ?>% off with Pro</span><?php endif; ?>
    </div>

    <?php if ( $pros ) : ?>
      <div class="k-dir-tools">
        <div class="k-blog-search" style="margin:0;max-width:340px">
          <i class="ti ti-search"></i><input type="search" id="k-dir-q" placeholder="Search by name or focus" aria-label="Search professionals">
        </div>
        <?php if ( count( $specialties ) > 1 ) : ?>
          <div class="k-topics" style="padding:0;margin:0;justify-content:flex-start" id="k-dir-specs">
            <button class="k-pill active" data-spec="">All</button>
            <?php foreach ( array_slice( $specialties, 0, 10 ) as $key => $label ) : ?><button class="k-pill" data-spec="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></button><?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <div class="k-pro-grid" id="k-dir">
        <?php foreach ( $pros as $p ) { echo kounselia_professional_card_html( $p ); } ?>
      </div>
      <div class="k-empty" id="k-dir-none" hidden><i class="ti ti-search"></i><h3>No one matches that</h3><p>Try a different word, or clear the filter.</p></div>
    <?php else : ?>
      <div class="k-empty"><i class="ti ti-stethoscope"></i><h3>Our first professionals are joining now</h3><p>We're verifying licensed professionals right now. In the meantime, our AI counselors are here for you 24/7.</p><p style="margin-top:14px"><a class="k-btn outline" href="/page/our-counselors">Meet the counselors</a></p></div>
    <?php endif; ?>

    <div class="k-ai-note">
      <i class="ti ti-message-heart"></i>
      <div><b>Not ready to book?</b> Start with one of our AI counselors — free, private and available any time. Many people talk to a counselor first and book a professional when they're ready.</div>
      <a href="/page/our-counselors">Meet the counselors</a>
    </div>
  </section>

  <section class="k-cta-band">
    <h2>Are you a <em>licensed professional</em>?</h2>
    <p>Meet clients who are ready for support, set your own rate, and get paid securely in naira.</p>
    <div class="k-cta-actions"><a class="k-btn" href="/professionals/join">Learn about joining</a></div>
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
