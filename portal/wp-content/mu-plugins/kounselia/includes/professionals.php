<?php
/**
 * Kounselia Core — professional marketplace: applications, verification,
 * and private document storage.
 *
 * Slice 1 of the professional marketplace: a person applies to join as a
 * licensed professional, uploads proof of credentials, and an admin
 * reviews and approves or rejects the application. Approval grants the
 * kounselia_professional WP role — the marker later slices (profile/rate
 * setup, the booking directory, human-answered conversations, earnings)
 * build on. Nothing about routing conversations to a human, payments, or
 * earnings lives here yet; this is just "can this person even be trusted
 * to receive that work."
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * True when a professional title is just an honorific on its own ("Dr",
 * "Dr.", "Prof", "Mr" …) rather than an actual role or qualification.
 * It's an easy thing to type out of habit, but it reads oddly wherever
 * the title is shown next to the name — a name that, for a "Dr", is
 * usually already "Dr. So-and-so" — and tells a client nothing about
 * what the person actually does.
 */
function kounselia_title_is_bare_honorific( $title ) {
    $bare = array( 'dr', 'dr.', 'doctor', 'prof', 'prof.', 'professor', 'mr', 'mr.', 'mrs', 'mrs.', 'ms', 'ms.', 'miss', 'mx', 'mx.' );
    return in_array( strtolower( trim( (string) $title ) ), $bare, true );
}

/* -------------------------------------------------------------------------
 * ROLE
 * ---------------------------------------------------------------------- */

function kounselia_setup_professional_role() {
    if ( get_option( 'kounselia_professional_role_version' ) === '1.0.0' ) {
        return;
    }
    if ( ! get_role( 'kounselia_professional' ) ) {
        // Just 'read' for now — this is a marker role for "verified
        // human professional," not a capability grant. Whatever a
        // professional dashboard needs later gets its own caps then.
        add_role( 'kounselia_professional', 'Kounselia Professional', array( 'read' => true ) );
    }
    update_option( 'kounselia_professional_role_version', '1.0.0' );
}
add_action( 'init', 'kounselia_setup_professional_role' );

function kounselia_user_is_professional( $user_id = 0 ) {
    $user_id = $user_id ? $user_id : get_current_user_id();
    if ( ! $user_id ) {
        return false;
    }
    $user = get_userdata( $user_id );
    return $user && in_array( 'kounselia_professional', (array) $user->roles, true );
}

/* -------------------------------------------------------------------------
 * PRIVATE DOCUMENT STORAGE
 *
 * License/ID documents never go through the public media library — a
 * media library attachment gets a guessable, public URL by default,
 * which is not acceptable for someone's government ID. These live in
 * their own folder outside any public listing, blocked at the
 * webserver level, and are only ever served through the authenticated
 * endpoint below.
 * ---------------------------------------------------------------------- */

function kounselia_professional_docs_dir() {
    $upload_dir = wp_upload_dir();
    return trailingslashit( $upload_dir['basedir'] ) . 'kounselia-professional-docs';
}

function kounselia_ensure_professional_docs_dir() {
    $dir = kounselia_professional_docs_dir();
    if ( ! file_exists( $dir ) ) {
        wp_mkdir_p( $dir );
    }

    $htaccess = trailingslashit( $dir ) . '.htaccess';
    if ( ! file_exists( $htaccess ) ) {
        file_put_contents( $htaccess,
            "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n"
        );
    }

    $index = trailingslashit( $dir ) . 'index.php';
    if ( ! file_exists( $index ) ) {
        file_put_contents( $index, "<?php\n// Silence is golden.\n" );
    }
}

/**
 * Move an uploaded file into private storage and record it. Returns the
 * new document row's id, or false if the upload was missing/invalid.
 */
function kounselia_store_professional_document( $professional_id, $file, $doc_type ) {
    if ( empty( $file ) || empty( $file['tmp_name'] ) || ! isset( $file['error'] ) || UPLOAD_ERR_OK !== $file['error'] ) {
        return false;
    }

    $allowed  = array( 'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png' );
    $filetype = wp_check_filetype( $file['name'], $allowed );
    if ( empty( $filetype['ext'] ) ) {
        return false;
    }

    $max_bytes = 8 * 1024 * 1024; // 8MB
    if ( $file['size'] > $max_bytes ) {
        return false;
    }

    // The name alone can lie (a web page renamed licence.png). Check the
    // file's contents really are a PDF or picture of the type its name says.
    $real = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $allowed );
    if ( empty( $real['ext'] ) || empty( $real['type'] ) ) {
        return false;
    }

    kounselia_ensure_professional_docs_dir();
    $stored_filename = wp_generate_password( 32, false ) . '.' . $filetype['ext'];
    $dest            = trailingslashit( kounselia_professional_docs_dir() ) . $stored_filename;

    // The app sends files as base64, so they're written to a temporary
    // file by kounselia_app_file_from_base64() rather than uploaded the
    // usual way; move_uploaded_file() refuses anything PHP didn't receive
    // as an upload itself. Only our own code can set this flag: PHP builds
    // each $_FILES entry and never adds keys of its own choosing.
    $moved = ! empty( $file['kounselia_app'] ) ? @rename( $file['tmp_name'], $dest ) : move_uploaded_file( $file['tmp_name'], $dest );
    if ( ! $moved ) {
        return false;
    }

    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'kounselia_professional_documents', array(
        'professional_id'   => $professional_id,
        'doc_type'          => sanitize_key( $doc_type ),
        'original_filename' => sanitize_file_name( $file['name'] ),
        'stored_filename'   => $stored_filename,
        'uploaded_at'       => current_time( 'mysql' ),
    ) );

    return (int) $wpdb->insert_id;
}

function kounselia_delete_professional_documents( $professional_id ) {
    global $wpdb;
    $docs = $wpdb->get_results( $wpdb->prepare(
        "SELECT stored_filename FROM {$wpdb->prefix}kounselia_professional_documents WHERE professional_id = %d",
        $professional_id
    ) );
    foreach ( $docs as $doc ) {
        $path = trailingslashit( kounselia_professional_docs_dir() ) . $doc->stored_filename;
        if ( file_exists( $path ) ) {
            @unlink( $path );
        }
    }
    $wpdb->delete( $wpdb->prefix . 'kounselia_professional_documents', array( 'professional_id' => $professional_id ) );
}

/**
 * Build a time-boxed, per-document authenticated link. Never a raw
 * public URL — this always goes back through kounselia_ajax_view_professional_document
 * for an ownership/admin check before anything is served.
 */
function kounselia_professional_document_url( $doc_id ) {
    $nonce = wp_create_nonce( 'kounselia_view_professional_doc_' . $doc_id );
    return add_query_arg(
        array( 'action' => 'kounselia_view_professional_document', 'doc_id' => (int) $doc_id, 'nonce' => $nonce ),
        admin_url( 'admin-ajax.php' )
    );
}

/**
 * The actual "who is allowed to see this" decision, factored out on
 * its own so it's testable without exercising the file-streaming side
 * effect below it (which ends the request on purpose, by design, on a
 * real request — not something a test suite should ever trigger).
 */
function kounselia_user_can_view_professional_document( $user_id, $doc_owner_user_id ) {
    if ( ! $user_id ) {
        return false;
    }
    return ( (int) $doc_owner_user_id === (int) $user_id ) || kounselia_admin_can( 'professionals', $user_id );
}

function kounselia_ajax_view_professional_document() {
    if ( ! is_user_logged_in() ) {
        wp_die( 'Please sign in.', 'Unauthorized', array( 'response' => 401 ) );
    }

    $doc_id = isset( $_GET['doc_id'] ) ? absint( $_GET['doc_id'] ) : 0;
    $nonce  = isset( $_GET['nonce'] ) ? wp_unslash( $_GET['nonce'] ) : '';

    if ( ! $doc_id || ! wp_verify_nonce( $nonce, 'kounselia_view_professional_doc_' . $doc_id ) ) {
        wp_die( 'This link has expired.', 'Invalid link', array( 'response' => 403 ) );
    }

    global $wpdb;
    $doc = $wpdb->get_row( $wpdb->prepare(
        "SELECT d.*, p.user_id FROM {$wpdb->prefix}kounselia_professional_documents d
         INNER JOIN {$wpdb->prefix}kounselia_professionals p ON p.id = d.professional_id
         WHERE d.id = %d",
        $doc_id
    ) );

    if ( ! $doc ) {
        wp_die( 'Not found.', 'Not found', array( 'response' => 404 ) );
    }

    if ( ! kounselia_user_can_view_professional_document( get_current_user_id(), $doc->user_id ) ) {
        wp_die( 'You do not have access to this document.', 'Unauthorized', array( 'response' => 403 ) );
    }

    kounselia_stream_professional_document( $doc );
}
add_action( 'wp_ajax_kounselia_view_professional_document', 'kounselia_ajax_view_professional_document' );

/**
 * Sends one stored document to the browser and ends the request. Only
 * call it once the viewer has been checked (above, or the app's one-time
 * link in app-professional.php).
 */
function kounselia_stream_professional_document( $doc ) {
    $path = trailingslashit( kounselia_professional_docs_dir() ) . $doc->stored_filename;
    if ( ! file_exists( $path ) ) {
        wp_die( 'That file is missing.', 'Not found', array( 'response' => 404 ) );
    }

    $filetype = wp_check_filetype( $path );
    $mime     = $filetype['type'] ? $filetype['type'] : 'application/octet-stream';

    nocache_headers();
    header( 'Content-Type: ' . $mime );
    header( 'Content-Disposition: inline; filename="' . sanitize_file_name( $doc->original_filename ) . '"' );
    header( 'Content-Length: ' . filesize( $path ) );
    header( 'X-Robots-Tag: noindex, nofollow' );
    header( 'X-Content-Type-Options: nosniff' );
    readfile( $path );
    exit;
}

/* -------------------------------------------------------------------------
 * APPLICATION
 * ---------------------------------------------------------------------- */

function kounselia_get_professional_application( $user_id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}kounselia_professionals WHERE user_id = %d",
        $user_id
    ) );
}

function kounselia_get_professional_documents( $professional_id ) {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}kounselia_professional_documents WHERE professional_id = %d ORDER BY uploaded_at ASC",
        $professional_id
    ) );
}

/**
 * The professional details of an application as posted (the website's
 * apply form and the app send the same field names), cleaned.
 */
function kounselia_professional_application_fields_from_post() {
    return array(
        'title'            => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
        'license_number'   => isset( $_POST['license_number'] ) ? sanitize_text_field( wp_unslash( $_POST['license_number'] ) ) : '',
        'specialty'        => isset( $_POST['specialty'] ) ? sanitize_text_field( wp_unslash( $_POST['specialty'] ) ) : '',
        'years_experience' => isset( $_POST['years_experience'] ) ? absint( $_POST['years_experience'] ) : 0,
        'bio'              => isset( $_POST['bio'] ) ? sanitize_textarea_field( wp_unslash( $_POST['bio'] ) ) : '',
        'rate_amount'      => isset( $_POST['rate_amount'] ) ? (float) $_POST['rate_amount'] : 0,
    );
}

/**
 * What's wrong with an application's details, in words for the person
 * applying, or '' when they're fine.
 */
function kounselia_professional_application_problem( $fields ) {
    if ( '' === $fields['title'] ) {
        return 'Please enter your professional title.';
    }
    if ( kounselia_title_is_bare_honorific( $fields['title'] ) ) {
        return 'Your professional title should say what you do, e.g. "Licensed Clinical Psychologist" — not just an honorific like "Dr".';
    }
    if ( $fields['rate_amount'] <= 0 ) {
        return 'Please enter your rate per session.';
    }
    return '';
}

/**
 * Files an application (or a fresh one after a rejection) for a user,
 * stores its documents, and tells the applicant and the admins. Shared
 * by the website's apply form and the app.
 *
 * @param int        $user_id
 * @param array      $fields      From kounselia_professional_application_fields_from_post().
 * @param array      $license_doc A $_FILES-style entry (required).
 * @param array|null $id_doc      A $_FILES-style entry, or null.
 * @return int|WP_Error The application's id.
 */
function kounselia_submit_professional_application( $user_id, $fields, $license_doc, $id_doc = null ) {
    global $wpdb;
    $table = $wpdb->prefix . 'kounselia_professionals';

    $existing = kounselia_get_professional_application( $user_id );
    if ( $existing && 'rejected' !== $existing->status ) {
        return new WP_Error( 'kounselia_apply', 'You already have an application on file.' );
    }

    $now  = current_time( 'mysql' );
    $data = array(
        'user_id'           => $user_id,
        'title'             => $fields['title'],
        'license_number'    => $fields['license_number'],
        'specialty'         => $fields['specialty'],
        'years_experience'  => $fields['years_experience'] ?: null,
        'bio'               => $fields['bio'],
        'rate_amount'       => $fields['rate_amount'],
        'rate_currency'     => 'NGN',
        'status'            => 'pending',
        'rejection_reason'  => null,
        'submitted_at'      => $now,
        'updated_at'        => $now,
    );

    if ( $existing ) {
        $wpdb->update( $table, $data, array( 'id' => $existing->id ) );
        $professional_id = (int) $existing->id;
        kounselia_delete_professional_documents( $professional_id ); // Reapplying — old rejected-round files shouldn't linger.
    } else {
        $data['created_at'] = $now;
        $wpdb->insert( $table, $data );
        $professional_id = (int) $wpdb->insert_id;
    }

    kounselia_store_professional_document( $professional_id, $license_doc, 'license' );
    if ( $id_doc ) {
        kounselia_store_professional_document( $professional_id, $id_doc, 'id' );
    }

    $user = get_userdata( $user_id );
    if ( $user && function_exists( 'kounselia_send_html_email' ) ) {
        $lang = kounselia_mail_lang( $user );
        kounselia_send_html_email(
            $user->user_email,
            kounselia_t( 'mail.apply.subject', array(), $lang ),
            kounselia_t( 'mail.apply.headline', array(), $lang ),
            '<p>' . esc_html( kounselia_t( 'mail.apply.body', array(), $lang ) ) . '</p>',
            null,
            null,
            array( 'lang' => $lang )
        );
    }
    kounselia_notify_admins_new_professional_application( $professional_id );

    return $professional_id;
}

function kounselia_ajax_apply_professional() {
    kounselia_verify_nonce();

    if ( kounselia_honeypot_tripped() ) {
        wp_send_json_error( array( 'message' => 'Something went wrong, please try again.' ), 400 );
    }

    if ( kounselia_rate_limited( 'apply_professional', 3, 3600 ) ) {
        wp_send_json_error( array( 'message' => 'Too many attempts. Please try again later.' ), 429 );
    }

    $fields  = kounselia_professional_application_fields_from_post();
    $problem = kounselia_professional_application_problem( $fields );
    if ( '' !== $problem ) {
        wp_send_json_error( array( 'message' => $problem ), 400 );
    }

    // Resolve the account: the signed-in user, or create one from the
    // credentials fields (same path a regular sign-up takes).
    if ( is_user_logged_in() ) {
        $user_id = get_current_user_id();
    } else {
        $email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
        $name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

        if ( empty( $email ) || empty( $password ) || empty( $name ) ) {
            wp_send_json_error( array( 'message' => 'Please fill in your name, email, and password.' ), 400 );
        }
        if ( ! is_email( $email ) ) {
            wp_send_json_error( array( 'message' => 'Please enter a valid email address.' ), 400 );
        }
        if ( email_exists( $email ) || username_exists( $email ) ) {
            wp_send_json_error( array( 'message' => 'That email is already registered. Please sign in first, then apply.' ), 400 );
        }

        $user_id = wp_create_user( $email, $password, $email );
        if ( is_wp_error( $user_id ) ) {
            wp_send_json_error( array( 'message' => 'Something went wrong creating your account.' ), 500 );
        }
        wp_update_user( array( 'ID' => $user_id, 'display_name' => $name, 'first_name' => explode( ' ', trim( $name ) )[0] ) );
        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id, true, is_ssl() );
    }

    $existing = kounselia_get_professional_application( $user_id );
    if ( $existing && 'rejected' !== $existing->status ) {
        wp_send_json_error( array( 'message' => 'You already have an application on file.' ), 400 );
    }

    if ( empty( $_FILES['license_doc'] ) || ! isset( $_FILES['license_doc']['error'] ) || UPLOAD_ERR_OK !== $_FILES['license_doc']['error'] ) {
        wp_send_json_error( array( 'message' => 'Please upload a license or credential document.' ), 400 );
    }

    $id_doc = ( ! empty( $_FILES['id_doc'] ) && isset( $_FILES['id_doc']['error'] ) && UPLOAD_ERR_NO_FILE !== $_FILES['id_doc']['error'] ) ? $_FILES['id_doc'] : null;
    $result = kounselia_submit_professional_application( $user_id, $fields, $_FILES['license_doc'], $id_doc );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
    }

    wp_send_json_success( array(
        'message'  => 'Application submitted. We will review it and email you.',
        'redirect' => '/pro-dashboard.php',
        'nonce'    => wp_create_nonce( 'kounselia_auth' ),
    ) );
}
add_action( 'wp_ajax_kounselia_apply_professional', 'kounselia_ajax_apply_professional' );
add_action( 'wp_ajax_nopriv_kounselia_apply_professional', 'kounselia_ajax_apply_professional' );

function kounselia_notify_admins_new_professional_application( $professional_id ) {
    if ( ! function_exists( 'kounselia_send_html_email' ) ) {
        return;
    }

    $admins = get_users( array(
        'role__in' => array( 'administrator', 'kounselia_staff' ),
        'fields'   => array( 'user_email' ),
    ) );
    $emails = array_filter( array_unique( wp_list_pluck( $admins, 'user_email' ) ) );
    if ( empty( $emails ) ) {
        return;
    }

    $site_url = function_exists( 'home_url' ) ? home_url() : ( 'https://' . $_SERVER['SERVER_NAME'] );
    $review_url = rtrim( $site_url, '/' ) . '/portal/admin/pages/professionals.php';

    foreach ( $emails as $email ) {
        kounselia_send_html_email(
            $email,
            'New professional application to review',
            'New application',
            '<p>A new professional application (#' . (int) $professional_id . ') is waiting for review.</p>',
            'Review application',
            $review_url,
            array( 'lang' => 'en' ) // Staff alert: always English.
        );
    }
}

/* -------------------------------------------------------------------------
 * ADMIN REVIEW
 * ---------------------------------------------------------------------- */

function kounselia_ajax_admin_review_professional() {
    check_ajax_referer( 'kounselia_admin_nonce', 'nonce' );

    if ( ! kounselia_admin_can( 'professionals' ) ) {
        kounselia_send_pure_json_error( array( 'message' => 'Unauthorized' ), 403 );
    }

    $professional_id = isset( $_POST['professional_id'] ) ? absint( $_POST['professional_id'] ) : 0;
    $decision         = isset( $_POST['decision'] ) ? sanitize_key( $_POST['decision'] ) : '';

    if ( ! $professional_id || ! in_array( $decision, array( 'approve', 'reject' ), true ) ) {
        kounselia_send_pure_json_error( array( 'message' => 'Invalid request' ), 400 );
    }

    global $wpdb;
    $table = $wpdb->prefix . 'kounselia_professionals';
    $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $professional_id ) );

    if ( ! $row ) {
        kounselia_send_pure_json_error( array( 'message' => 'Application not found' ), 404 );
    }

    $now  = current_time( 'mysql' );
    $user = get_userdata( $row->user_id );

    if ( 'approve' === $decision ) {
        $wpdb->update( $table, array(
            'status'            => 'verified',
            'rejection_reason'  => null,
            'reviewed_at'       => $now,
            'reviewed_by'       => get_current_user_id(),
            'updated_at'        => $now,
        ), array( 'id' => $professional_id ) );

        if ( $user ) {
            $user->add_role( 'kounselia_professional' ); // Additive — they keep whatever role they already had.
            if ( function_exists( 'kounselia_send_html_email' ) ) {
                $lang = kounselia_mail_lang( $user );
                kounselia_send_html_email(
                    $user->user_email,
                    kounselia_t( 'mail.apply.approved_subject', array(), $lang ),
                    kounselia_t( 'mail.apply.approved_headline', array(), $lang ),
                    '<p>' . esc_html( kounselia_t( 'mail.apply.approved_body', array(), $lang ) ) . '</p>',
                    null,
                    null,
                    array( 'lang' => $lang )
                );
            }
        }
        kounselia_admin_log( 'approve_professional', 'professional', $professional_id );
    } else {
        $reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';

        $wpdb->update( $table, array(
            'status'            => 'rejected',
            'rejection_reason'  => $reason,
            'reviewed_at'       => $now,
            'reviewed_by'       => get_current_user_id(),
            'updated_at'        => $now,
        ), array( 'id' => $professional_id ) );

        if ( $user ) {
            $user->remove_role( 'kounselia_professional' );
            if ( function_exists( 'kounselia_send_html_email' ) ) {
                $lang        = kounselia_mail_lang( $user );
                $reason_html = $reason ? '<p><strong>' . esc_html( kounselia_t( 'mail.apply.reason_label', array(), $lang ) ) . '</strong> ' . esc_html( $reason ) . '</p>' : '';
                kounselia_send_html_email(
                    $user->user_email,
                    kounselia_t( 'mail.apply.rejected_subject', array(), $lang ),
                    kounselia_t( 'mail.apply.rejected_headline', array(), $lang ),
                    '<p>' . esc_html( kounselia_t( 'mail.apply.rejected_body', array(), $lang ) ) . '</p>' . $reason_html . '<p>' . esc_html( kounselia_t( 'mail.apply.rejected_reapply', array(), $lang ) ) . '</p>',
                    null,
                    null,
                    array( 'lang' => $lang )
                );
            }
        }
        kounselia_admin_log( 'reject_professional', 'professional', $professional_id );
    }

    kounselia_send_pure_json_success( array( 'status' => 'approve' === $decision ? 'verified' : 'rejected' ) );
}
add_action( 'wp_ajax_kounselia_admin_review_professional', 'kounselia_ajax_admin_review_professional' );

/* -------------------------------------------------------------------------
 * PROFESSIONAL PROFILE — Slice 2
 *
 * A professional manages their own listing (title, specialty, bio) and
 * — this is the important one — sets their own rate, from the moment
 * they apply, not just once verified: editing it while pending just
 * means it's ready to go live the instant they're approved, it's not
 * shown to clients either way until then. Admin can see it (the review
 * page already reads straight from this same table, so any edit shows
 * up there automatically) but never writes it for them. License number
 * is intentionally left out of what can be edited here: changing the
 * credential you were verified against is a re-verification event, not
 * a profile tweak.
 * ---------------------------------------------------------------------- */

function kounselia_ajax_update_professional_profile() {
    kounselia_verify_nonce();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in first.' ), 401 );
    }

    $user_id     = get_current_user_id();
    $application = kounselia_get_professional_application( $user_id );

    if ( ! $application ) {
        wp_send_json_error( array( 'message' => 'You do not have a professional application on file.' ), 403 );
    }

    $title     = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
    $specialty = isset( $_POST['specialty'] ) ? sanitize_text_field( wp_unslash( $_POST['specialty'] ) ) : '';
    $years     = isset( $_POST['years_experience'] ) ? absint( $_POST['years_experience'] ) : 0;
    $bio       = isset( $_POST['bio'] ) ? sanitize_textarea_field( wp_unslash( $_POST['bio'] ) ) : '';
    $rate      = isset( $_POST['rate_amount'] ) ? (float) $_POST['rate_amount'] : 0;
    $free      = isset( $_POST['free_sessions_per_client'] ) && '' !== $_POST['free_sessions_per_client'] && function_exists( 'kounselia_free_sessions_clean' )
        ? kounselia_free_sessions_clean( $_POST['free_sessions_per_client'] )
        : (int) $application->free_sessions_per_client;
    $all_free  = defined( 'KOUNSELIA_FREE_ALWAYS' ) && $free >= KOUNSELIA_FREE_ALWAYS;

    if ( '' === $title ) {
        wp_send_json_error( array( 'message' => 'Please enter your professional title.' ), 400 );
    }
    if ( kounselia_title_is_bare_honorific( $title ) ) {
        wp_send_json_error( array( 'message' => 'Your professional title should say what you do, e.g. "Licensed Clinical Psychologist" — not just an honorific like "Dr".' ), 400 );
    }
    // A rate is needed unless every session is free.
    if ( $rate <= 0 && ! $all_free ) {
        wp_send_json_error( array( 'message' => 'Please enter a rate greater than zero, or make every session free.' ), 400 );
    }

    global $wpdb;
    $wpdb->update( $wpdb->prefix . 'kounselia_professionals', array(
        'title'            => $title,
        'specialty'        => $specialty,
        'years_experience' => $years ?: null,
        'bio'              => $bio,
        'rate_amount'      => $rate > 0 ? $rate : null,
        'free_sessions_per_client' => $free,
        'updated_at'       => current_time( 'mysql' ),
    ), array( 'id' => $application->id ) );

    wp_send_json_success( array( 'message' => 'Profile updated.' ) );
}
add_action( 'wp_ajax_kounselia_update_professional_profile', 'kounselia_ajax_update_professional_profile' );

/* -------------------------------------------------------------------------
 * ADDITIONAL DOCUMENTS
 *
 * The application only ever collected two files (license + optional ID).
 * A professional often needs to add more later — a certificate, a second
 * form of ID a reviewer asked for — without re-submitting the whole
 * application. These just add to the same private document store.
 * ---------------------------------------------------------------------- */

function kounselia_ajax_upload_professional_document() {
    kounselia_verify_nonce();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in first.' ), 401 );
    }
    if ( kounselia_rate_limited( 'upload_professional_document', 10, 3600 ) ) {
        wp_send_json_error( array( 'message' => 'Too many uploads. Please try again later.' ), 429 );
    }

    $application = kounselia_get_professional_application( get_current_user_id() );
    if ( ! $application ) {
        wp_send_json_error( array( 'message' => 'You do not have a professional application on file.' ), 403 );
    }

    global $wpdb;
    $existing_count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_professional_documents WHERE professional_id = %d",
        $application->id
    ) );
    if ( $existing_count >= 10 ) {
        wp_send_json_error( array( 'message' => 'You have reached the maximum of 10 documents. Remove one before adding another.' ), 400 );
    }

    $allowed_types = array( 'license', 'id', 'certificate', 'other' );
    $doc_type      = isset( $_POST['doc_type'] ) ? sanitize_key( $_POST['doc_type'] ) : 'other';
    if ( ! in_array( $doc_type, $allowed_types, true ) ) {
        $doc_type = 'other';
    }

    if ( empty( $_FILES['document'] ) ) {
        wp_send_json_error( array( 'message' => 'Please choose a file to upload.' ), 400 );
    }

    $doc_id = kounselia_store_professional_document( $application->id, $_FILES['document'], $doc_type );
    if ( ! $doc_id ) {
        wp_send_json_error( array( 'message' => 'Could not upload that file. Please use a PDF, JPG, or PNG under 8MB.' ), 400 );
    }

    $doc = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}kounselia_professional_documents WHERE id = %d", $doc_id ) );

    wp_send_json_success( array(
        'message'  => 'Document uploaded.',
        'document' => array(
            'id'                => $doc_id,
            'doc_type'          => $doc_type,
            'original_filename' => $doc->original_filename,
            'url'               => kounselia_professional_document_url( $doc_id ),
        ),
    ) );
}
add_action( 'wp_ajax_kounselia_upload_professional_document', 'kounselia_ajax_upload_professional_document' );

function kounselia_ajax_delete_professional_document() {
    kounselia_verify_nonce();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in first.' ), 401 );
    }

    $application = kounselia_get_professional_application( get_current_user_id() );
    if ( ! $application ) {
        wp_send_json_error( array( 'message' => 'You do not have a professional application on file.' ), 403 );
    }

    $doc_id = isset( $_POST['doc_id'] ) ? absint( $_POST['doc_id'] ) : 0;

    global $wpdb;
    $doc = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}kounselia_professional_documents WHERE id = %d AND professional_id = %d",
        $doc_id,
        $application->id
    ) );
    if ( ! $doc ) {
        wp_send_json_error( array( 'message' => 'Document not found.' ), 404 );
    }

    $path = trailingslashit( kounselia_professional_docs_dir() ) . $doc->stored_filename;
    if ( file_exists( $path ) ) {
        @unlink( $path );
    }
    $wpdb->delete( $wpdb->prefix . 'kounselia_professional_documents', array( 'id' => $doc_id ) );

    wp_send_json_success( array( 'message' => 'Document removed.' ) );
}
add_action( 'wp_ajax_kounselia_delete_professional_document', 'kounselia_ajax_delete_professional_document' );
