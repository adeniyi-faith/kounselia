<?php
/**
 * The site footer, shared by the homepage and every content page.
 * Columns, links, social profiles and the newsletter box are all
 * edited in Admin → Pages → Footer (stored in the kounselia_footer
 * option; see content.php). Links to unpublished pages are hidden
 * automatically.
 *
 * Also prints the small script that powers every newsletter sign-up
 * form on the page (.k-nl-form).
 */
$kounselia_footer = function_exists( 'kounselia_footer_settings' ) ? kounselia_footer_settings() : array( 'tagline' => '', 'columns' => array(), 'social' => array(), 'show_newsletter' => 0 );
$kounselia_social_icons = array(
    'twitter'   => array( 'ti-brand-x', 'X (Twitter)' ),
    'linkedin'  => array( 'ti-brand-linkedin', 'LinkedIn' ),
    'instagram' => array( 'ti-brand-instagram', 'Instagram' ),
    'facebook'  => array( 'ti-brand-facebook', 'Facebook' ),
    'youtube'   => array( 'ti-brand-youtube', 'YouTube' ),
    'email'     => array( 'ti-mail', 'Email us' ),
);
?>
<footer class="footer">
  <div class="container">
    <?php if ( ! empty( $kounselia_footer['show_newsletter'] ) ) : ?>
      <div class="k-footer-nl">
        <div class="k-footer-nl-copy">
          <div class="k-footer-nl-title"><?php echo esc_html( $kounselia_footer['newsletter_heading'] ); ?></div>
          <p><?php echo esc_html( $kounselia_footer['newsletter_text'] ); ?></p>
        </div>
        <?php echo kounselia_newsletter_form_html( 'footer' ); // Built with escaped values. ?>
      </div>
    <?php endif; ?>

    <div class="footer-top k-footer-top">
      <div class="k-footer-brand">
        <a href="/" class="footer-logo-link">
          <img src="https://kounselia.com/img/Kounselia_Logo_IconMark_ChampagneGold.png" alt="Kounselia" class="footer-site-logo" loading="lazy">
        </a>
        <div class="footer-tagline"><?php echo esc_html( $kounselia_footer['tagline'] ); ?></div>

        <div class="footer-social">
          <?php foreach ( $kounselia_social_icons as $key => $icon ) :
              $value = isset( $kounselia_footer['social'][ $key ] ) ? $kounselia_footer['social'][ $key ] : '';
              if ( ! $value ) {
                  continue;
              }
              $href = 'email' === $key ? 'mailto:' . $value : $value;
              ?>
            <a class="social-btn" href="<?php echo esc_url( $href ); ?>" aria-label="<?php echo esc_attr( $icon[1] ); ?>" <?php echo 'email' === $key ? '' : 'target="_blank" rel="noopener"'; ?>><i class="ti <?php echo esc_attr( $icon[0] ); ?>"></i></a>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="footer-grid k-footer-grid">
        <?php foreach ( (array) $kounselia_footer['columns'] as $col ) :
            $links = array_filter( array_map( 'kounselia_footer_resolve_link', (array) $col['links'] ) );
            if ( ! $links ) {
                continue;
            }
            ?>
          <div>
            <div class="footer-col-title"><?php echo esc_html( $col['title'] ); ?></div>
            <div class="footer-links">
              <?php foreach ( $links as $link ) : ?>
                <a class="footer-link" href="<?php echo esc_url( $link['url'] ); ?>"><?php echo esc_html( $link['label'] ); ?></a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="footer-divider"></div>

    <div class="footer-badges">
      <div class="footer-badge"><i class="ti ti-shield-check"></i> GDPR aligned</div>
      <div class="footer-badge"><i class="ti ti-lock"></i> Encrypted</div>
      <div class="footer-badge"><i class="ti ti-accessible"></i> Accessible</div>
      <div class="footer-badge"><i class="ti ti-certificate"></i> Evidence informed</div>
      <div class="footer-badge"><i class="ti ti-heart-handshake"></i> Crisis safe</div>
    </div>

    <div class="footer-bottom">
      &copy; <?php echo esc_html( date( 'Y' ) ); ?> Kounselia. All rights reserved.<br><br>
      Kounselia is a supportive wellness platform. It is not a substitute for clinical therapy or medical advice. If you are in crisis please contact your local emergency services or a licensed mental health professional.
    </div>
  </div>
</footer>
<script>
(function(){
  var AJAX = <?php echo wp_json_encode( set_url_scheme( admin_url( 'admin-ajax.php' ), is_ssl() ? 'https' : 'http' ) ); ?>;
  document.querySelectorAll('.k-nl-form').forEach(function(form){
    if (form.dataset.bound) return;
    form.dataset.bound = '1';
    form.addEventListener('submit', function(e){
      e.preventDefault();
      var msg = form.querySelector('.k-nl-msg');
      var btn = form.querySelector('button');
      var email = form.querySelector('[name=email]').value.trim();
      if (!/^\S+@\S+\.\S+$/.test(email)) {
        msg.className = 'k-nl-msg error'; msg.textContent = 'Please enter a valid email address.'; return;
      }
      btn.disabled = true; msg.className = 'k-nl-msg'; msg.textContent = 'Subscribing…';
      var body = new URLSearchParams({
        action: 'kounselia_newsletter_subscribe',
        email: email,
        name: (form.querySelector('[name=name]') || {}).value || '',
        website: (form.querySelector('[name=website]') || {}).value || '',
        source: form.dataset.source || 'website'
      });
      fetch(AJAX, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
        .then(function(r){ return r.json(); })
        .then(function(res){
          btn.disabled = false;
          var text = (res && res.data && res.data.message) || (res && res.success ? 'Subscribed.' : 'Something went wrong. Please try again.');
          msg.className = 'k-nl-msg ' + (res && res.success ? 'ok' : 'error');
          msg.textContent = text;
          if (res && res.success) { form.classList.add('done'); form.querySelector('[name=email]').value = ''; }
        })
        .catch(function(){ btn.disabled = false; msg.className = 'k-nl-msg error'; msg.textContent = 'Network error. Please try again.'; });
    });
  });
})();
</script>
