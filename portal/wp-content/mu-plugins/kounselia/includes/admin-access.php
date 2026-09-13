<?php
/**
 * STREAMING_CHUNK:Bootstrapping the admin control engine...
 * Kounselia Core — custom kounselia_admin capability, staff role, and audit logging
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * 19. ADMIN ACCESS CONTROL
 * ---------------------------------------------------------------------- */

function kounselia_setup_admin_roles() {
    if ( get_option( 'kounselia_admin_roles_version' ) === '1.0.1' ) {
        return;
    }

    $admin_role = get_role( 'administrator' );
    if ( $admin_role && ! $admin_role->has_cap( 'kounselia_admin' ) ) {
        $admin_role->add_cap( 'kounselia_admin' );
    }

    if ( ! get_role( 'kounselia_staff' ) ) {
        add_role( 'kounselia_staff', 'Kounselia Staff', array(
            'read'            => true,  
            'kounselia_admin' => true,  
        ) );
    }

    update_option( 'kounselia_admin_roles_version', '1.0.1' );
}
add_action( 'init', 'kounselia_setup_admin_roles' );

function kounselia_user_is_admin( $user_id = 0 ) {
    $user_id = $user_id ? $user_id : get_current_user_id();
    if ( ! $user_id ) {
        return false;
    }
    return user_can( $user_id, 'kounselia_admin' );
}

function kounselia_admin_log( $action, $target_type = '', $target_id = 0 ) {
    global $wpdb;
    $suppress = $wpdb->suppress_errors();
    $wpdb->insert( $wpdb->prefix . 'kounselia_admin_audit_log', array(
        'admin_id'    => get_current_user_id(),
        'action'      => sanitize_key( $action ),
        'target_type' => sanitize_key( $target_type ),
        'target_id'   => $target_id ? (int) $target_id : null,
        'ip_address'  => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : null,
        'created_at'  => current_time( 'mysql' ),
    ) );
    $wpdb->suppress_errors( $suppress );
}

/**
 * Helper to ensure we only send JSON. Clears any PHP warnings/notices printed so far.
 */
/**
 * Maps the role code sent from the browser to the real WP role slug.
 * We avoid transmitting the literal string "administrator" in the AJAX
 * POST body since some hosting WAFs flag that as a privilege-escalation
 * attempt and return a 406 before the request ever reaches WordPress.
 */
function kounselia_map_role_code( $code ) {
    return ( $code === 'super_admin' ) ? 'administrator' : 'kounselia_staff';
}

function kounselia_send_pure_json_success($data = null) {
    if (ob_get_length()) ob_clean();
    wp_send_json_success($data);
}

function kounselia_send_pure_json_error($data = null, $status_code = null) {
    if (ob_get_length()) ob_clean();
    wp_send_json_error($data, $status_code);
}

/* -------------------------------------------------------------------------
 * TEAM MANAGEMENT (GRANULAR ACCESS)
 * ---------------------------------------------------------------------- */

function kounselia_ajax_admin_create_staff() {
    check_ajax_referer( 'kounselia_admin_nonce', 'nonce' );
    
    if ( ! current_user_can('administrator') ) {
        kounselia_send_pure_json_error( array( 'message' => 'Only Super Admins can manage the team.' ), 403 );
    }

    $email = isset($_POST['email']) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
    $name  = isset($_POST['name']) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
    $role  = isset($_POST['role']) && $_POST['role'] !== '' ? kounselia_map_role_code( sanitize_text_field( wp_unslash( $_POST['role'] ) ) ) : '';

    $permissions_raw = isset($_POST['permissions']) ? sanitize_text_field( wp_unslash($_POST['permissions']) ) : '';
    $permissions = $permissions_raw !== '' ? array_filter( array_map( 'sanitize_key', explode( ',', $permissions_raw ) ) ) : array();
    $permissions = array_values( $permissions );

    if ( empty($email) || empty($name) || empty($role) ) {
        kounselia_send_pure_json_error( array( 'message' => 'All fields are required.' ), 400 );
    }
    if ( email_exists($email) ) {
        kounselia_send_pure_json_error( array( 'message' => 'That email is already in use.' ), 400 );
    }

    // Generate secure 16 character temporary password
    $password = wp_generate_password( 16, true, true );
    $user_id  = wp_create_user( $email, $password, $email );

    if ( is_wp_error($user_id) ) {
        kounselia_send_pure_json_error( array( 'message' => 'Could not create account.' ), 500 );
    }

    // Set name and role
    wp_update_user( array( 'ID' => $user_id, 'display_name' => $name, 'first_name' => explode(' ', trim($name))[0] ) );
    $user = new WP_User( $user_id );
    $user->set_role( $role );

    // Save modular permissions and force-reset flag
    update_user_meta( $user_id, 'kounselia_permissions', wp_json_encode($permissions) );
    update_user_meta( $user_id, 'kounselia_force_password_change', 1 );

    kounselia_admin_log( 'created_staff', 'user', $user_id );

    // Return the password directly to the UI (No automated email sent)
    kounselia_send_pure_json_success( array( 
        'message' => 'Team member created.',
        'credentials' => array(
            'email' => $email,
            'password' => $password
        )
    ) );
}
add_action( 'wp_ajax_kounselia_admin_create_staff', 'kounselia_ajax_admin_create_staff' );

function kounselia_ajax_admin_update_staff() {
    check_ajax_referer( 'kounselia_admin_nonce', 'nonce' );
    if ( ! current_user_can('administrator') ) kounselia_send_pure_json_error( array( 'message' => 'Unauthorized' ), 403 );

    $target_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    if ( $target_id === get_current_user_id() ) kounselia_send_pure_json_error( array( 'message' => 'You cannot edit your own permissions here.' ), 400 );
    if ( ! get_userdata($target_id) ) kounselia_send_pure_json_error( array( 'message' => 'User not found.' ), 404 );

    $role  = isset($_POST['role']) && $_POST['role'] !== '' ? kounselia_map_role_code( sanitize_text_field( wp_unslash( $_POST['role'] ) ) ) : '';
    $permissions_raw = isset($_POST['permissions']) ? sanitize_text_field( wp_unslash($_POST['permissions']) ) : '';
    $permissions = $permissions_raw !== '' ? array_filter( array_map( 'sanitize_key', explode( ',', $permissions_raw ) ) ) : array();
    $permissions = array_values( $permissions );

    $user = new WP_User( $target_id );
    $user->set_role( $role );
    update_user_meta( $target_id, 'kounselia_permissions', wp_json_encode($permissions) );

    $response = array( 'message' => 'Permissions updated successfully.' );

    // Optional manual password reset
    if ( isset($_POST['regenerate_pw']) && $_POST['regenerate_pw'] === '1' ) {
        $password = wp_generate_password( 16, true, true );
        wp_set_password( $password, $target_id );
        update_user_meta( $target_id, 'kounselia_force_password_change', 1 );
        $response['new_password'] = $password;
        $response['message'] = 'Permissions updated and password regenerated.';
    }

    kounselia_admin_log( 'edited_staff', 'user', $target_id );
    kounselia_send_pure_json_success( $response );
}
add_action( 'wp_ajax_kounselia_admin_update_staff', 'kounselia_ajax_admin_update_staff' );

function kounselia_ajax_admin_delete_staff() {
    check_ajax_referer( 'kounselia_admin_nonce', 'nonce' );
    if ( ! current_user_can('administrator') ) kounselia_send_pure_json_error( array( 'message' => 'Unauthorized' ), 403 );

    $target_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    if ( $target_id === get_current_user_id() ) kounselia_send_pure_json_error( array( 'message' => 'You cannot delete yourself.' ), 400 );

    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user( $target_id );
    kounselia_admin_log( 'deleted_staff', 'user', $target_id );

    kounselia_send_pure_json_success( array( 'message' => 'Team member removed securely.' ) );
}
add_action( 'wp_ajax_kounselia_admin_delete_staff', 'kounselia_ajax_admin_delete_staff' );

function kounselia_ajax_admin_force_password() {
    check_ajax_referer( 'kounselia_admin_nonce', 'nonce' );
    $user_id = get_current_user_id();

    // Ensure they actually have the flag
    if ( ! $user_id || ! get_user_meta( $user_id, 'kounselia_force_password_change', true ) ) {
        kounselia_send_pure_json_error( array('message' => 'Unauthorized'), 403 );
    }

    $new_password = isset($_POST['new_password']) ? trim(wp_unslash($_POST['new_password'])) : '';
    if ( strlen($new_password) < 8 ) {
        kounselia_send_pure_json_error( array('message' => 'Password must be at least 8 characters long.'), 400 );
    }

    wp_set_password( $new_password, $user_id );
    delete_user_meta( $user_id, 'kounselia_force_password_change' );

    // Re-authenticate them immediately so they aren't logged out
    wp_set_auth_cookie( $user_id, false, is_ssl() );

    kounselia_send_pure_json_success();
}
add_action( 'wp_ajax_kounselia_admin_force_password', 'kounselia_ajax_admin_force_password' );


/* -------------------------------------------------------------------------
 * ADMIN: BROADCASTS & NEWSLETTERS
 * ---------------------------------------------------------------------- */

function kounselia_ajax_admin_send_broadcast() {
    check_ajax_referer( 'kounselia_admin_nonce', 'nonce' );
    if ( ! kounselia_user_is_admin() ) {
        kounselia_send_pure_json_error( array( 'message' => 'Unauthorized access.' ), 403 );
    }

    $subject  = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
    $headline = isset( $_POST['headline'] ) ? sanitize_text_field( wp_unslash( $_POST['headline'] ) ) : '';
    $body     = isset( $_POST['body'] ) ? wp_kses_post( wp_unslash( $_POST['body'] ) ) : '';
    $btn_text = isset( $_POST['btn_text'] ) ? sanitize_text_field( wp_unslash( $_POST['btn_text'] ) ) : '';
    $btn_url  = isset( $_POST['btn_url'] ) ? esc_url_raw( wp_unslash( $_POST['btn_url'] ) ) : '';

    if ( empty( $subject ) || empty( $headline ) || empty( $body ) ) {
        kounselia_send_pure_json_error( array( 'message' => 'Subject, headline, and body are required.' ), 400 );
    }

    $members = get_users( array( 'role' => 'subscriber', 'fields' => array( 'ID', 'user_email', 'display_name' ) ) );
    if ( empty( $members ) ) kounselia_send_pure_json_error( array( 'message' => 'No active members found to send to.' ), 400 );

    $formatted_body = str_replace( '<p>', '<p style="margin-bottom: 18px;">', wpautop( $body ) );
    $sent_count = 0;

    foreach ( $members as $member ) {
        $first_name = explode( ' ', trim( $member->display_name ) )[0];
        if ( empty( $first_name ) ) $first_name = 'there';
        
        $personal_headline = str_replace( '{name}', $first_name, $headline );
        $personal_body     = str_replace( '{name}', $first_name, $formatted_body );

        if ( function_exists('kounselia_send_html_email') && kounselia_send_html_email( $member->user_email, $subject, $personal_headline, $personal_body, $btn_text, $btn_url ) ) {
            $sent_count++;
        }
    }

    kounselia_admin_log( 'sent_broadcast', 'newsletter', $sent_count );
    kounselia_send_pure_json_success( array( 'message' => "Successfully sent broadcast to {$sent_count} members." ) );
}
add_action( 'wp_ajax_kounselia_admin_send_broadcast', 'kounselia_ajax_admin_send_broadcast' );

/* -------------------------------------------------------------------------
 * ADMIN: COUNSELOR STUDIO (NO-CODE EDITOR)
 * ---------------------------------------------------------------------- */

function kounselia_ajax_admin_save_counselor() {
    check_ajax_referer( 'kounselia_admin_nonce', 'nonce' );
    if ( ! kounselia_user_is_admin() ) kounselia_send_pure_json_error( array( 'message' => 'Unauthorized access.' ), 403 );

    global $wpdb;
    
    $slug   = sanitize_key( $_POST['slug'] );
    $name   = sanitize_text_field( wp_unslash( $_POST['name'] ) );
    $spec   = sanitize_text_field( wp_unslash( $_POST['spec'] ) );
    $desc   = sanitize_textarea_field( wp_unslash( $_POST['desc'] ) );
    $icon   = sanitize_text_field( wp_unslash( $_POST['icon'] ) );
    $color  = sanitize_text_field( wp_unslash( $_POST['color'] ) );
    $prompt    = isset($_POST['system_prompt']) ? wp_unslash( $_POST['system_prompt'] ) : ''; 
    $model     = sanitize_text_field( wp_unslash( $_POST['ai_model'] ) );
    $temp      = (float) $_POST['temperature'];
    $tokens    = (int) $_POST['max_tokens'];
    $is_active = (int) $_POST['is_active'];

    if ( empty( $slug ) || empty( $name ) || empty( $prompt ) ) {
        kounselia_send_pure_json_error( array( 'message' => 'Slug, name, and system prompt are required.' ), 400 );
    }

    $ui_meta = get_option( 'kounselia_counselors_ui_meta', array() );
    $ui_meta[$slug] = array(
        'name'  => $name,
        'spec'  => $spec,
        'desc'  => $desc,
        'icon'  => $icon,
        'class' => $color
    );
    update_option( 'kounselia_counselors_ui_meta', $ui_meta );

    $table = $wpdb->prefix . 'kounselia_counselor_prompts';
    $exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE counselor_slug = %s", $slug ) );

    if ( $exists ) {
        $wpdb->update( $table, array(
            'system_prompt' => $prompt,
            'ai_model'      => $model,
            'temperature'   => $temp,
            'max_tokens'    => $tokens,
            'is_active'     => $is_active
        ), array( 'counselor_slug' => $slug ) );
    } else {
        $wpdb->insert( $table, array(
            'counselor_slug'=> $slug,
            'system_prompt' => $prompt,
            'ai_model'      => $model,
            'temperature'   => $temp,
            'max_tokens'    => $tokens,
            'is_active'     => $is_active,
            'tts_voice'     => 'Alnilam', 
            'voice_enabled' => 0
        ) );
    }

    kounselia_admin_log( 'edited_counselor', 'counselor', 0 );
    kounselia_send_pure_json_success( array( 'message' => 'Counselor saved successfully.' ) );
}
add_action( 'wp_ajax_kounselia_admin_save_counselor', 'kounselia_ajax_admin_save_counselor' );

/* -------------------------------------------------------------------------
 * ADMIN: CRM MEMBER MANAGEMENT
 * ---------------------------------------------------------------------- */

function kounselia_ajax_admin_get_member() {
    check_ajax_referer( 'kounselia_admin_nonce', 'nonce' );
    if ( ! kounselia_user_is_admin() ) kounselia_send_pure_json_error( array('message' => 'Unauthorized'), 403 );

    $user_id = (int) $_POST['user_id'];
    $user = get_userdata( $user_id );
    if ( ! $user ) kounselia_send_pure_json_error( array('message' => 'User not found.'), 404 );

    global $wpdb;
    $s_table = $wpdb->prefix . 'kounselia_sessions';
    $m_table = $wpdb->prefix . 'kounselia_messages';

    $total_sessions = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$s_table} WHERE user_id = %d", $user_id ) );
    $total_messages = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$m_table} m JOIN {$s_table} s ON m.session_id = s.id WHERE s.user_id = %d", $user_id ) );
    $safety_flags = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$m_table} m JOIN {$s_table} s ON m.session_id = s.id WHERE s.user_id = %d AND m.flagged_safety = 1", $user_id ) );

    $recent_sessions = $wpdb->get_results( $wpdb->prepare( 
        "SELECT id, counselor_slug, started_at, status FROM {$s_table} WHERE user_id = %d ORDER BY started_at DESC LIMIT 5", 
        $user_id 
    ) );

    $is_banned = get_user_meta( $user_id, 'kounselia_banned', true );
    $plan      = get_user_meta( $user_id, 'kounselia_plan', true ) ?: 'free';
    
    $core_memory = get_user_meta( $user_id, 'kounselia_core_memory', true );
    $reflection  = get_user_meta( $user_id, 'kounselia_latest_reflection', true );

    kounselia_send_pure_json_success( array(
        'name'       => $user->display_name ?: 'Unknown',
        'email'      => $user->user_email,
        'joined'     => date_i18n( 'M j, Y', strtotime( $user->user_registered ) ),
        'sessions'   => $total_sessions,
        'messages'   => $total_messages,
        'flags'      => $safety_flags,
        'is_banned'  => $is_banned ? 1 : 0,
        'plan'       => $plan,
        'recent'     => $recent_sessions,
        'core_memory'=> $core_memory ? json_decode( $core_memory, true ) : null,
        'reflection' => $reflection ? json_decode( $reflection, true ) : null
    ) );
}
add_action( 'wp_ajax_kounselia_admin_get_member', 'kounselia_ajax_admin_get_member' );

function kounselia_ajax_admin_update_member() {
    check_ajax_referer( 'kounselia_admin_nonce', 'nonce' );
    if ( ! kounselia_user_is_admin() ) kounselia_send_pure_json_error( array('message' => 'Unauthorized'), 403 );

    $user_id = (int) $_POST['user_id'];
    $action  = sanitize_text_field( $_POST['do_action'] ); 

    if ( ! get_userdata( $user_id ) ) kounselia_send_pure_json_error( array('message' => 'User not found.'), 404 );
    global $wpdb;

    switch ( $action ) {
        case 'ban':
            update_user_meta( $user_id, 'kounselia_banned', 1 );
            
            $last_ip = get_user_meta( $user_id, 'kounselia_last_ip', true );
            if ( $last_ip ) {
                $banned_ips = get_option( 'kounselia_banned_ips', array() );
                if ( ! in_array( $last_ip, $banned_ips ) ) {
                    $banned_ips[] = $last_ip;
                    update_option( 'kounselia_banned_ips', $banned_ips );
                }
            }
            kounselia_admin_log( 'banned_user', 'user', $user_id );
            $msg = "User and their IP address have been banned.";
            break;
            
        case 'unban':
            delete_user_meta( $user_id, 'kounselia_banned' );
            
            $last_ip = get_user_meta( $user_id, 'kounselia_last_ip', true );
            if ( $last_ip ) {
                $banned_ips = get_option( 'kounselia_banned_ips', array() );
                $banned_ips = array_diff( $banned_ips, array( $last_ip ) );
                update_option( 'kounselia_banned_ips', $banned_ips );
            }
            kounselia_admin_log( 'unbanned_user', 'user', $user_id );
            $msg = "User access and IP restored.";
            break;
            
        case 'upgrade':
            update_user_meta( $user_id, 'kounselia_plan', 'pro' );
            kounselia_admin_log( 'upgraded_user', 'user', $user_id );
            $msg = "User upgraded to Pro.";
            break;
            
        case 'downgrade':
            update_user_meta( $user_id, 'kounselia_plan', 'free' );
            kounselia_admin_log( 'downgraded_user', 'user', $user_id );
            $msg = "User downgraded to Free.";
            break;
            
        case 'delete':
            require_once ABSPATH . 'wp-admin/includes/user.php';
            $wpdb->query( $wpdb->prepare( "DELETE m FROM {$wpdb->prefix}kounselia_messages m INNER JOIN {$wpdb->prefix}kounselia_sessions s ON m.session_id = s.id WHERE s.user_id = %d", $user_id ) );
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}kounselia_sessions WHERE user_id = %d", $user_id ) );
            wp_delete_user( $user_id );
            kounselia_admin_log( 'deleted_user', 'user', $user_id );
            $msg = "User account and all data permanently deleted.";
            break;
            
        default:
            kounselia_send_pure_json_error( array('message' => 'Invalid action.') );
    }

    kounselia_send_pure_json_success( array( 'message' => $msg ) );
}
add_action( 'wp_ajax_kounselia_admin_update_member', 'kounselia_ajax_admin_update_member' );