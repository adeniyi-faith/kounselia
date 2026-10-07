<?php
/**
 * Newsletter links that appear inside emails:
 *
 *   ?a=confirm&t=<token>       double opt-in confirmation
 *   ?a=unsubscribe&t=<token>   unsubscribe (GET shows a confirm button,
 *                              so link scanners in mail apps can't
 *                              unsubscribe people by just visiting it;
 *                              POST — including the one-click
 *                              List-Unsubscribe-Post from Gmail/Apple
 *                              Mail — unsubscribes immediately)
 *   ?a=preferences&t=<token>   choose which emails to receive
 *   ?a=open&r=<token>          open-tracking pixel
 *   ?a=click&r=..&u=..&s=..    click tracking, then redirect
 *
 * <token> is the contact's private token (only ever sent to their own
 * inbox), which is what authorises changes here without a login.
 */
require dirname( __DIR__ ) . '/inc/kounselia-boot.php';

$action = isset( $_GET['a'] ) ? sanitize_key( $_GET['a'] ) : '';

if ( 'open' === $action ) {
    kounselia_newsletter_track_open( isset( $_GET['r'] ) ? sanitize_text_field( wp_unslash( $_GET['r'] ) ) : '' );
    nocache_headers();
    header( 'Content-Type: image/gif' );
    echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' );
    exit;
}

if ( 'click' === $action ) {
    $dest = kounselia_newsletter_track_click(
        isset( $_GET['r'] ) ? sanitize_text_field( wp_unslash( $_GET['r'] ) ) : '',
        isset( $_GET['u'] ) ? esc_url_raw( wp_unslash( $_GET['u'] ) ) : '',
        isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''
    );
    // Signed destination, so an external redirect is intended here.
    wp_redirect( $dest, 302 );
    exit;
}

$token = isset( $_REQUEST['t'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['t'] ) ) : '';
$sub   = kounselia_newsletter_get_by_token( $token );
$is_post = 'POST' === $_SERVER['REQUEST_METHOD'];

// RFC 8058 one-click unsubscribe from the mail app itself: no page, just do it.
if ( $sub && 'unsubscribe' === $action && $is_post && isset( $_POST['List-Unsubscribe'] ) ) {
    kounselia_newsletter_set_preferences( $sub, array() );
    status_header( 200 );
    echo 'Unsubscribed';
    exit;
}

$state   = 'form';
$message = '';

if ( ! $sub ) {
    $state = 'invalid';
} elseif ( 'confirm' === $action ) {
    if ( 'subscribed' !== $sub->status ) {
        $sub = kounselia_newsletter_upsert( $sub->email, array( 'status' => 'subscribed' ) );
        kounselia_newsletter_send_welcome( $sub );
    }
    $state = 'confirmed';
} elseif ( 'unsubscribe' === $action && $is_post ) {
    $sub   = kounselia_newsletter_set_preferences( $sub, array() );
    $state = 'unsubscribed';
} elseif ( 'preferences' === $action && $is_post ) {
    $lists = isset( $_POST['lists'] ) ? array_map( 'sanitize_key', (array) $_POST['lists'] ) : array();
    $sub   = kounselia_newsletter_set_preferences( $sub, $lists );
    $state = $lists ? 'saved' : 'unsubscribed';
}

$self = '/newsletter/?t=' . rawurlencode( $token );
$nl = function ( $key, $vars = array() ) {
    return esc_html( kounselia_t( $key, $vars ) );
};
kounselia_public_head( array( 'title' => kounselia_t( 's.nl.title' ), 'noindex' => true ) );
?>
<body class="k-site">
<?php require dirname( __DIR__ ) . '/inc/kounselia-site-nav.php'; ?>
<main class="k-card-center">

<?php if ( 'invalid' === $state ) : ?>
  <div class="k-status warn"><i class="ti ti-link-off"></i></div>
  <h1><?php echo $nl( 's.nl.expired_h1' ); ?></h1>
  <p><?php echo $nl( 's.nl.expired_p' ); ?></p>
  <a class="k-btn outline" href="/"><?php echo $nl( 's.notfound.home' ); ?></a>

<?php elseif ( 'confirmed' === $state ) : ?>
  <div class="k-status"><i class="ti ti-circle-check"></i></div>
  <h1><?php echo $nl( 's.nl.subscribed_h1' ); ?></h1>
  <p><?php echo $nl( 's.nl.subscribed_p', array( 'name' => kounselia_newsletter_first_name( $sub ) ) ); ?></p>
  <a class="k-btn" href="/blog/"><?php echo $nl( 's.page.read_journal' ); ?></a>

<?php elseif ( 'unsubscribed' === $state ) : ?>
  <div class="k-status"><i class="ti ti-mail-off"></i></div>
  <h1><?php echo $nl( 's.nl.unsubscribed_h1' ); ?></h1>
  <p><?php echo $nl( 's.nl.unsubscribed_p', array( 'email' => $sub->email ) ); ?></p>
  <form method="post" action="<?php echo esc_url( $self . '&a=preferences' ); ?>">
    <input type="hidden" name="lists[]" value="newsletter">
    <input type="hidden" name="lists[]" value="blog">
    <button class="k-btn outline" type="submit"><?php echo $nl( 's.nl.resubscribe' ); ?></button>
  </form>

<?php elseif ( 'unsubscribe' === $action ) : ?>
  <div class="k-status warn"><i class="ti ti-mail-off"></i></div>
  <h1><?php echo $nl( 's.nl.unsub_h1' ); ?></h1>
  <p><?php echo $nl( 's.nl.unsub_p' ); ?></p>
  <form method="post" action="<?php echo esc_url( $self . '&a=unsubscribe' ); ?>">
    <button class="k-btn" type="submit"><?php echo $nl( 's.nl.unsub_btn', array( 'email' => $sub->email ) ); ?></button>
  </form>
  <p style="margin-top:18px"><a href="<?php echo esc_url( $self . '&a=preferences' ); ?>"><?php echo $nl( 's.nl.keep_instead' ); ?></a></p>

<?php else : ?>
  <div class="k-eyebrow"><?php echo $nl( 's.nl.prefs_eyebrow' ); ?></div>
  <h1><?php echo $nl( 's.nl.prefs_h1' ); ?></h1>
  <?php if ( 'saved' === $state ) : ?><p style="color:var(--sage)"><i class="ti ti-check"></i> <?php echo $nl( 's.nl.prefs_saved' ); ?></p><?php else : ?><p><?php echo $nl( 's.nl.prefs_choose', array( 'email' => $sub->email ) ); ?></p><?php endif; ?>
  <form method="post" action="<?php echo esc_url( $self . '&a=preferences' ); ?>">
    <div class="k-panel">
      <?php $on = 'subscribed' === $sub->status; ?>
      <label class="k-check"><input type="checkbox" name="lists[]" value="newsletter" <?php checked( $on && $sub->list_newsletter ); ?>><div><b><?php echo $nl( 's.nl.list_newsletter' ); ?></b><span><?php echo $nl( 's.nl.list_newsletter_d' ); ?></span></div></label>
      <label class="k-check"><input type="checkbox" name="lists[]" value="blog" <?php checked( $on && $sub->list_blog ); ?>><div><b><?php echo $nl( 's.nl.list_blog' ); ?></b><span><?php echo $nl( 's.nl.list_blog_d' ); ?></span></div></label>
    </div>
    <button class="k-btn" type="submit"><?php echo $nl( 's.nl.save_prefs' ); ?></button>
  </form>
  <form method="post" action="<?php echo esc_url( $self . '&a=unsubscribe' ); ?>"><button class="k-link-btn" type="submit"><?php echo $nl( 's.nl.unsub_all' ); ?></button></form>
<?php endif; ?>

</main>
<?php require dirname( __DIR__ ) . '/inc/kounselia-footer.php'; ?>
</body>
</html>
