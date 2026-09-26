<?php
/**
 * Kounselia Core — which currency a visitor sees and pays in.
 *
 * Visitors in the "naira countries" list (Nigeria by default) see and
 * pay naira; everyone else sees and pays US dollars. All of it is
 * admin-configurable from Settings → Currency & Pricing: the country
 * list, a force-one-currency override, the exchange rate, and what to
 * show when a visitor's location can't be worked out.
 *
 * Prices are still stored in naira at the source (a professional's
 * session rate, a plan's base price). Dollar prices are either set
 * explicitly per plan, or converted from naira with the admin's
 * exchange rate. Professionals are always paid out in naira: a dollar
 * booking still credits them their naira rate minus commission (see
 * kounselia_init_booking_payment()).
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function kounselia_supported_currencies() {
    return array( 'NGN', 'USD' );
}

/* -------------------------------------------------------------------------
 * ADMIN SETTINGS
 * ---------------------------------------------------------------------- */

/** 'auto' (by location), or 'NGN' / 'USD' to force one currency for everyone. */
function kounselia_currency_mode() {
    $mode = get_option( 'kounselia_currency_mode', 'auto' );
    return in_array( $mode, array( 'auto', 'NGN', 'USD' ), true ) ? $mode : 'auto';
}

/** ISO country codes that see naira. */
function kounselia_naira_countries() {
    $raw   = get_option( 'kounselia_naira_countries', 'NG' );
    $codes = array_filter( array_map( 'strtoupper', array_map( 'trim', explode( ',', (string) $raw ) ) ) );
    return $codes ? array_values( $codes ) : array( 'NG' );
}

/** Currency for a visitor whose country couldn't be determined. */
function kounselia_unknown_location_currency() {
    $currency = get_option( 'kounselia_currency_unknown_default', 'USD' );
    return in_array( $currency, kounselia_supported_currencies(), true ) ? $currency : 'USD';
}

/** How many naira one US dollar buys, as set by an admin. */
function kounselia_usd_ngn_rate() {
    $rate = (float) get_option( 'kounselia_usd_ngn_rate', 1500 );
    return $rate > 0 ? $rate : 1500;
}

function kounselia_geo_lookup_enabled() {
    return (bool) get_option( 'kounselia_geo_lookup_enabled', 1 );
}

/**
 * Off by default: a country header is only trustworthy when a CDN such
 * as Cloudflare sets it. Without one in front of the site, anyone could
 * send the header themselves to pick the cheaper currency.
 */
function kounselia_trust_country_header() {
    return (bool) get_option( 'kounselia_trust_country_header', 0 );
}

/* -------------------------------------------------------------------------
 * DETECTING THE VISITOR'S COUNTRY
 * ---------------------------------------------------------------------- */

/**
 * Two-letter country code for the current visitor, or '' if unknown.
 * Uses the CDN's country header when an admin has said one is in front
 * of the site, otherwise a lookup of the visitor's IP address, cached per
 * IP for a week so it costs at most one outbound request per visitor.
 */
function kounselia_detect_country() {
    static $country = null;
    if ( null !== $country ) {
        return $country;
    }

    foreach ( array( 'HTTP_CF_IPCOUNTRY', 'HTTP_X_COUNTRY_CODE', 'GEOIP_COUNTRY_CODE', 'HTTP_X_GEO_COUNTRY' ) as $header ) {
        if ( kounselia_trust_country_header() && ! empty( $_SERVER[ $header ] ) ) {
            $code = strtoupper( substr( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ), 0, 2 ) );
            if ( preg_match( '/^[A-Z]{2}$/', $code ) && 'XX' !== $code ) {
                return $country = $code;
            }
        }
    }

    $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    if ( ! kounselia_geo_lookup_enabled() || ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
        return $country = '';
    }

    $cache_key = 'kounselia_geo_' . md5( $ip );
    $cached    = get_transient( $cache_key );
    if ( false !== $cached ) {
        return $country = (string) $cached;
    }

    $response = wp_remote_get( 'https://ipapi.co/' . rawurlencode( $ip ) . '/country/', array( 'timeout' => 3 ) );
    $code     = '';
    if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
        $body = strtoupper( trim( wp_remote_retrieve_body( $response ) ) );
        if ( preg_match( '/^[A-Z]{2}$/', $body ) ) {
            $code = $body;
        }
    }

    // A failed lookup is cached briefly too, so a lookup outage can't slow every page load.
    set_transient( $cache_key, $code, $code ? WEEK_IN_SECONDS : HOUR_IN_SECONDS );
    return $country = $code;
}

/** The currency the current visitor sees prices in and is charged in. */
function kounselia_viewer_currency() {
    $mode = kounselia_currency_mode();
    if ( 'auto' !== $mode ) {
        return $mode;
    }

    $country = kounselia_detect_country();
    if ( '' === $country ) {
        return kounselia_unknown_location_currency();
    }
    return in_array( $country, kounselia_naira_countries(), true ) ? 'NGN' : 'USD';
}

/* -------------------------------------------------------------------------
 * PRICES
 * ---------------------------------------------------------------------- */

function kounselia_convert_ngn( $amount_ngn, $currency ) {
    $amount_ngn = (float) $amount_ngn;
    if ( 'USD' === $currency ) {
        return round( $amount_ngn / kounselia_usd_ngn_rate(), 2 );
    }
    return round( $amount_ngn, 2 );
}

/**
 * A plan's price in the given currency. A plan's own explicit dollar
 * price wins; otherwise its naira price is converted.
 */
function kounselia_plan_price( $plan, $currency ) {
    if ( 'USD' === $currency && isset( $plan['price_usd'] ) && (float) $plan['price_usd'] > 0 ) {
        return round( (float) $plan['price_usd'], 2 );
    }
    return kounselia_convert_ngn( $plan['price_amount'], $currency );
}

function kounselia_format_money( $amount, $currency ) {
    $amount = (float) $amount;
    if ( 'USD' === $currency ) {
        return '$' . number_format( $amount, 2 );
    }
    return '₦' . number_format( $amount, floor( $amount ) == $amount ? 0 : 2 );
}
