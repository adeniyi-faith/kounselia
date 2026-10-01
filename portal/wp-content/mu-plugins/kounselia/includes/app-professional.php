<?php
/**
 * Kounselia Core — the professional's side of the mobile app.
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 *
 * The website builds a professional's home (pro-dashboard.php) straight
 * into the page; the app can't read a page, so these actions hand it the
 * same information, built with the same helpers so both always agree.
 * Everything a professional changes (profile, availability, bookings,
 * payouts, video setting, articles) goes through the website's own
 * actions, which the app already calls as they are.
 *
 *   kounselia_app_pro_dashboard          everything pro-dashboard.php shows
 *   kounselia_app_apply_professional     apply from the app, documents as base64;
 *                                        creates the account too when signed out
 *   kounselia_app_upload_professional_document  add a document, as base64
 *   kounselia_app_professional_document_link    a one-time link to view one of
 *                                        their own documents in the phone's viewer
 *   kounselia_app_view_professional_document    what that link opens
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Same limit as the website's upload (kounselia_store_professional_document).
define( 'KOUNSELIA_APP_DOC_MAX_BYTES', 8 * 1024 * 1024 );

/**
 * Whether the member is a professional, and where their application
 * stands, for kounselia_app_user_payload(): the app shows a professional
 * their own home instead of the client one, as the website does.
 *
 * @return array{id:int,status:string}|null
 */
function kounselia_app_professional_summary( $user_id ) {
    $pro = kounselia_get_professional_application( $user_id );
    return $pro ? array( 'id' => (int) $pro->id, 'status' => (string) $pro->status ) : null;
}

/**
 * Turns a document the app sent as base64 into a $_FILES-style entry
 * kounselia_store_professional_document() can keep. What the file really
 * is gets checked here (not the label the phone put on it), and the
 * stored name gets the matching extension.
 *
 * @param string $prefix The field names' start: "<prefix>_b64" and "<prefix>_name".
 * @return array|null|WP_Error null when nothing was sent.
 */
function kounselia_app_file_from_base64( $prefix ) {
    $b64 = isset( $_POST[ $prefix . '_b64' ] ) ? (string) wp_unslash( $_POST[ $prefix . '_b64' ] ) : '';
    if ( '' === $b64 ) {
        return null;
    }
    $bytes = base64_decode( $b64, true );
    if ( ! $bytes ) {
        return new WP_Error( 'kounselia_doc', 'That file did not come through. Please try again.' );
    }
    if ( strlen( $bytes ) > KOUNSELIA_APP_DOC_MAX_BYTES ) {
        return new WP_Error( 'kounselia_doc', 'That file is too large. Please use one under 8MB.' );
    }

    $tmp = wp_tempnam( 'kounselia-doc' );
    file_put_contents( $tmp, $bytes );

    $ext = '';
    if ( 0 === strpos( $bytes, '%PDF' ) ) {
        $ext = 'pdf';
    } else {
        $real = wp_get_image_mime( $tmp );
        $ext  = 'image/jpeg' === $real ? 'jpg' : ( 'image/png' === $real ? 'png' : '' );
    }
    if ( '' === $ext ) {
        @unlink( $tmp );
        return new WP_Error( 'kounselia_doc', 'Please use a PDF, JPG, or PNG file.' );
    }

    $name = isset( $_POST[ $prefix . '_name' ] ) ? sanitize_file_name( wp_unslash( $_POST[ $prefix . '_name' ] ) ) : '';
    $base = $name ? preg_replace( '/\.[^.]*$/', '', $name ) : 'document';
    return array(
        'name'          => ( $base ? $base : 'document' ) . '.' . $ext,
        'tmp_name'      => $tmp,
        'size'          => strlen( $bytes ),
        'error'         => UPLOAD_ERR_OK,
        'kounselia_app' => true,
    );
}

function kounselia_app_discard_file( $file ) {
    if ( is_array( $file ) && ! empty( $file['tmp_name'] ) && file_exists( $file['tmp_name'] ) ) {
        @unlink( $file['tmp_name'] );
    }
}

/**
 * The signed-in member's application, or an error reply.
 */
function kounselia_app_require_professional() {
    $user_id = kounselia_app_require_member();
    $pro     = kounselia_get_professional_application( $user_id );
    if ( ! $pro ) {
        wp_send_json_error( array( 'message' => 'You do not have a professional application on file.', 'no_application' => true ), 403 );
    }
    return $pro;
}

/** A site-time timestamp (current_time('timestamp') style) as UTC ISO 8601. */
function kounselia_app_utc_from_local_ts( $ts ) {
    return kounselia_app_utc( gmdate( 'Y-m-d H:i:s', (int) $ts ) );
}

/* -------------------------------------------------------------------------
 * THE PROFESSIONAL'S HOME
 * ---------------------------------------------------------------------- */

/**
 * A professional's confirmed sessions that haven't finished yet, soonest
 * first. Like the client side (kounselia_app_open_bookings), a session is
 * kept until its joining window closes, so a professional a minute late
 * still sees Join.
 */
function kounselia_app_pro_open_bookings( $professional_id ) {
    $now = current_time( 'timestamp' );
    return array_values( array_filter( kounselia_get_professional_bookings( $professional_id, false ), function ( $b ) use ( $now ) {
        return strtotime( $b->scheduled_end ) + 15 * MINUTE_IN_SECONDS >= $now;
    } ) );
}

function kounselia_ajax_app_pro_dashboard() {
    $pro     = kounselia_app_require_professional();
    $user    = wp_get_current_user();
    $user_id = (int) $user->ID;
    $name    = $user->display_name ? $user->display_name : $user->user_login;
    $verified = 'verified' === $pro->status;
    $own_video_allowed = function_exists( 'kounselia_video_own_allowed' ) && kounselia_video_own_allowed( $pro );

    $rating = function_exists( 'kounselia_get_professional_rating_summary' ) ? kounselia_get_professional_rating_summary( $pro->id ) : array( 'average' => 0, 'count' => 0 );

    $reviews = array();
    if ( function_exists( 'kounselia_get_professional_reviews' ) ) {
        foreach ( kounselia_get_professional_reviews( $pro->id ) as $r ) {
            $reviews[] = array(
                'rating'      => (int) $r->rating,
                'comment'     => (string) $r->comment,
                'client_name' => $r->client_name ? $r->client_name : 'A client',
                'date_utc'    => kounselia_app_utc( $r->created_at ),
            );
        }
    }

    $doc_labels = array( 'license' => 'License / credential', 'id' => 'Government ID', 'certificate' => 'Certificate', 'other' => 'Other document' );
    $documents  = array();
    foreach ( kounselia_get_professional_documents( $pro->id ) as $doc ) {
        $documents[] = array(
            'id'         => (int) $doc->id,
            'name'       => $doc->original_filename,
            'type_label' => isset( $doc_labels[ $doc->doc_type ] ) ? $doc_labels[ $doc->doc_type ] : ucfirst( $doc->doc_type ),
        );
    }

    // One window per day, as the website's weekly editor shows it.
    $availability = array();
    foreach ( kounselia_get_availability_rules( $pro->id ) as $rule ) {
        $day = (int) $rule->day_of_week;
        if ( ! isset( $availability[ $day ] ) ) {
            $availability[ $day ] = array( 'day' => $day, 'start' => substr( $rule->start_time, 0, 5 ), 'end' => substr( $rule->end_time, 0, 5 ) );
        }
    }

    $bookings = array();
    foreach ( kounselia_app_pro_open_bookings( $pro->id ) as $b ) {
        $window = kounselia_booking_join_window( $b );
        $where  = function_exists( 'kounselia_booking_video' ) ? kounselia_booking_video( $b, $pro ) : array( 'external' => false, 'provider' => 'Kounselia' );
        $bookings[] = array(
            'id'               => (int) $b->id,
            'client_name'      => $b->client_name ? $b->client_name : $b->client_email,
            'client_note'      => $b->client_note ? (string) $b->client_note : null,
            'start_local'      => $b->scheduled_start,
            'start_utc'        => kounselia_app_utc( $b->scheduled_start ),
            'join_opens_utc'   => kounselia_app_utc_from_local_ts( $window['opens_at'] ),
            'join_closes_utc'  => kounselia_app_utc_from_local_ts( $window['closes_at'] ),
            'joinable'         => kounselia_booking_is_joinable( $b ),
            'series_id'        => (int) $b->series_id,
            'is_free'          => ! empty( $b->is_free ),
            // "Zoom", "Google Meet"... when this session isn't in Kounselia's own room.
            'video_provider'   => $where['external'] ? $where['provider'] : null,
            'video_link'       => $b->video_link ? (string) $b->video_link : '',
        );
    }

    // Articles for the Journal (articles.php + community.php).
    $article_settings = function_exists( 'kounselia_article_settings' ) ? kounselia_article_settings() : array();
    $access           = function_exists( 'kounselia_article_access' ) ? kounselia_article_access( $pro ) : array( 'allowed' => false, 'mode' => '', 'message' => '' );
    $article_rows     = function_exists( 'kounselia_pro_articles' ) ? kounselia_pro_articles( $pro->id ) : array();
    $totals           = array( 'views' => 0, 'loves' => 0, 'comments' => 0 );
    $articles         = array();
    foreach ( $article_rows as $a ) {
        $totals['views']    += (int) $a->views;
        $totals['loves']    += (int) $a->love_count;
        $totals['comments'] += (int) $a->comment_count;
        $state = kounselia_article_state( $a );
        $live  = in_array( $state, array( 'live', 'live_pending', 'live_draft' ), true );
        $note  = $a->review_note && in_array( $state, array( 'changes', 'rejected', 'removed', 'live_draft' ), true ) && in_array( $a->review_status, array( 'changes_requested', 'rejected', 'removed' ), true );
        $articles[] = array(
            'id'          => (int) $a->id,
            'title'       => $a->title,
            'slug'        => $a->slug,
            'cover'       => $a->cover_image ? $a->cover_image : null,
            'state'       => $state,
            'state_label' => kounselia_article_state_label( $state ),
            'live'        => $live,
            // Waiting for an editor, so it can be taken back.
            'in_review'   => in_array( $state, array( 'pending', 'live_pending' ), true ),
            'date_utc'    => kounselia_app_utc( $a->published_at ? $a->published_at : $a->updated_at ),
            'views'       => (int) $a->views,
            'loves'       => (int) $a->love_count,
            'comments'    => (int) $a->comment_count,
            'review_note' => $note ? (string) $a->review_note : null,
            'url'         => kounselia_blog_url( $a->slug, true ),
        );
    }

    $balance  = function_exists( 'kounselia_get_professional_balance' ) ? kounselia_get_professional_balance( $pro->id ) : array( 'available' => 0, 'total_earned' => 0, 'paid_out' => 0 );
    $account  = function_exists( 'kounselia_get_payout_account' ) ? kounselia_get_payout_account( $pro->id ) : null;
    $status_labels = array( 'pending' => 'Processing', 'success' => 'Paid', 'failed' => 'Failed' );
    $payouts  = array();
    foreach ( function_exists( 'kounselia_get_payout_history' ) ? kounselia_get_payout_history( $pro->id ) : array() as $p ) {
        $payouts[] = array(
            'id'             => (int) $p->id,
            'amount'         => (float) $p->amount,
            'status'         => (string) $p->status,
            'status_label'   => isset( $status_labels[ $p->status ] ) ? $status_labels[ $p->status ] : ucfirst( $p->status ),
            'date_utc'       => kounselia_app_utc( $p->created_at ),
            'failure_reason' => 'failed' === $p->status && $p->failure_reason ? (string) $p->failure_reason : null,
        );
    }

    $free_options = array();
    if ( function_exists( 'kounselia_free_session_options' ) ) {
        foreach ( kounselia_free_session_options() as $value => $label ) {
            $free_options[] = array( 'value' => (int) $value, 'label' => $label );
        }
    }
    $all_free = defined( 'KOUNSELIA_FREE_ALWAYS' ) && (int) $pro->free_sessions_per_client >= KOUNSELIA_FREE_ALWAYS;

    $usd_hint = null;
    if ( function_exists( 'kounselia_usd_ngn_rate' ) && $pro->rate_amount ) {
        $usd_hint = 'Clients outside Nigeria see about ' . kounselia_format_money( kounselia_convert_ngn( $pro->rate_amount, 'USD' ), 'USD' ) . '. You are always paid in naira.';
    }

    $avatar = kounselia_get_avatar_url( $user_id, 'thumbnail' );

    wp_send_json_success( array(
        'user'        => array(
            'name'       => $name,
            'first_name' => explode( ' ', trim( $name ) )[0],
            'avatar'     => $avatar ? $avatar : null,
        ),
        'application' => array(
            'id'                       => (int) $pro->id,
            'status'                   => (string) $pro->status,
            'title'                    => (string) $pro->title,
            'specialty'                => (string) $pro->specialty,
            'years_experience'         => $pro->years_experience ? (int) $pro->years_experience : null,
            'bio'                      => (string) $pro->bio,
            'rate_amount'              => $pro->rate_amount ? (float) $pro->rate_amount : null,
            'free_sessions_per_client' => (int) $pro->free_sessions_per_client,
            'all_free'                 => $all_free,
            'license_number'           => $pro->license_number ? (string) $pro->license_number : null,
            'rejection_reason'         => ! empty( $pro->rejection_reason ) ? (string) $pro->rejection_reason : null,
            'suspended_reason'         => ! empty( $pro->suspended_reason ) ? (string) $pro->suspended_reason : null,
        ),
        'rating'      => array( 'average' => (float) $rating['average'], 'count' => (int) $rating['count'] ),
        'reviews'     => $reviews,
        'free_options' => $free_options,
        'rate_usd_hint' => $usd_hint,
        'public_profile' => array(
            'available' => function_exists( 'kounselia_professional_is_public' ),
            'on'        => function_exists( 'kounselia_professional_is_public' ) && kounselia_professional_is_public( $user_id ),
            'url'       => $verified && function_exists( 'kounselia_professional_url' ) ? kounselia_professional_url( (object) array( 'display_name' => $name, 'id' => $pro->id ), true ) : null,
        ),
        // Only offered once verified, as on the website.
        'video'       => array(
            'shown'   => $verified && function_exists( 'kounselia_video_own_allowed' ),
            'allowed' => $own_video_allowed,
            'mode'    => 'own' === $pro->video_mode ? 'own' : 'kounselia',
            'link'    => (string) $pro->video_link,
        ),
        'documents'    => $documents,
        'availability' => array_values( $availability ),
        'bookings'     => $bookings,
        'session_minutes' => kounselia_session_length_minutes(),
        'articles'     => array(
            'enabled'   => ! empty( $article_settings['enabled'] ),
            'show_tab'  => ! empty( $article_settings['enabled'] ) || ! empty( $articles ),
            'access'    => array( 'allowed' => (bool) $access['allowed'], 'mode' => (string) $access['mode'], 'message' => (string) $access['message'] ),
            'followers' => function_exists( 'kounselia_follower_count' ) ? (int) kounselia_follower_count( $pro->id ) : 0,
            'totals'    => $totals,
            'items'     => $articles,
        ),
        'earnings'     => array(
            'available'          => (float) $balance['available'],
            'total_earned'       => (float) $balance['total_earned'],
            'paid_out'           => (float) $balance['paid_out'],
            'commission_percent' => function_exists( 'kounselia_booking_commission_percent' ) ? (float) kounselia_booking_commission_percent() : 0,
            'account'            => $account ? array(
                'account_name' => (string) $account->account_name,
                'bank_name'    => (string) $account->bank_name,
                'last4'        => substr( (string) $account->account_number, -4 ),
            ) : null,
            'history'            => $payouts,
        ),
    ) );
}
add_action( 'wp_ajax_kounselia_app_pro_dashboard', 'kounselia_ajax_app_pro_dashboard' );
add_action( 'wp_ajax_nopriv_kounselia_app_pro_dashboard', 'kounselia_ajax_app_pro_dashboard' );

/* -------------------------------------------------------------------------
 * APPLYING FROM THE APP
 * ---------------------------------------------------------------------- */

/**
 * The website's apply form (apply.php) for the app: the same fields and
 * checks (kounselia_professional_application_problem), with the two
 * documents as base64. Someone signed out gets their account made here
 * too, in the same step, and the app gets the sign-in back, so nobody
 * ends up with an account and no application because one half failed.
 */
function kounselia_ajax_app_apply_professional() {
    kounselia_verify_nonce();

    // Looser than the website's 3 an hour per address: mobile carriers put
    // many people behind one address (see app-auth.php).
    if ( kounselia_rate_limited( 'app_apply_professional', 10, 3600 ) ) {
        wp_send_json_error( array( 'message' => 'Too many attempts. Please try again later.' ), 429 );
    }

    $fields  = kounselia_professional_application_fields_from_post();
    $problem = kounselia_professional_application_problem( $fields );
    if ( '' !== $problem ) {
        wp_send_json_error( array( 'message' => $problem ), 400 );
    }

    $signed_in = is_user_logged_in();
    if ( $signed_in ) {
        $existing = kounselia_get_professional_application( get_current_user_id() );
        if ( $existing && 'rejected' !== $existing->status ) {
            wp_send_json_error( array( 'message' => 'You already have an application on file.' ), 400 );
        }
    }

    // Documents are checked before any account is made.
    $license_doc = kounselia_app_file_from_base64( 'license_doc' );
    if ( is_wp_error( $license_doc ) ) {
        wp_send_json_error( array( 'message' => $license_doc->get_error_message() ), 400 );
    }
    if ( ! $license_doc ) {
        wp_send_json_error( array( 'message' => 'Please upload a license or credential document.' ), 400 );
    }
    $id_doc = kounselia_app_file_from_base64( 'id_doc' );
    if ( is_wp_error( $id_doc ) ) {
        kounselia_app_discard_file( $license_doc );
        wp_send_json_error( array( 'message' => 'Government ID: ' . $id_doc->get_error_message() ), 400 );
    }

    $token = null;
    if ( $signed_in ) {
        $user_id = get_current_user_id();
    } else {
        $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        if ( '' === $name ) {
            kounselia_app_discard_file( $license_doc );
            kounselia_app_discard_file( $id_doc );
            wp_send_json_error( array( 'message' => 'Please fill in your name, email, and password.' ), 400 );
        }
        $user_id = kounselia_create_member(
            $name,
            isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
            isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''
        );
        if ( is_wp_error( $user_id ) ) {
            kounselia_app_discard_file( $license_doc );
            kounselia_app_discard_file( $id_doc );
            $message = $user_id->get_error_message();
            if ( 'That email is already registered.' === $message ) {
                $message = 'That email is already registered. Please sign in first, then apply.';
            }
            wp_send_json_error( array( 'message' => $message ), (int) $user_id->get_error_data() );
        }
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
        update_user_meta( $user_id, 'kounselia_last_ip', $ip );
        $device = isset( $_POST['device_name'] ) ? wp_unslash( $_POST['device_name'] ) : '';
        $token  = kounselia_issue_app_token( $user_id, $device );
    }

    $result = kounselia_submit_professional_application( $user_id, $fields, $license_doc, $id_doc );
    kounselia_app_discard_file( $license_doc );
    kounselia_app_discard_file( $id_doc );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
    }

    $data = array(
        'message' => 'Application submitted. We will review it and email you.',
        'user'    => kounselia_app_user_payload( get_userdata( $user_id ) ),
    );
    if ( $token ) {
        $data['token'] = $token;
    }
    wp_send_json_success( $data );
}
add_action( 'wp_ajax_kounselia_app_apply_professional', 'kounselia_ajax_app_apply_professional' );
add_action( 'wp_ajax_nopriv_kounselia_app_apply_professional', 'kounselia_ajax_app_apply_professional' );

/* -------------------------------------------------------------------------
 * DOCUMENTS
 * ---------------------------------------------------------------------- */

/** kounselia_ajax_upload_professional_document(), with the file as base64. */
function kounselia_ajax_app_upload_professional_document() {
    $pro = kounselia_app_require_professional();
    if ( kounselia_rate_limited( 'upload_professional_document', 10, 3600 ) ) {
        wp_send_json_error( array( 'message' => 'Too many uploads. Please try again later.' ), 429 );
    }

    global $wpdb;
    $count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_professional_documents WHERE professional_id = %d",
        $pro->id
    ) );
    if ( $count >= 10 ) {
        wp_send_json_error( array( 'message' => 'You have reached the maximum of 10 documents. Remove one before adding another.' ), 400 );
    }

    $doc_type = isset( $_POST['doc_type'] ) ? sanitize_key( $_POST['doc_type'] ) : 'other';
    if ( ! in_array( $doc_type, array( 'license', 'id', 'certificate', 'other' ), true ) ) {
        $doc_type = 'other';
    }

    $file = kounselia_app_file_from_base64( 'document' );
    if ( is_wp_error( $file ) ) {
        wp_send_json_error( array( 'message' => $file->get_error_message() ), 400 );
    }
    if ( ! $file ) {
        wp_send_json_error( array( 'message' => 'Please choose a file to upload.' ), 400 );
    }

    $doc_id = kounselia_store_professional_document( $pro->id, $file, $doc_type );
    kounselia_app_discard_file( $file );
    if ( ! $doc_id ) {
        wp_send_json_error( array( 'message' => 'Could not upload that file. Please use a PDF, JPG, or PNG under 8MB.' ), 400 );
    }
    wp_send_json_success( array( 'message' => 'Document uploaded.', 'id' => (int) $doc_id ) );
}
add_action( 'wp_ajax_kounselia_app_upload_professional_document', 'kounselia_ajax_app_upload_professional_document' );
add_action( 'wp_ajax_nopriv_kounselia_app_upload_professional_document', 'kounselia_ajax_app_upload_professional_document' );

// How long a document link from the app works. It's opened straight away.
define( 'KOUNSELIA_APP_DOC_LINK_TTL', 120 );

function kounselia_app_doc_link_key( $code ) {
    return 'kounselia_doclink_' . hash( 'sha256', (string) $code );
}

/**
 * The website's document links (kounselia_professional_document_url) only
 * work in a browser signed in with the website's cookie, which the phone's
 * file viewer never has. This gives the app a one-time link instead, good
 * for two minutes, for one of the professional's own documents only.
 */
function kounselia_ajax_app_professional_document_link() {
    $pro    = kounselia_app_require_professional();
    $doc_id = isset( $_POST['doc_id'] ) ? absint( $_POST['doc_id'] ) : 0;

    global $wpdb;
    $owned = $doc_id ? (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}kounselia_professional_documents WHERE id = %d AND professional_id = %d",
        $doc_id,
        $pro->id
    ) ) : 0;
    if ( ! $owned ) {
        wp_send_json_error( array( 'message' => 'Document not found.' ), 404 );
    }

    $code = bin2hex( random_bytes( 32 ) );
    set_transient( kounselia_app_doc_link_key( $code ), array( 'doc_id' => $doc_id, 'user_id' => get_current_user_id() ), KOUNSELIA_APP_DOC_LINK_TTL );

    wp_send_json_success( array(
        'url' => add_query_arg(
            array( 'action' => 'kounselia_app_view_professional_document', 'code' => $code ),
            admin_url( 'admin-ajax.php' )
        ),
    ) );
}
add_action( 'wp_ajax_kounselia_app_professional_document_link', 'kounselia_ajax_app_professional_document_link' );
add_action( 'wp_ajax_nopriv_kounselia_app_professional_document_link', 'kounselia_ajax_app_professional_document_link' );

function kounselia_ajax_app_view_professional_document() {
    $code = isset( $_GET['code'] ) ? (string) $_GET['code'] : '';
    $key  = kounselia_app_doc_link_key( $code );
    $link = '' !== $code ? get_transient( $key ) : false;
    // Single-use: gone the moment it's read.
    delete_transient( $key );
    if ( ! is_array( $link ) ) {
        wp_die( 'This link has expired. Please open the document from the app again.', 'Invalid link', array( 'response' => 403 ) );
    }

    global $wpdb;
    $doc = $wpdb->get_row( $wpdb->prepare(
        "SELECT d.*, p.user_id FROM {$wpdb->prefix}kounselia_professional_documents d
         INNER JOIN {$wpdb->prefix}kounselia_professionals p ON p.id = d.professional_id
         WHERE d.id = %d",
        (int) $link['doc_id']
    ) );
    if ( ! $doc || ! kounselia_user_can_view_professional_document( (int) $link['user_id'], $doc->user_id ) ) {
        wp_die( 'Not found.', 'Not found', array( 'response' => 404 ) );
    }
    kounselia_stream_professional_document( $doc );
}
add_action( 'wp_ajax_kounselia_app_view_professional_document', 'kounselia_ajax_app_view_professional_document' );
add_action( 'wp_ajax_nopriv_kounselia_app_view_professional_document', 'kounselia_ajax_app_view_professional_document' );
