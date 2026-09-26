<?php
/**
 * Boots WordPress for a public, theme-less Kounselia page (page/,
 * blog/, newsletter/). Same approach and reasoning as the top of
 * index.php: load WP core only, never the theme loader, and scope auth
 * cookies to the whole site so a signed-in member is recognised here.
 */
if ( ! defined( 'WP_USE_THEMES' ) ) {
    define( 'WP_USE_THEMES', false );
}
if ( ! defined( 'COOKIEPATH' ) ) {
    define( 'COOKIEPATH', '/' );
}
if ( ! defined( 'SITECOOKIEPATH' ) ) {
    define( 'SITECOOKIEPATH', '/' );
}

$kounselia_wp_load = dirname( __DIR__ ) . '/portal/wp-load.php';
if ( ! file_exists( $kounselia_wp_load ) ) {
    http_response_code( 500 );
    die( 'Configuration error: cannot locate the WordPress core engine at ' . htmlspecialchars( $kounselia_wp_load ) );
}
require_once $kounselia_wp_load;

/**
 * Path segments after a base folder, e.g. for /blog/tag/sleep and base
 * "blog": array( 'tag', 'sleep' ). Works with or without the pretty-URL
 * rewrite (/blog/index.php/tag/sleep).
 */
function kounselia_request_segments( $base ) {
    $path = (string) parse_url( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH );
    $path = preg_replace( '#^.*?/' . preg_quote( $base, '#' ) . '(/|$)#', '', $path, 1 );
    $path = preg_replace( '#^index\.php/?#', '', $path );
    $segs = array();
    foreach ( explode( '/', $path ) as $seg ) {
        $seg = sanitize_title( rawurldecode( $seg ) );
        if ( '' !== $seg ) {
            $segs[] = $seg;
        }
    }
    return $segs;
}

/**
 * Everything a public template needs about the visitor + AJAX endpoint.
 */
function kounselia_public_context() {
    $user = wp_get_current_user();
    return array(
        'logged_in' => is_user_logged_in(),
        'name'      => $user->ID ? $user->display_name : '',
        'avatar'    => ( $user->ID && function_exists( 'kounselia_get_avatar_url' ) ) ? kounselia_get_avatar_url( $user->ID, 'thumbnail' ) : false,
        'ajax_url'  => set_url_scheme( admin_url( 'admin-ajax.php' ), is_ssl() ? 'https' : 'http' ),
        'is_admin'  => function_exists( 'kounselia_user_is_admin' ) && kounselia_user_is_admin(),
    );
}

/**
 * Shared <head> for public content pages. $meta: title, description,
 * url (absolute canonical), image, type ('website'|'article'), extra
 * (raw HTML appended, e.g. JSON-LD), noindex (bool).
 */
function kounselia_public_head( $meta ) {
    $meta = wp_parse_args( $meta, array(
        'title'       => 'Kounselia',
        'description' => 'Kounselia gives you a private space to talk through what you\'re carrying.',
        'url'         => kounselia_site_url( '/' ),
        'image'       => 'https://kounselia.com/img/Kounselia_Banner_02_16_9.png',
        'type'        => 'website',
        'extra'       => '',
        'noindex'     => false,
    ) );
    $root = dirname( __DIR__ );
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo esc_html( $meta['title'] ); ?></title>
<meta name="description" content="<?php echo esc_attr( $meta['description'] ); ?>">
<link rel="canonical" href="<?php echo esc_url( $meta['url'] ); ?>">
<?php if ( $meta['noindex'] ) : ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
<link rel="icon" type="image/png" href="https://kounselia.com/img/fv.png">
<link rel="apple-touch-icon" href="https://kounselia.com/img/fv.png">
<meta property="og:type" content="<?php echo esc_attr( $meta['type'] ); ?>">
<meta property="og:url" content="<?php echo esc_url( $meta['url'] ); ?>">
<meta property="og:title" content="<?php echo esc_attr( $meta['title'] ); ?>">
<meta property="og:description" content="<?php echo esc_attr( $meta['description'] ); ?>">
<meta property="og:image" content="<?php echo esc_url( $meta['image'] ); ?>">
<meta property="og:site_name" content="Kounselia">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?php echo esc_attr( $meta['title'] ); ?>">
<meta name="twitter:description" content="<?php echo esc_attr( $meta['description'] ); ?>">
<meta name="twitter:image" content="<?php echo esc_url( $meta['image'] ); ?>">
<link rel="alternate" type="application/rss+xml" title="Kounselia Journal" href="<?php echo esc_url( kounselia_site_url( '/blog/feed' ) ); ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600&family=Source+Serif+4:ital,opsz,wght@0,8..60,400;0,8..60,600;1,8..60,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
<?php require $root . '/inc/kounselia-styles.php'; ?>
<?php require $root . '/inc/kounselia-content-styles.php'; ?>
<?php echo $meta['extra']; // Trusted, built by our own templates. ?>
</head>
    <?php
}

/**
 * A friendly "not found" page for /page/... and /blog/... misses.
 */
function kounselia_public_not_found( $what = 'page' ) {
    status_header( 404 );
    nocache_headers();
    kounselia_public_head( array( 'title' => 'Not found — Kounselia', 'noindex' => true ) );
    echo '<body class="k-site">';
    require dirname( __DIR__ ) . '/inc/kounselia-site-nav.php';
    echo '<main class="k-notfound"><div class="k-eyebrow">404</div><h1>We couldn\'t find that ' . esc_html( $what ) . '</h1>'
        . '<p>It may have moved, or the link might be mistyped. Here are some places to start instead.</p>'
        . '<div class="k-notfound-links"><a class="k-btn" href="/">Go to the homepage</a><a class="k-btn outline" href="/blog/">Read the journal</a></div></main>';
    require dirname( __DIR__ ) . '/inc/kounselia-footer.php';
    echo '</body></html>';
    exit;
}
