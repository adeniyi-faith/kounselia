<?php
/**
 * Kounselia Core — the public face of our licensed professionals.
 *
 * Powers /professionals/ (directory), /professionals/<name>-<id>
 * (profile) and /professionals/join (why join, for professionals), plus
 * the homepage section. Only VERIFIED professionals appear, and each can
 * hide themselves from the public site (Pro dashboard → Profile) while
 * staying bookable by signed-in members.
 *
 * Privacy: licence numbers are never shown, and reviews are shown as
 * "Verified client" — never the client's name. Being in therapy is
 * nobody else's business.
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function kounselia_professional_is_public( $user_id ) {
    return '0' !== (string) get_user_meta( $user_id, 'kounselia_public_profile', true );
}

/**
 * Verified professionals who are happy to be shown publicly, newest
 * reviews/ratings attached. Each row gets: rating (array), avatar (url|false),
 * url (profile path), specialties (array).
 */
function kounselia_public_professionals( $limit = 0 ) {
    if ( ! function_exists( 'kounselia_get_verified_professionals' ) ) {
        return array();
    }
    $out = array();
    foreach ( kounselia_get_verified_professionals() as $pro ) {
        if ( ! kounselia_professional_is_public( $pro->user_id ) ) {
            continue;
        }
        $out[] = kounselia_public_professional_decorate( $pro );
    }
    // Best-reviewed first, then most experienced — a sensible default order.
    usort( $out, function ( $a, $b ) {
        $by_rating = ( $b->rating['count'] > 0 ? $b->rating['average'] : 0 ) <=> ( $a->rating['count'] > 0 ? $a->rating['average'] : 0 );
        return $by_rating ? $by_rating : ( (int) $b->years_experience <=> (int) $a->years_experience );
    } );
    return $limit ? array_slice( $out, 0, $limit ) : $out;
}

function kounselia_public_professional_decorate( $pro ) {
    $pro->rating      = function_exists( 'kounselia_get_professional_rating_summary' ) ? kounselia_get_professional_rating_summary( $pro->id ) : array( 'count' => 0, 'average' => 0 );
    $pro->avatar      = function_exists( 'kounselia_get_avatar_url' ) ? kounselia_get_avatar_url( $pro->user_id, 'medium' ) : false;
    $pro->url         = kounselia_professional_url( $pro );
    $pro->specialties = kounselia_professional_specialties( $pro->specialty );
    $pro->initial     = mb_strtoupper( mb_substr( $pro->display_name, 0, 1 ) );
    return $pro;
}

/** "Anxiety, trauma & grief" -> array( 'Anxiety', 'Trauma', 'Grief' ). */
function kounselia_professional_specialties( $raw ) {
    $parts = preg_split( '/\s*(?:,|&|\/|;|\band\b)\s*/i', (string) $raw );
    $out   = array();
    foreach ( $parts as $p ) {
        $p = trim( $p );
        if ( '' !== $p ) {
            $out[ strtolower( $p ) ] = ucfirst( $p );
        }
    }
    return array_values( array_slice( $out, 0, 6 ) );
}

/** A friendly address like /professionals/ada-obi-12 (the number is what's looked up). */
function kounselia_professional_url( $pro, $absolute = false ) {
    $slug = sanitize_title( $pro->display_name );
    $path = '/professionals/' . ( $slug ? $slug . '-' : '' ) . (int) $pro->id;
    return $absolute && function_exists( 'kounselia_site_url' ) ? kounselia_site_url( $path ) : $path;
}

/** A public professional by the id at the end of their address, or null. */
function kounselia_public_professional_by_slug( $slug ) {
    if ( ! preg_match( '/(\d+)$/', (string) $slug, $m ) || ! function_exists( 'kounselia_get_professional_by_id' ) ) {
        return null;
    }
    $pro = kounselia_get_professional_by_id( (int) $m[1] );
    if ( ! $pro || 'verified' !== $pro->status || ! kounselia_professional_is_public( $pro->user_id ) ) {
        return null;
    }
    return kounselia_public_professional_decorate( $pro );
}

/** Reviews for a public profile, anonymised. */
function kounselia_public_professional_reviews( $professional_id, $limit = 6 ) {
    if ( ! function_exists( 'kounselia_get_professional_reviews' ) ) {
        return array();
    }
    $out = array();
    foreach ( kounselia_get_professional_reviews( $professional_id, 30 ) as $r ) {
        if ( '' === trim( (string) $r->comment ) ) {
            continue; // Star-only ratings count in the average but have nothing to show.
        }
        $out[] = array( 'rating' => (int) $r->rating, 'comment' => $r->comment, 'date' => $r->created_at );
        if ( count( $out ) >= $limit ) {
            break;
        }
    }
    return $out;
}

/**
 * A session price as the current visitor would pay it (their currency),
 * before any Pro discount.
 */
function kounselia_public_session_price( $pro ) {
    if ( ! $pro->rate_amount ) {
        return '';
    }
    if ( function_exists( 'kounselia_viewer_currency' ) ) {
        $currency = kounselia_viewer_currency();
        return kounselia_format_money( kounselia_convert_ngn( $pro->rate_amount, $currency ), $currency );
    }
    return '₦' . number_format_i18n( (float) $pro->rate_amount );
}

/** Where "Book a session" goes: straight to that professional in the dashboard, or sign-up first. */
function kounselia_professional_book_url( $pro ) {
    return is_user_logged_in() ? '/dashboard.php?tab=professionals&book=' . (int) $pro->id : '/?auth=register';
}

/* -------------------------------------------------------------------------
 * A professional chooses whether to be listed publicly
 * ---------------------------------------------------------------------- */

function kounselia_ajax_set_public_profile() {
    kounselia_verify_nonce();
    $user_id = get_current_user_id();
    if ( ! $user_id || ! function_exists( 'kounselia_get_professional_application' ) || ! kounselia_get_professional_application( $user_id ) ) {
        wp_send_json_error( array( 'message' => 'Only professionals can change this.' ), 403 );
    }
    $show = isset( $_POST['show'] ) && '1' === $_POST['show'];
    update_user_meta( $user_id, 'kounselia_public_profile', $show ? '1' : '0' );
    wp_send_json_success( array( 'message' => $show ? 'Your profile is visible on the public website.' : 'Your profile is hidden from the public website. Signed-in members can still book you.' ) );
}
add_action( 'wp_ajax_kounselia_set_public_profile', 'kounselia_ajax_set_public_profile' );

/* -------------------------------------------------------------------------
 * Shared card markup (directory + homepage)
 * ---------------------------------------------------------------------- */

function kounselia_professional_card_html( $pro ) {
    $rating = $pro->rating['count'] > 0
        ? '<span class="k-pro-rating"><i class="ti ti-star-filled"></i> ' . esc_html( number_format( (float) $pro->rating['average'], 1 ) ) . ' <small>(' . (int) $pro->rating['count'] . ')</small></span>'
        : '<span class="k-pro-rating new">New to Kounselia</span>';
    $chips = '';
    foreach ( array_slice( $pro->specialties, 0, 3 ) as $s ) {
        $chips .= '<span>' . esc_html( $s ) . '</span>';
    }
    $price = kounselia_public_session_price( $pro );
    return '<a class="k-pro-card" href="' . esc_url( $pro->url ) . '" data-spec="' . esc_attr( strtolower( implode( '|', $pro->specialties ) ) ) . '" data-name="' . esc_attr( strtolower( $pro->display_name . ' ' . $pro->title . ' ' . $pro->specialty ) ) . '">'
        . '<span class="k-pro-photo">' . ( $pro->avatar ? '<img src="' . esc_url( $pro->avatar ) . '" alt="" loading="lazy">' : '<span>' . esc_html( $pro->initial ) . '</span>' ) . '<b class="k-pro-verified" title="Licence verified by Kounselia"><i class="ti ti-rosette-discount-check-filled"></i></b></span>'
        . '<span class="k-pro-body">'
        . '<span class="k-pro-name">' . esc_html( $pro->display_name ) . '</span>'
        . '<span class="k-pro-title">' . esc_html( $pro->title ) . ( $pro->years_experience ? ' · ' . (int) $pro->years_experience . ' yrs' : '' ) . '</span>'
        . ( $chips ? '<span class="k-pro-chips">' . $chips . '</span>' : '' )
        . '<span class="k-pro-foot">' . $rating . ( $price ? '<span class="k-pro-price">' . esc_html( $price ) . '<small> / session</small></span>' : '' ) . '</span>'
        . '</span></a>';
}

/* -------------------------------------------------------------------------
 * ONE-TIME BACKFILL for sites that already had their footer/pages seeded
 * (by kounselia_content_seed_defaults()) before this file existed. Runs
 * once, adds the two professionals links to whatever footer the admin
 * already has (never touching their other customisations), and appends
 * a short note to the "Our counselors" page pointing to /professionals/
 * — but only if that page doesn't already mention it.
 * ---------------------------------------------------------------------- */

function kounselia_professionals_backfill_links() {
    if ( get_option( 'kounselia_professionals_links_backfilled' ) ) {
        return;
    }
    update_option( 'kounselia_professionals_links_backfilled', 1 );

    if ( function_exists( 'kounselia_footer_settings' ) ) {
        kounselia_footer_backfill_professionals_links();
    }
    if ( function_exists( 'kounselia_get_page_by_slug' ) ) {
        kounselia_counselors_page_backfill_professionals_note();
    }
}

function kounselia_footer_backfill_professionals_links() {
    $footer  = kounselia_footer_settings();
    $columns = (array) $footer['columns'];

    $has_link = function ( $target ) use ( $columns ) {
        foreach ( $columns as $col ) {
            foreach ( (array) $col['links'] as $link ) {
                if ( ! empty( $link['url'] ) && untrailingslashit( $link['url'] ) === untrailingslashit( $target ) ) {
                    return true;
                }
            }
        }
        return false;
    };

    $changed = false;

    if ( ! $has_link( '/professionals/' ) ) {
        $inserted = false;
        foreach ( $columns as $ci => $col ) {
            foreach ( (array) $col['links'] as $li => $link ) {
                if ( 'our-counselors' === ( $link['page'] ?? '' ) ) {
                    // Sits right after "Our counselors", the same place the default footer puts it.
                    array_splice( $columns[ $ci ]['links'], $li + 1, 0, array( array( 'label' => 'Find a professional', 'page' => '', 'url' => '/professionals/' ) ) );
                    $inserted = true;
                    break 2;
                }
            }
        }
        if ( ! $inserted && isset( $columns[0] ) ) {
            $columns[0]['links'][] = array( 'label' => 'Find a professional', 'page' => '', 'url' => '/professionals/' );
            $inserted = true;
        }
        $changed = $changed || $inserted;
    }

    if ( ! $has_link( '/professionals/join' ) ) {
        // Prefer an "Organisation" column if there is one, else the last column.
        $target_ci = null;
        foreach ( $columns as $ci => $col ) {
            if ( false !== stripos( (string) $col['title'], 'organisation' ) || false !== stripos( (string) $col['title'], 'organization' ) ) {
                $target_ci = $ci;
                break;
            }
        }
        if ( null === $target_ci && $columns ) {
            $target_ci = count( $columns ) - 1;
        }
        if ( null !== $target_ci ) {
            $columns[ $target_ci ]['links'][] = array( 'label' => 'For professionals', 'page' => '', 'url' => '/professionals/join' );
            $changed = true;
        }
    }

    if ( $changed ) {
        $footer['columns'] = $columns;
        update_option( 'kounselia_footer', $footer );
    }
}

function kounselia_counselors_page_backfill_professionals_note() {
    $page = kounselia_get_page_by_slug( 'our-counselors', false );
    if ( ! $page || false !== stripos( (string) $page->content, 'professionals' ) ) {
        return; // No such page, or it already mentions professionals — leave it alone.
    }

    $note = '<h2>Want to talk to a licensed human?</h2>'
        . '<p>Our counselors are AI — and they will always say so. When you would rather speak with a licensed psychologist, counsellor or therapist, you can book a private video session with one of our <a href="/professionals/">verified professionals</a>.</p>';

    kounselia_save_page( array(
        'title'            => $page->title,
        'slug'             => $page->slug,
        'eyebrow'          => $page->eyebrow,
        'subtitle'         => $page->subtitle,
        'hero_image'       => $page->hero_image,
        'meta_description' => $page->meta_description,
        'status'           => $page->status,
        'content'          => $page->content . $note,
    ), $page->id );
}
add_action( 'init', 'kounselia_professionals_backfill_links', 20 );
