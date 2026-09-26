<?php
/**
 * Kounselia Core — Newsletter & email CRM.
 *
 * The moving parts:
 *
 *   Contacts (kounselia_subscribers)
 *     Everyone we may email: website sign-ups AND members (linked by
 *     user_id; new members are added automatically when the
 *     "auto_subscribe_members" setting is on). Each has two consents —
 *     the newsletter, and "new blog post" emails — plus a master status.
 *
 *   Segments (kounselia_segments)
 *     Saved audience rules ("Pro members inactive for 30 days"), turned
 *     into SQL by kounselia_newsletter_segment_where().
 *
 *   Campaigns (kounselia_campaigns + kounselia_campaign_recipients)
 *     One email send. When it starts, the audience is resolved into one
 *     recipient row per contact (UNIQUE, so nobody gets it twice), and
 *     WP-Cron delivers them in small batches so a big list never times
 *     out the server. The admin screen also nudges a batch along every
 *     few seconds while it's open. Opens and clicks are tracked per
 *     recipient via /newsletter/.
 *
 * Every email carries a one-click unsubscribe link and header. An
 * unsubscribed contact is never selected by any audience.
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * SETTINGS
 * ---------------------------------------------------------------------- */

function kounselia_newsletter_settings() {
    $defaults = array(
        'double_optin'           => 0,
        'auto_subscribe_members' => 1,
        'batch_size'             => 40,
        'track_opens'            => 1,
        'track_clicks'           => 1,
        'footer_address'         => '',
        'welcome_enabled'        => 1,
        'welcome_subject'        => 'Welcome to Kounselia',
        'welcome_body'           => "Thank you for joining us, {first_name}.\n\nEvery so often we'll send you gentle, practical ideas for looking after your mind, and news about what we're building. You can change what you receive, or unsubscribe, from the link at the bottom of any email.",
    );
    $saved = get_option( 'kounselia_newsletter_settings', array() );
    return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
}

function kounselia_newsletter_lists() {
    return array(
        'newsletter' => 'Newsletter',
        'blog'       => 'New blog posts',
    );
}

/* -------------------------------------------------------------------------
 * CONTACTS
 * ---------------------------------------------------------------------- */

function kounselia_newsletter_table() {
    global $wpdb;
    return $wpdb->prefix . 'kounselia_subscribers';
}

function kounselia_newsletter_get_subscriber( $id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . kounselia_newsletter_table() . ' WHERE id = %d', $id ) );
}

function kounselia_newsletter_get_by_email( $email ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . kounselia_newsletter_table() . ' WHERE email = %s', strtolower( trim( $email ) ) ) );
}

function kounselia_newsletter_get_by_token( $token ) {
    global $wpdb;
    if ( ! is_string( $token ) || strlen( $token ) < 20 ) {
        return null;
    }
    return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . kounselia_newsletter_table() . ' WHERE token = %s', $token ) );
}

function kounselia_newsletter_get_by_user( $user_id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . kounselia_newsletter_table() . ' WHERE user_id = %d', $user_id ) );
}

/**
 * "VIP, lagos , Vip" -> "vip,lagos" (tags are stored as slugs).
 */
function kounselia_newsletter_clean_tags( $raw ) {
    $tags = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
    $tags = array_unique( array_filter( array_map( 'sanitize_title', $tags ) ) );
    return implode( ',', array_slice( array_values( $tags ), 0, 20 ) );
}

/**
 * Create or update a contact by email. $args: name, user_id, source,
 * status, list_newsletter, list_blog, add_tags, tags (replace).
 * Only the keys given are changed on an existing contact.
 * Returns the contact row, or WP_Error for an invalid email.
 */
function kounselia_newsletter_upsert( $email, $args = array() ) {
    global $wpdb;
    $email = strtolower( trim( sanitize_email( $email ) ) );
    if ( ! is_email( $email ) ) {
        return new WP_Error( 'invalid_email', 'Please enter a valid email address.' );
    }

    $table    = kounselia_newsletter_table();
    $now      = current_time( 'mysql' );
    $existing = kounselia_newsletter_get_by_email( $email );
    $row      = array( 'updated_at' => $now );

    if ( isset( $args['name'] ) && '' !== trim( $args['name'] ) ) {
        $row['name'] = mb_substr( sanitize_text_field( $args['name'] ), 0, 191 );
    }
    if ( isset( $args['user_id'] ) ) {
        $row['user_id'] = $args['user_id'] ? (int) $args['user_id'] : null;
    }
    if ( isset( $args['status'] ) && in_array( $args['status'], array( 'subscribed', 'pending', 'unsubscribed' ), true ) ) {
        $row['status'] = $args['status'];
        if ( 'subscribed' === $args['status'] ) {
            $row['confirmed_at']    = $now;
            $row['unsubscribed_at'] = null;
        } elseif ( 'unsubscribed' === $args['status'] ) {
            $row['unsubscribed_at'] = $now;
        }
    }
    foreach ( array( 'list_newsletter', 'list_blog' ) as $list ) {
        if ( isset( $args[ $list ] ) ) {
            $row[ $list ] = $args[ $list ] ? 1 : 0;
        }
    }
    if ( isset( $args['tags'] ) ) {
        $row['tags'] = kounselia_newsletter_clean_tags( $args['tags'] ) ?: null;
    }
    if ( ! empty( $args['add_tags'] ) ) {
        $current     = $existing ? (string) $existing->tags : '';
        $row['tags'] = kounselia_newsletter_clean_tags( $current . ',' . ( is_array( $args['add_tags'] ) ? implode( ',', $args['add_tags'] ) : $args['add_tags'] ) );
    }

    if ( $existing ) {
        $wpdb->update( $table, $row, array( 'id' => $existing->id ) );
        return kounselia_newsletter_get_subscriber( $existing->id );
    }

    $row = array_merge( array(
        'email'           => $email,
        'status'          => 'subscribed',
        'list_newsletter' => 1,
        'list_blog'       => 1,
        'source'          => isset( $args['source'] ) ? sanitize_key( $args['source'] ) : 'website',
        'token'           => wp_generate_password( 40, false ),
        'ip_address'      => isset( $args['ip'] ) ? $args['ip'] : null,
        'confirmed_at'    => ( ! isset( $args['status'] ) || 'subscribed' === $args['status'] ) ? $now : null,
        'created_at'      => isset( $args['created_at'] ) ? $args['created_at'] : $now,
    ), $row );
    $wpdb->insert( $table, $row );
    return kounselia_newsletter_get_subscriber( $wpdb->insert_id );
}

/**
 * A sign-up from the website form. Handles double opt-in (a
 * confirmation email first) when that setting is on.
 * Returns array( 'status' => ..., 'message' => ... ) or WP_Error.
 */
function kounselia_newsletter_subscribe_public( $email, $name = '', $source = 'website' ) {
    $settings = kounselia_newsletter_settings();
    $existing = kounselia_newsletter_get_by_email( $email );

    if ( $existing && 'subscribed' === $existing->status && $existing->list_newsletter ) {
        return array( 'status' => 'subscribed', 'message' => "You're already on the list. Thank you for being here." );
    }

    $user    = get_user_by( 'email', $email );
    $pending = ! empty( $settings['double_optin'] );
    $sub     = kounselia_newsletter_upsert( $email, array(
        'name'            => $name,
        'user_id'         => $user ? $user->ID : ( $existing ? $existing->user_id : null ),
        'source'          => $source,
        'status'          => $pending ? 'pending' : 'subscribed',
        'list_newsletter' => 1,
        'list_blog'       => 1,
        'ip'              => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : null,
    ) );
    if ( is_wp_error( $sub ) ) {
        return $sub;
    }

    if ( $pending ) {
        kounselia_newsletter_send_confirmation( $sub );
        return array( 'status' => 'pending', 'message' => 'Almost there — please check your inbox and tap the link to confirm.' );
    }

    kounselia_newsletter_send_welcome( $sub );
    return array( 'status' => 'subscribed', 'message' => "You're subscribed. Welcome — we're glad you're here." );
}

function kounselia_newsletter_first_name( $sub ) {
    $name = '';
    // Members: their account is the up-to-date source (the contact row
    // was created mid-registration, before the name was filled in).
    if ( ! empty( $sub->user_id ) ) {
        $user = get_userdata( $sub->user_id );
        $name = $user ? ( $user->first_name ?: $user->display_name ) : '';
    }
    if ( '' === trim( (string) $name ) || false !== strpos( $name, '@' ) ) {
        $name = (string) $sub->name;
    }
    $first = trim( explode( ' ', trim( $name ) )[0] );
    // Never greet someone by their email address.
    return ( '' !== $first && false === strpos( $first, '@' ) ) ? $first : 'there';
}

function kounselia_newsletter_manage_url( $sub, $action = 'preferences' ) {
    return kounselia_site_url( '/newsletter/?a=' . rawurlencode( $action ) . '&t=' . rawurlencode( $sub->token ) );
}

function kounselia_newsletter_send_confirmation( $sub ) {
    $content = '<p style="margin-bottom:18px;">Hi ' . esc_html( kounselia_newsletter_first_name( $sub ) ) . ', please confirm you would like to receive emails from Kounselia. If you did not sign up, you can safely ignore this message.</p>';
    return kounselia_send_html_email( $sub->email, 'Please confirm your subscription', 'One quick step', $content, 'Yes, subscribe me', kounselia_newsletter_manage_url( $sub, 'confirm' ) );
}

function kounselia_newsletter_send_welcome( $sub ) {
    $settings = kounselia_newsletter_settings();
    if ( empty( $settings['welcome_enabled'] ) ) {
        return false;
    }
    $body    = str_replace( array( '{first_name}', '{name}' ), esc_html( kounselia_newsletter_first_name( $sub ) ), esc_html( $settings['welcome_body'] ) );
    $content = kounselia_email_inline_styles( wpautop( $body ) );
    return kounselia_send_html_email(
        $sub->email,
        $settings['welcome_subject'],
        'Welcome, ' . esc_html( kounselia_newsletter_first_name( $sub ) ),
        $content,
        'Read the journal',
        kounselia_blog_url( '', true ),
        kounselia_newsletter_email_opts( $sub )
    );
}

/**
 * Unsubscribe footer + List-Unsubscribe headers for one contact.
 */
function kounselia_newsletter_email_opts( $sub, $extra = array() ) {
    $settings = kounselia_newsletter_settings();
    $unsub    = kounselia_newsletter_manage_url( $sub, 'unsubscribe' );
    $footer   = '<span style="font-size:11px;color:#A8A49A;">You are receiving this because you subscribed to Kounselia updates.<br>'
        . '<a href="' . esc_url( kounselia_newsletter_manage_url( $sub ) ) . '" style="color:#8B3A52;">Email preferences</a> &middot; '
        . '<a href="' . esc_url( $unsub ) . '" style="color:#8B3A52;">Unsubscribe</a>'
        . ( $settings['footer_address'] ? '<br>' . esc_html( $settings['footer_address'] ) : '' ) . '</span>';
    return array_merge( array(
        'footer_html' => $footer,
        'headers'     => array(
            'List-Unsubscribe: <' . $unsub . '>',
            'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
        ),
    ), $extra );
}

/**
 * Update which emails a contact receives. $lists: array of list keys
 * to stay on; an empty array means unsubscribe from everything.
 */
function kounselia_newsletter_set_preferences( $sub, $lists ) {
    if ( ! $sub ) {
        return null;
    }
    $lists = array_intersect( (array) $lists, array_keys( kounselia_newsletter_lists() ) );
    $args  = array(
        'list_newsletter' => in_array( 'newsletter', $lists, true ),
        'list_blog'       => in_array( 'blog', $lists, true ),
    );
    if ( empty( $lists ) ) {
        $args['status'] = 'unsubscribed';
    } elseif ( 'subscribed' !== $sub->status ) {
        $args['status'] = 'subscribed';
    }
    return kounselia_newsletter_upsert( $sub->email, $args );
}

/* --- Keep members in sync with their contact row ----------------------- */

function kounselia_newsletter_on_user_register( $user_id ) {
    $user = get_userdata( $user_id );
    if ( ! $user || ! is_email( $user->user_email ) ) {
        return;
    }
    $existing = kounselia_newsletter_get_by_email( $user->user_email );
    if ( $existing ) {
        // Already a contact (e.g. signed up to the newsletter first): just
        // link the account, never override their choice.
        kounselia_newsletter_upsert( $user->user_email, array( 'user_id' => $user_id, 'name' => kounselia_newsletter_account_name( $user ) ) );
        return;
    }
    $settings = kounselia_newsletter_settings();
    if ( empty( $settings['auto_subscribe_members'] ) ) {
        return;
    }
    // Staff accounts are created as members too; they're filtered out of
    // member audiences by role, so adding them is harmless.
    kounselia_newsletter_upsert( $user->user_email, array(
        'user_id' => $user_id,
        'name'    => kounselia_newsletter_account_name( $user ),
        'source'  => 'member',
    ) );
}

/**
 * A member's display name, or '' while it's still just their email
 * (WordPress uses the email as the name until one is saved).
 */
function kounselia_newsletter_account_name( $user ) {
    return ( $user && $user->display_name && false === strpos( $user->display_name, '@' ) ) ? $user->display_name : '';
}
add_action( 'user_register', 'kounselia_newsletter_on_user_register', 20 );

function kounselia_newsletter_on_profile_update( $user_id, $old_user ) {
    global $wpdb;
    $user = get_userdata( $user_id );
    if ( ! $user || ! $old_user ) {
        return;
    }
    $sub = kounselia_newsletter_get_by_user( $user_id );
    $name = kounselia_newsletter_account_name( $user );
    if ( $sub && $name && $name !== $sub->name ) {
        $wpdb->update( kounselia_newsletter_table(), array( 'name' => mb_substr( $name, 0, 191 ) ), array( 'id' => $sub->id ) );
    }
    if ( $user->user_email === $old_user->user_email ) {
        return;
    }
    if ( $sub && ! kounselia_newsletter_get_by_email( $user->user_email ) ) {
        $wpdb->update( kounselia_newsletter_table(), array( 'email' => strtolower( $user->user_email ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $sub->id ) );
    }
}
add_action( 'profile_update', 'kounselia_newsletter_on_profile_update', 10, 2 );

function kounselia_newsletter_on_delete_user( $user_id ) {
    global $wpdb;
    $wpdb->delete( kounselia_newsletter_table(), array( 'user_id' => (int) $user_id ) );
}
add_action( 'delete_user', 'kounselia_newsletter_on_delete_user' );

/**
 * One-time: add every existing member as a contact (when the
 * auto-subscribe setting is on), dated to when they registered.
 * Existing contacts are left exactly as they are.
 */
function kounselia_newsletter_backfill_members() {
    global $wpdb;
    if ( get_option( 'kounselia_newsletter_members_backfilled' ) ) {
        return;
    }
    update_option( 'kounselia_newsletter_members_backfilled', 1 );

    $settings = kounselia_newsletter_settings();
    if ( empty( $settings['auto_subscribe_members'] ) ) {
        return;
    }

    $salt = wp_generate_password( 12, false );
    $now  = current_time( 'mysql' );
    $wpdb->query( $wpdb->prepare(
        'INSERT IGNORE INTO ' . kounselia_newsletter_table() . " (email, name, user_id, status, list_newsletter, list_blog, source, token, confirmed_at, created_at, updated_at)
         SELECT LOWER(u.user_email), u.display_name, u.ID, 'subscribed', 1, 1, 'member', CONCAT(MD5(CONCAT(%s, u.ID, RAND())), SUBSTRING(MD5(RAND()), 1, 8)), %s, u.user_registered, %s
         FROM {$wpdb->users} u
         INNER JOIN {$wpdb->usermeta} m ON m.user_id = u.ID AND m.meta_key = %s
         WHERE m.meta_value LIKE %s AND u.user_email != ''",
        $salt, $now, $now, $wpdb->prefix . 'capabilities', '%"subscriber"%'
    ) );
}

/* -------------------------------------------------------------------------
 * SEGMENTS (audience rules)
 * ---------------------------------------------------------------------- */

/**
 * The rule keys an audience supports, sanitized. Every rule narrows the
 * audience further (they are AND-ed together).
 */
function kounselia_newsletter_normalize_rules( $rules ) {
    $rules = is_array( $rules ) ? $rules : (array) json_decode( (string) $rules, true );
    $pick  = function ( $key, $allowed, $default ) use ( $rules ) {
        return ( isset( $rules[ $key ] ) && in_array( $rules[ $key ], $allowed, true ) ) ? $rules[ $key ] : $default;
    };
    $days = function ( $key ) use ( $rules ) {
        return isset( $rules[ $key ] ) && '' !== $rules[ $key ] ? max( 0, min( 3650, (int) $rules[ $key ] ) ) : 0;
    };
    $tags = function ( $key ) use ( $rules ) {
        $raw = isset( $rules[ $key ] ) ? $rules[ $key ] : array();
        return array_values( array_filter( explode( ',', kounselia_newsletter_clean_tags( $raw ) ) ) );
    };

    return array(
        'audience'          => $pick( 'audience', array( 'all', 'members', 'non_members', 'professionals', 'pro_members', 'free_members' ), 'all' ),
        'subscribed_within' => $days( 'subscribed_within' ),
        'subscribed_before' => $days( 'subscribed_before' ),
        'active_within'     => $days( 'active_within' ),
        'inactive_for'      => $days( 'inactive_for' ),
        'has_booking'       => $pick( 'has_booking', array( 'any', 'yes', 'no' ), 'any' ),
        'tags_any'          => $tags( 'tags_any' ),
        'tags_none'         => $tags( 'tags_none' ),
        'source'            => isset( $rules['source'] ) ? sanitize_key( $rules['source'] ) : '',
        'email_domain'      => isset( $rules['email_domain'] ) ? strtolower( preg_replace( '/[^a-z0-9.\-]/i', '', ltrim( (string) $rules['email_domain'], '@' ) ) ) : '',
    );
}

/**
 * Plain-English summary of an audience, for the admin screens.
 */
function kounselia_newsletter_describe_rules( $rules ) {
    $r     = kounselia_newsletter_normalize_rules( $rules );
    $names = array(
        'all' => 'Everyone', 'members' => 'Members', 'non_members' => 'Website subscribers (no account)',
        'professionals' => 'Verified professionals', 'pro_members' => 'Pro members', 'free_members' => 'Free members',
    );
    $parts = array( $names[ $r['audience'] ] );
    if ( $r['subscribed_within'] ) { $parts[] = 'joined in the last ' . $r['subscribed_within'] . ' days'; }
    if ( $r['subscribed_before'] ) { $parts[] = 'joined over ' . $r['subscribed_before'] . ' days ago'; }
    if ( $r['active_within'] ) { $parts[] = 'chatted in the last ' . $r['active_within'] . ' days'; }
    if ( $r['inactive_for'] ) { $parts[] = 'no chat for ' . $r['inactive_for'] . '+ days'; }
    if ( 'yes' === $r['has_booking'] ) { $parts[] = 'have booked a professional'; }
    if ( 'no' === $r['has_booking'] ) { $parts[] = 'never booked a professional'; }
    if ( $r['tags_any'] ) { $parts[] = 'tagged ' . implode( ' or ', $r['tags_any'] ); }
    if ( $r['tags_none'] ) { $parts[] = 'not tagged ' . implode( ', ', $r['tags_none'] ); }
    if ( $r['source'] ) { $parts[] = 'source: ' . $r['source']; }
    if ( $r['email_domain'] ) { $parts[] = 'emails at @' . $r['email_domain']; }
    return implode( ' · ', $parts );
}

/**
 * Builds the WHERE clause (for contacts aliased "s") matching an
 * audience on one list. Returns array( $sql, $params ).
 * Always excludes: unsubscribed/pending contacts, contacts off this
 * list, and banned or deleted member accounts.
 */
function kounselia_newsletter_segment_where( $rules, $list_key = 'newsletter' ) {
    global $wpdb;
    $r      = kounselia_newsletter_normalize_rules( $rules );
    $list   = 'blog' === $list_key ? 's.list_blog' : 's.list_newsletter';
    $p      = $wpdb->prefix;
    $where  = array( "s.status = 'subscribed'", "{$list} = 1" );
    $params = array();

    $where[] = "(s.user_id IS NULL OR NOT EXISTS (SELECT 1 FROM {$wpdb->usermeta} bm WHERE bm.user_id = s.user_id AND bm.meta_key IN ('kounselia_banned','kounselia_deleted_at') AND bm.meta_value != '' AND bm.meta_value != '0'))";

    $is_member = "s.user_id IS NOT NULL AND EXISTS (SELECT 1 FROM {$wpdb->usermeta} rm WHERE rm.user_id = s.user_id AND rm.meta_key = '{$p}capabilities' AND rm.meta_value LIKE '%\"subscriber\"%')";
    $is_pro    = "(EXISTS (SELECT 1 FROM {$wpdb->usermeta} pm WHERE pm.user_id = s.user_id AND pm.meta_key = 'kounselia_plan' AND pm.meta_value = 'pro') OR EXISTS (SELECT 1 FROM {$p}kounselia_subscriptions ks WHERE ks.user_id = s.user_id AND ks.current_period_end > %s))";

    switch ( $r['audience'] ) {
        case 'members':
            $where[] = "({$is_member})";
            break;
        case 'non_members':
            $where[] = 's.user_id IS NULL';
            break;
        case 'professionals':
            $where[] = "s.user_id IS NOT NULL AND EXISTS (SELECT 1 FROM {$p}kounselia_professionals kp WHERE kp.user_id = s.user_id AND kp.status = 'verified')";
            break;
        case 'pro_members':
            $where[]  = "({$is_member}) AND {$is_pro}";
            $params[] = current_time( 'mysql' );
            break;
        case 'free_members':
            $where[]  = "({$is_member}) AND NOT {$is_pro}";
            $params[] = current_time( 'mysql' );
            break;
    }

    $cutoff = function ( $days ) {
        return date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS );
    };

    if ( $r['subscribed_within'] ) {
        $where[]  = 's.created_at >= %s';
        $params[] = $cutoff( $r['subscribed_within'] );
    }
    if ( $r['subscribed_before'] ) {
        $where[]  = 's.created_at < %s';
        $params[] = $cutoff( $r['subscribed_before'] );
    }
    if ( $r['active_within'] ) {
        $where[]  = "s.user_id IS NOT NULL AND EXISTS (SELECT 1 FROM {$p}kounselia_sessions cs WHERE cs.user_id = s.user_id AND cs.started_at >= %s)";
        $params[] = $cutoff( $r['active_within'] );
    }
    if ( $r['inactive_for'] ) {
        $where[]  = "s.user_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM {$p}kounselia_sessions cs2 WHERE cs2.user_id = s.user_id AND cs2.started_at >= %s)";
        $params[] = $cutoff( $r['inactive_for'] );
    }
    if ( 'yes' === $r['has_booking'] ) {
        $where[] = "s.user_id IS NOT NULL AND EXISTS (SELECT 1 FROM {$p}kounselia_bookings kb WHERE kb.client_user_id = s.user_id)";
    } elseif ( 'no' === $r['has_booking'] ) {
        $where[] = "(s.user_id IS NULL OR NOT EXISTS (SELECT 1 FROM {$p}kounselia_bookings kb2 WHERE kb2.client_user_id = s.user_id))";
    }
    if ( $r['tags_any'] ) {
        $ors = array();
        foreach ( $r['tags_any'] as $tag ) {
            $ors[]    = "CONCAT(',', IFNULL(s.tags,''), ',') LIKE %s";
            $params[] = '%,' . $wpdb->esc_like( $tag ) . ',%';
        }
        $where[] = '(' . implode( ' OR ', $ors ) . ')';
    }
    foreach ( $r['tags_none'] as $tag ) {
        $where[]  = "CONCAT(',', IFNULL(s.tags,''), ',') NOT LIKE %s";
        $params[] = '%,' . $wpdb->esc_like( $tag ) . ',%';
    }
    if ( $r['source'] ) {
        $where[]  = 's.source = %s';
        $params[] = $r['source'];
    }
    if ( $r['email_domain'] ) {
        $where[]  = 's.email LIKE %s';
        $params[] = '%@' . $wpdb->esc_like( $r['email_domain'] );
    }

    return array( implode( ' AND ', $where ), $params );
}

function kounselia_newsletter_prepare( $sql, $params ) {
    global $wpdb;
    return $params ? $wpdb->prepare( $sql, $params ) : $sql;
}

function kounselia_newsletter_audience_count( $rules, $list_key = 'newsletter' ) {
    global $wpdb;
    list( $where, $params ) = kounselia_newsletter_segment_where( $rules, $list_key );
    return (int) $wpdb->get_var( kounselia_newsletter_prepare( 'SELECT COUNT(*) FROM ' . kounselia_newsletter_table() . " s WHERE {$where}", $params ) );
}

function kounselia_newsletter_audience_sample( $rules, $list_key = 'newsletter', $limit = 6 ) {
    global $wpdb;
    list( $where, $params ) = kounselia_newsletter_segment_where( $rules, $list_key );
    $params[] = (int) $limit;
    return $wpdb->get_results( $wpdb->prepare( 'SELECT s.id, s.email, s.name, s.user_id FROM ' . kounselia_newsletter_table() . " s WHERE {$where} ORDER BY s.id DESC LIMIT %d", $params ) );
}

function kounselia_newsletter_get_segment( $id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}kounselia_segments WHERE id = %d", $id ) );
}

function kounselia_newsletter_list_segments() {
    global $wpdb;
    return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}kounselia_segments ORDER BY name ASC" );
}

/* -------------------------------------------------------------------------
 * CAMPAIGNS
 * ---------------------------------------------------------------------- */

function kounselia_newsletter_get_campaign( $id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}kounselia_campaigns WHERE id = %d", $id ) );
}

/**
 * The audience rules a campaign sends to: its saved segment's rules if
 * it uses one (read at send time, so edits to the segment apply),
 * otherwise its own custom rules.
 */
function kounselia_newsletter_campaign_rules( $campaign ) {
    if ( $campaign->segment_id ) {
        $segment = kounselia_newsletter_get_segment( $campaign->segment_id );
        if ( $segment ) {
            return kounselia_newsletter_normalize_rules( $segment->rules );
        }
    }
    return kounselia_newsletter_normalize_rules( $campaign->rules );
}

/**
 * Create or update a draft campaign. Returns id or WP_Error.
 */
function kounselia_newsletter_save_campaign( $data, $id = 0 ) {
    global $wpdb;
    $table = $wpdb->prefix . 'kounselia_campaigns';
    $now   = current_time( 'mysql' );

    $existing = $id ? kounselia_newsletter_get_campaign( $id ) : null;
    if ( $existing && ! in_array( $existing->status, array( 'draft', 'scheduled' ), true ) ) {
        return new WP_Error( 'locked', 'This campaign has already been sent and can no longer be edited. Duplicate it instead.' );
    }

    $subject = isset( $data['subject'] ) ? trim( sanitize_text_field( $data['subject'] ) ) : '';
    if ( '' === $subject ) {
        return new WP_Error( 'missing_subject', 'Please write a subject line.' );
    }

    $row = array(
        'name'       => ! empty( $data['name'] ) ? mb_substr( sanitize_text_field( $data['name'] ), 0, 191 ) : mb_substr( $subject, 0, 191 ),
        'subject'    => mb_substr( $subject, 0, 255 ),
        'preheader'  => isset( $data['preheader'] ) ? mb_substr( sanitize_text_field( $data['preheader'] ), 0, 255 ) : null,
        'headline'   => isset( $data['headline'] ) ? mb_substr( sanitize_text_field( $data['headline'] ), 0, 255 ) : null,
        'content'    => isset( $data['content'] ) ? kounselia_content_kses( $data['content'] ) : '',
        'btn_text'   => isset( $data['btn_text'] ) ? mb_substr( sanitize_text_field( $data['btn_text'] ), 0, 120 ) : null,
        'btn_url'    => ! empty( $data['btn_url'] ) ? esc_url_raw( $data['btn_url'] ) : null,
        'list_key'   => ( isset( $data['list_key'] ) && 'blog' === $data['list_key'] ) ? 'blog' : 'newsletter',
        'segment_id' => ! empty( $data['segment_id'] ) ? (int) $data['segment_id'] : null,
        'rules'      => wp_json_encode( kounselia_newsletter_normalize_rules( isset( $data['rules'] ) ? $data['rules'] : array() ) ),
        'updated_at' => $now,
    );

    if ( $existing ) {
        $wpdb->update( $table, $row, array( 'id' => $id ) );
        return (int) $id;
    }
    $row['type']       = isset( $data['type'] ) && 'blog' === $data['type'] ? 'blog' : 'newsletter';
    $row['post_id']    = ! empty( $data['post_id'] ) ? (int) $data['post_id'] : null;
    $row['status']     = 'draft';
    $row['created_by'] = get_current_user_id() ?: null;
    $row['created_at'] = $now;
    $wpdb->insert( $table, $row );
    return (int) $wpdb->insert_id;
}

/**
 * Send now ($when = null) or schedule a campaign. Returns true or WP_Error.
 */
function kounselia_newsletter_start_campaign( $id, $when = null ) {
    global $wpdb;
    $campaign = kounselia_newsletter_get_campaign( $id );
    if ( ! $campaign || ! in_array( $campaign->status, array( 'draft', 'scheduled' ), true ) ) {
        return new WP_Error( 'bad_state', 'This campaign cannot be sent.' );
    }

    $table = $wpdb->prefix . 'kounselia_campaigns';
    if ( $when && strtotime( $when ) > current_time( 'timestamp' ) ) {
        $wpdb->update( $table, array( 'status' => 'scheduled', 'scheduled_at' => date( 'Y-m-d H:i:s', strtotime( $when ) ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
        return true;
    }

    $total = kounselia_newsletter_build_recipients( $campaign );
    $wpdb->update( $table, array(
        'status'           => $total ? 'sending' : 'sent',
        'started_at'       => current_time( 'mysql' ),
        'finished_at'      => $total ? null : current_time( 'mysql' ),
        'recipients_total' => $total,
        'updated_at'       => current_time( 'mysql' ),
    ), array( 'id' => $id ) );
    return true;
}

/**
 * Turns the campaign's audience into queued recipient rows (one per
 * contact, ever). Returns the number queued.
 */
function kounselia_newsletter_build_recipients( $campaign ) {
    global $wpdb;
    list( $where, $params ) = kounselia_newsletter_segment_where( kounselia_newsletter_campaign_rules( $campaign ), $campaign->list_key );
    $salt = wp_generate_password( 16, false );
    $sql  = "INSERT IGNORE INTO {$wpdb->prefix}kounselia_campaign_recipients (campaign_id, subscriber_id, email, status, token)
             SELECT %d, s.id, s.email, 'queued', MD5(CONCAT(%s, '-', %d, '-', s.id, '-', RAND()))
             FROM " . kounselia_newsletter_table() . " s WHERE {$where}";
    $wpdb->query( $wpdb->prepare( $sql, array_merge( array( (int) $campaign->id, $salt, (int) $campaign->id ), $params ) ) );
    return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_campaign_recipients WHERE campaign_id = %d", $campaign->id ) );
}

function kounselia_newsletter_cancel_campaign( $id ) {
    global $wpdb;
    $campaign = kounselia_newsletter_get_campaign( $id );
    if ( ! $campaign || ! in_array( $campaign->status, array( 'scheduled', 'sending' ), true ) ) {
        return false;
    }
    $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}kounselia_campaign_recipients SET status = 'cancelled' WHERE campaign_id = %d AND status = 'queued'", $id ) );
    $wpdb->update( $wpdb->prefix . 'kounselia_campaigns', array( 'status' => 'cancelled', 'finished_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
    return true;
}

/* --- Rendering one email ------------------------------------------------ */

function kounselia_newsletter_click_sig( $url ) {
    return substr( hash_hmac( 'sha256', $url, wp_salt( 'auth' ) ), 0, 20 );
}

/**
 * Wraps a link in the click tracker. __KTOKEN__ is swapped for each
 * recipient's own token at send time.
 */
function kounselia_newsletter_track_link( $url ) {
    return kounselia_site_url( '/newsletter/?a=click&r=__KTOKEN__&u=' . rawurlencode( $url ) . '&s=' . kounselia_newsletter_click_sig( $url ) );
}

/**
 * The campaign's body, styled for email and with trackable links —
 * built once per campaign, then personalized per recipient.
 */
function kounselia_newsletter_campaign_body( $campaign, $track_clicks ) {
    $html = kounselia_email_inline_styles( (string) $campaign->content );
    if ( $track_clicks ) {
        $html = preg_replace_callback( '#(<a\b[^>]*\bhref=)(["\'])(https?://[^"\']+)\2#i', function ( $m ) {
            $url = html_entity_decode( $m[3] );
            return $m[1] . $m[2] . esc_url( kounselia_newsletter_track_link( $url ) ) . $m[2];
        }, $html );
    }
    return $html;
}

/**
 * Fills {first_name} / {name} / {email}. $escape = false for plain-text
 * places (subject, preview line) that get escaped later anyway.
 */
function kounselia_newsletter_personalize( $text, $sub, $escape = true ) {
    $first = kounselia_newsletter_first_name( $sub );
    $email = $sub->email;
    if ( $escape ) {
        $first = esc_html( $first );
        $email = esc_html( $email );
    }
    return str_replace( array( '{first_name}', '{name}', '{email}' ), array( $first, $first, $email ), (string) $text );
}

/**
 * Full email for one recipient. $recipient_token is null for previews
 * and test sends (no tracking).
 * Returns array( subject, headline, content, btn_text, btn_url, opts ).
 */
function kounselia_newsletter_build_email( $campaign, $sub, $recipient_token = null, $body = null ) {
    $settings = kounselia_newsletter_settings();
    $track    = null !== $recipient_token;
    if ( null === $body ) {
        $body = kounselia_newsletter_campaign_body( $campaign, $track && ! empty( $settings['track_clicks'] ) );
    }
    $btn_url = $campaign->btn_url;
    if ( $btn_url && $track && ! empty( $settings['track_clicks'] ) ) {
        $btn_url = kounselia_newsletter_track_link( $btn_url );
    }

    $token   = $track ? $recipient_token : 'preview';
    $content = str_replace( '__KTOKEN__', rawurlencode( $token ), kounselia_newsletter_personalize( $body, $sub ) );
    $btn_url = $btn_url ? str_replace( '__KTOKEN__', rawurlencode( $token ), $btn_url ) : null;

    $extra = array( 'preheader' => kounselia_newsletter_personalize( $campaign->preheader, $sub, false ) );
    if ( $track && ! empty( $settings['track_opens'] ) ) {
        $extra['pixel_url'] = kounselia_site_url( '/newsletter/?a=open&r=' . rawurlencode( $recipient_token ) );
    }

    return array(
        'subject'  => kounselia_newsletter_personalize( $campaign->subject, $sub, false ),
        'headline' => kounselia_newsletter_personalize( esc_html( $campaign->headline ), $sub ),
        'content'  => $content,
        'btn_text' => $campaign->btn_text,
        'btn_url'  => $btn_url,
        'opts'     => kounselia_newsletter_email_opts( $sub, $extra ),
    );
}

function kounselia_newsletter_send_one( $campaign, $sub, $recipient_token = null, $body = null, $to = null ) {
    $e = kounselia_newsletter_build_email( $campaign, $sub, $recipient_token, $body );
    return kounselia_send_html_email( $to ? $to : $sub->email, $e['subject'], $e['headline'], $e['content'], $e['btn_text'], $e['btn_url'], $e['opts'] );
}

/* --- The delivery queue -------------------------------------------------- */

/**
 * Moves due scheduled campaigns into sending, then delivers up to
 * $limit queued emails. Safe to call from several places at once: each
 * batch "claims" its rows with one UPDATE before sending, so two
 * overlapping runs can never both send the same email.
 * Returns the number of emails attempted.
 */
function kounselia_newsletter_process_queue( $limit = null ) {
    global $wpdb;
    $settings = kounselia_newsletter_settings();
    $limit    = $limit ? (int) $limit : max( 5, (int) $settings['batch_size'] );
    $ctable   = $wpdb->prefix . 'kounselia_campaigns';
    $rtable   = $wpdb->prefix . 'kounselia_campaign_recipients';
    $now      = current_time( 'mysql' );

    // 1. Scheduled campaigns whose time has come.
    $due = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$ctable} WHERE status = 'scheduled' AND scheduled_at <= %s", $now ) );
    foreach ( $due as $campaign ) {
        if ( 'blog' === $campaign->type && $campaign->post_id ) {
            $post = kounselia_get_blog_post( $campaign->post_id );
            if ( ! $post || 'published' !== $post->status ) {
                kounselia_newsletter_cancel_campaign( $campaign->id );
                continue;
            }
            if ( ! kounselia_blog_post_is_live( $post ) ) {
                // The post was rescheduled later; follow it.
                $wpdb->update( $ctable, array( 'scheduled_at' => $post->published_at ), array( 'id' => $campaign->id ) );
                continue;
            }
        }
        kounselia_newsletter_start_campaign( $campaign->id );
    }

    // 2. Rows claimed by a run that died more than 15 minutes ago go back in the queue.
    $wpdb->query( $wpdb->prepare( "UPDATE {$rtable} SET status = 'queued', claim = NULL WHERE status = 'sending' AND claim < %s", (string) ( time() - 15 * MINUTE_IN_SECONDS ) ) );

    // 3. Send.
    $attempted = 0;
    $sending   = $wpdb->get_results( "SELECT * FROM {$ctable} WHERE status = 'sending' ORDER BY id ASC" );
    foreach ( $sending as $campaign ) {
        if ( $attempted >= $limit ) {
            break;
        }
        $claim = time() . '-' . wp_generate_password( 8, false );
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$rtable} SET status = 'sending', claim = %s WHERE campaign_id = %d AND status = 'queued' ORDER BY id ASC LIMIT %d",
            $claim, $campaign->id, $limit - $attempted
        ) );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$rtable} WHERE claim = %s", $claim ) );

        $body = kounselia_newsletter_campaign_body( $campaign, ! empty( $settings['track_clicks'] ) );
        foreach ( $rows as $row ) {
            $attempted++;
            $sub = kounselia_newsletter_get_subscriber( $row->subscriber_id );
            // Re-check consent at the moment of sending: someone may have
            // unsubscribed after the campaign started.
            $list_col = 'blog' === $campaign->list_key ? 'list_blog' : 'list_newsletter';
            if ( ! $sub || 'subscribed' !== $sub->status || ! $sub->{$list_col} ) {
                $wpdb->update( $rtable, array( 'status' => 'skipped', 'claim' => null ), array( 'id' => $row->id ) );
                continue;
            }
            $ok = kounselia_newsletter_send_one( $campaign, $sub, $row->token, $body );
            $wpdb->update( $rtable, array(
                'status'  => $ok ? 'sent' : 'failed',
                'sent_at' => $ok ? current_time( 'mysql' ) : null,
                'error'   => $ok ? null : 'Mail server rejected the message',
                'claim'   => null,
            ), array( 'id' => $row->id ) );
            if ( $ok ) {
                $wpdb->update( kounselia_newsletter_table(), array( 'last_emailed_at' => current_time( 'mysql' ) ), array( 'id' => $sub->id ) );
            }
        }

        kounselia_newsletter_refresh_stats( $campaign->id );
    }

    return $attempted;
}

/**
 * Recounts a campaign's numbers from its recipient rows, and marks it
 * sent once nothing is left in the queue.
 */
function kounselia_newsletter_refresh_stats( $campaign_id ) {
    global $wpdb;
    $rtable = $wpdb->prefix . 'kounselia_campaign_recipients';
    $stats  = $wpdb->get_row( $wpdb->prepare(
        "SELECT COUNT(*) AS total,
            SUM(status = 'sent') AS sent,
            SUM(status = 'failed') AS failed,
            SUM(status IN ('queued','sending')) AS pending,
            SUM(opened_at IS NOT NULL) AS opens,
            SUM(clicked_at IS NOT NULL) AS clicks
         FROM {$rtable} WHERE campaign_id = %d",
        $campaign_id
    ) );
    $update = array(
        'recipients_total' => (int) $stats->total,
        'sent_count'       => (int) $stats->sent,
        'failed_count'     => (int) $stats->failed,
        'open_count'       => (int) $stats->opens,
        'click_count'      => (int) $stats->clicks,
    );
    $campaign = kounselia_newsletter_get_campaign( $campaign_id );
    if ( $campaign && 'sending' === $campaign->status && 0 === (int) $stats->pending ) {
        $update['status']      = 'sent';
        $update['finished_at'] = current_time( 'mysql' );
    }
    $wpdb->update( $wpdb->prefix . 'kounselia_campaigns', $update, array( 'id' => $campaign_id ) );
}

add_filter( 'cron_schedules', function ( $schedules ) {
    if ( ! isset( $schedules['kounselia_every_minute'] ) ) {
        $schedules['kounselia_every_minute'] = array(
            'interval' => MINUTE_IN_SECONDS,
            'display'  => 'Every minute (Kounselia)',
        );
    }
    return $schedules;
} );

add_action( 'init', function () {
    if ( ! wp_next_scheduled( 'kounselia_newsletter_cron' ) ) {
        wp_schedule_event( time() + 60, 'kounselia_every_minute', 'kounselia_newsletter_cron' );
    }
} );
add_action( 'kounselia_newsletter_cron', 'kounselia_newsletter_process_queue' );

/* --- Tracking ------------------------------------------------------------ */

function kounselia_newsletter_recipient_by_token( $token ) {
    global $wpdb;
    if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
        return null;
    }
    return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}kounselia_campaign_recipients WHERE token = %s", $token ) );
}

function kounselia_newsletter_track_open( $token ) {
    global $wpdb;
    $r = kounselia_newsletter_recipient_by_token( $token );
    if ( $r && ! $r->opened_at ) {
        $wpdb->update( $wpdb->prefix . 'kounselia_campaign_recipients', array( 'opened_at' => current_time( 'mysql' ) ), array( 'id' => $r->id ) );
        kounselia_newsletter_refresh_stats( $r->campaign_id );
    }
}

/**
 * Records a click and returns where to send the reader. Only URLs
 * carrying a valid signature are followed, so the tracker can't be
 * abused as an open redirect.
 */
function kounselia_newsletter_track_click( $token, $url, $sig ) {
    global $wpdb;
    if ( ! $url || ! hash_equals( kounselia_newsletter_click_sig( $url ), (string) $sig ) ) {
        return kounselia_site_url( '/' );
    }
    $r = kounselia_newsletter_recipient_by_token( $token );
    if ( $r && ! $r->clicked_at ) {
        $now = current_time( 'mysql' );
        $wpdb->update( $wpdb->prefix . 'kounselia_campaign_recipients', array( 'clicked_at' => $now, 'opened_at' => $r->opened_at ? $r->opened_at : $now ), array( 'id' => $r->id ) );
        kounselia_newsletter_refresh_stats( $r->campaign_id );
    }
    return $url;
}

/* --- Blog post notifications -------------------------------------------- */

/**
 * Queues the "new post" email for a published blog post — sent now if
 * the post is live, or when it goes live if it's scheduled. Never
 * queues twice for the same post. Returns the campaign id, or WP_Error.
 */
function kounselia_newsletter_queue_post_notification( $post_id, $segment_id = 0 ) {
    global $wpdb;
    $post = kounselia_get_blog_post( $post_id );
    if ( ! $post || 'published' !== $post->status ) {
        return new WP_Error( 'not_published', 'Only published posts can be emailed.' );
    }
    if ( $post->notify_campaign_id ) {
        return (int) $post->notify_campaign_id;
    }

    $blog       = kounselia_blog_settings();
    $segment_id = $segment_id ? (int) $segment_id : (int) $blog['notify_segment_id'];
    $url        = kounselia_blog_url( $post->slug, true );
    $summary    = kounselia_blog_summary( $post, 45 );

    $content = '';
    if ( $post->cover_image ) {
        $content .= '<p><a href="' . esc_url( $url ) . '"><img src="' . esc_url( $post->cover_image ) . '" alt=""></a></p>';
    }
    $content .= '<p style="text-transform:uppercase;letter-spacing:2px;font-size:11px;color:#B07D3A;">New on ' . esc_html( $blog['title'] ) . '</p>';
    $content .= '<h2>' . esc_html( $post->title ) . '</h2>';
    $content .= '<p>' . esc_html( $summary ) . '</p>';
    $content .= '<p style="color:#A8A49A;font-size:13px;">' . (int) $post->reading_minutes . ' min read</p>';

    $campaign_id = kounselia_newsletter_save_campaign( array(
        'type'       => 'blog',
        'post_id'    => $post->id,
        'name'       => 'New post: ' . $post->title,
        'subject'    => $post->title,
        'preheader'  => wp_trim_words( $summary, 18 ),
        'headline'   => '',
        'content'    => $content,
        'btn_text'   => 'Read the full story',
        'btn_url'    => $url,
        'list_key'   => 'blog',
        'segment_id' => $segment_id,
        'rules'      => array( 'audience' => 'all' ),
    ) );
    if ( is_wp_error( $campaign_id ) ) {
        return $campaign_id;
    }

    $wpdb->update( $wpdb->prefix . 'kounselia_posts', array( 'notify_campaign_id' => $campaign_id ), array( 'id' => $post->id ) );
    kounselia_newsletter_start_campaign( $campaign_id, kounselia_blog_post_is_live( $post ) ? null : $post->published_at );
    return $campaign_id;
}

/* -------------------------------------------------------------------------
 * PUBLIC + MEMBER AJAX
 * ---------------------------------------------------------------------- */

/**
 * Website sign-up form. No nonce on purpose (it's on cacheable public
 * pages and used by signed-out visitors) — protected instead by a
 * honeypot field and a per-IP rate limit.
 */
function kounselia_ajax_newsletter_subscribe() {
    if ( kounselia_honeypot_tripped() ) {
        wp_send_json_success( array( 'message' => "You're subscribed. Welcome." ) );
    }
    if ( kounselia_rate_limited( 'newsletter_subscribe', 6, 600 ) ) {
        wp_send_json_error( array( 'message' => 'Too many attempts. Please try again in a few minutes.' ), 429 );
    }
    $email  = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
    $name   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
    $source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : 'website';
    if ( ! in_array( $source, array( 'footer', 'blog', 'post', 'page', 'website' ), true ) ) {
        $source = 'website';
    }
    if ( ! is_email( $email ) ) {
        wp_send_json_error( array( 'message' => 'Please enter a valid email address.' ), 400 );
    }
    $result = kounselia_newsletter_subscribe_public( $email, $name, $source );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
    }
    wp_send_json_success( $result );
}
add_action( 'wp_ajax_nopriv_kounselia_newsletter_subscribe', 'kounselia_ajax_newsletter_subscribe' );
add_action( 'wp_ajax_kounselia_newsletter_subscribe', 'kounselia_ajax_newsletter_subscribe' );

/**
 * A signed-in member's own email preferences (dashboard → Settings).
 */
function kounselia_ajax_get_email_prefs() {
    kounselia_verify_nonce();
    $user = wp_get_current_user();
    if ( ! $user->ID ) {
        wp_send_json_error( array( 'message' => 'Please sign in.' ), 401 );
    }
    $sub = kounselia_newsletter_get_by_user( $user->ID );
    if ( ! $sub ) {
        $sub = kounselia_newsletter_get_by_email( $user->user_email );
    }
    $on = $sub && 'subscribed' === $sub->status;
    wp_send_json_success( array(
        'newsletter' => $on && (int) $sub->list_newsletter === 1,
        'blog'       => $on && (int) $sub->list_blog === 1,
    ) );
}
add_action( 'wp_ajax_kounselia_get_email_prefs', 'kounselia_ajax_get_email_prefs' );

function kounselia_ajax_save_email_prefs() {
    kounselia_verify_nonce();
    $user = wp_get_current_user();
    if ( ! $user->ID ) {
        wp_send_json_error( array( 'message' => 'Please sign in.' ), 401 );
    }
    $lists = array();
    if ( ! empty( $_POST['newsletter'] ) && '1' === $_POST['newsletter'] ) {
        $lists[] = 'newsletter';
    }
    if ( ! empty( $_POST['blog'] ) && '1' === $_POST['blog'] ) {
        $lists[] = 'blog';
    }
    $sub = kounselia_newsletter_get_by_user( $user->ID );
    if ( ! $sub ) {
        $sub = kounselia_newsletter_upsert( $user->user_email, array( 'user_id' => $user->ID, 'name' => $user->display_name, 'source' => 'member' ) );
    }
    if ( is_wp_error( $sub ) ) {
        wp_send_json_error( array( 'message' => $sub->get_error_message() ), 400 );
    }
    kounselia_newsletter_set_preferences( $sub, $lists );
    wp_send_json_success( array( 'message' => $lists ? 'Email preferences saved.' : "You won't receive newsletter or blog emails. Account emails (like booking confirmations) still arrive." ) );
}
add_action( 'wp_ajax_kounselia_save_email_prefs', 'kounselia_ajax_save_email_prefs' );

/* -------------------------------------------------------------------------
 * ADMIN AJAX
 * ---------------------------------------------------------------------- */

function kounselia_newsletter_admin_guard() {
    check_ajax_referer( 'kounselia_admin_nonce', 'nonce' );
    if ( ! kounselia_admin_can( 'broadcasts' ) ) {
        kounselia_send_pure_json_error( array( 'message' => 'You do not have access to the newsletter.' ), 403 );
    }
}

function kounselia_newsletter_campaign_from_post() {
    $rules = json_decode( (string) kounselia_post_field( 'rules', '{}' ), true );
    return array(
        'name'       => kounselia_post_field( 'name' ),
        'subject'    => kounselia_post_field( 'subject' ),
        'preheader'  => kounselia_post_field( 'preheader' ),
        'headline'   => kounselia_post_field( 'headline' ),
        'content'    => kounselia_post_field( 'content' ),
        'btn_text'   => kounselia_post_field( 'btn_text' ),
        'btn_url'    => kounselia_post_field( 'btn_url' ),
        'list_key'   => kounselia_post_field( 'list_key', 'newsletter' ),
        'segment_id' => (int) kounselia_post_field( 'segment_id', 0 ),
        'rules'      => is_array( $rules ) ? $rules : array(),
    );
}

/**
 * A stand-in contact for previews/tests: the admin themselves.
 */
function kounselia_newsletter_preview_contact() {
    $user = wp_get_current_user();
    $sub  = kounselia_newsletter_get_by_email( $user->user_email );
    if ( $sub ) {
        return $sub;
    }
    return (object) array(
        'id' => 0, 'email' => $user->user_email, 'name' => $user->display_name, 'user_id' => $user->ID,
        'token' => 'preview-token-not-real-000000000', 'status' => 'subscribed',
    );
}

function kounselia_ajax_admin_nl_save_campaign() {
    kounselia_newsletter_admin_guard();
    $id     = (int) kounselia_post_field( 'id', 0 );
    $result = kounselia_newsletter_save_campaign( kounselia_newsletter_campaign_from_post(), $id );
    if ( is_wp_error( $result ) ) {
        kounselia_send_pure_json_error( array( 'message' => $result->get_error_message() ), 400 );
    }
    kounselia_admin_log( $id ? 'edited_campaign' : 'created_campaign', 'campaign', $result );
    kounselia_send_pure_json_success( array( 'message' => 'Draft saved.', 'id' => $result ) );
}
add_action( 'wp_ajax_kounselia_admin_nl_save_campaign', 'kounselia_ajax_admin_nl_save_campaign' );

function kounselia_ajax_admin_nl_preview() {
    kounselia_newsletter_admin_guard();
    $data     = kounselia_newsletter_campaign_from_post();
    $campaign = (object) array(
        'subject'   => sanitize_text_field( $data['subject'] ),
        'preheader' => sanitize_text_field( $data['preheader'] ),
        'headline'  => sanitize_text_field( $data['headline'] ),
        'content'   => kounselia_content_kses( $data['content'] ),
        'btn_text'  => sanitize_text_field( $data['btn_text'] ),
        'btn_url'   => esc_url_raw( $data['btn_url'] ),
    );
    $e = kounselia_newsletter_build_email( $campaign, kounselia_newsletter_preview_contact() );
    kounselia_send_pure_json_success( array(
        'html' => kounselia_render_email_html( $e['subject'], $e['headline'], $e['content'], $e['btn_text'], $e['btn_url'], $e['opts'] ),
    ) );
}
add_action( 'wp_ajax_kounselia_admin_nl_preview', 'kounselia_ajax_admin_nl_preview' );

function kounselia_ajax_admin_nl_send_test() {
    kounselia_newsletter_admin_guard();
    if ( kounselia_rate_limited( 'nl_test_' . get_current_user_id(), 20, 600 ) ) {
        kounselia_send_pure_json_error( array( 'message' => 'Too many test emails. Please wait a few minutes.' ), 429 );
    }
    $to = sanitize_email( kounselia_post_field( 'to' ) );
    if ( ! is_email( $to ) ) {
        $to = wp_get_current_user()->user_email;
    }
    $data     = kounselia_newsletter_campaign_from_post();
    $campaign = (object) array(
        'subject'   => '[Test] ' . sanitize_text_field( $data['subject'] ),
        'preheader' => sanitize_text_field( $data['preheader'] ),
        'headline'  => sanitize_text_field( $data['headline'] ),
        'content'   => kounselia_content_kses( $data['content'] ),
        'btn_text'  => sanitize_text_field( $data['btn_text'] ),
        'btn_url'   => esc_url_raw( $data['btn_url'] ),
    );
    $ok = kounselia_newsletter_send_one( $campaign, kounselia_newsletter_preview_contact(), null, null, $to );
    if ( ! $ok ) {
        kounselia_send_pure_json_error( array( 'message' => 'The mail server did not accept the test email.' ), 500 );
    }
    kounselia_send_pure_json_success( array( 'message' => 'Test email sent to ' . $to . '.' ) );
}
add_action( 'wp_ajax_kounselia_admin_nl_send_test', 'kounselia_ajax_admin_nl_send_test' );

function kounselia_ajax_admin_nl_send() {
    kounselia_newsletter_admin_guard();
    $id     = (int) kounselia_post_field( 'id', 0 );
    $result = kounselia_newsletter_save_campaign( kounselia_newsletter_campaign_from_post(), $id );
    if ( is_wp_error( $result ) ) {
        kounselia_send_pure_json_error( array( 'message' => $result->get_error_message() ), 400 );
    }
    $when    = kounselia_post_field( 'schedule_at' );
    $started = kounselia_newsletter_start_campaign( $result, $when ? $when : null );
    if ( is_wp_error( $started ) ) {
        kounselia_send_pure_json_error( array( 'message' => $started->get_error_message() ), 400 );
    }
    $campaign = kounselia_newsletter_get_campaign( $result );
    kounselia_admin_log( 'scheduled' === $campaign->status ? 'scheduled_campaign' : 'sent_campaign', 'campaign', $result );
    if ( 'scheduled' === $campaign->status ) {
        $msg = 'Scheduled for ' . mysql2date( 'M j, Y g:ia', $campaign->scheduled_at ) . '.';
    } elseif ( ! $campaign->recipients_total ) {
        $msg = 'Nobody matched this audience, so nothing was sent.';
    } else {
        $msg = 'Sending to ' . number_format_i18n( $campaign->recipients_total ) . ' people…';
    }
    kounselia_send_pure_json_success( array( 'message' => $msg, 'id' => $result, 'status' => $campaign->status ) );
}
add_action( 'wp_ajax_kounselia_admin_nl_send', 'kounselia_ajax_admin_nl_send' );

/**
 * Polled by the campaigns screen: pushes one batch through (so sending
 * progresses even on a quiet site), then reports progress.
 */
function kounselia_ajax_admin_nl_progress() {
    kounselia_newsletter_admin_guard();
    kounselia_newsletter_process_queue();
    global $wpdb;
    $ids  = array_filter( array_map( 'intval', explode( ',', (string) kounselia_post_field( 'ids' ) ) ) );
    $rows = array();
    if ( $ids ) {
        $rows = $wpdb->get_results( "SELECT id, status, recipients_total, sent_count, failed_count, open_count, click_count FROM {$wpdb->prefix}kounselia_campaigns WHERE id IN (" . implode( ',', $ids ) . ')' );
    }
    kounselia_send_pure_json_success( array( 'campaigns' => $rows ) );
}
add_action( 'wp_ajax_kounselia_admin_nl_progress', 'kounselia_ajax_admin_nl_progress' );

function kounselia_ajax_admin_nl_campaign_action() {
    kounselia_newsletter_admin_guard();
    global $wpdb;
    $id       = (int) kounselia_post_field( 'id', 0 );
    $do       = sanitize_key( kounselia_post_field( 'do' ) );
    $campaign = kounselia_newsletter_get_campaign( $id );
    if ( ! $campaign ) {
        kounselia_send_pure_json_error( array( 'message' => 'Campaign not found.' ), 404 );
    }

    if ( 'cancel' === $do ) {
        kounselia_newsletter_cancel_campaign( $id );
        kounselia_admin_log( 'cancelled_campaign', 'campaign', $id );
        kounselia_send_pure_json_success( array( 'message' => 'Stopped. Anyone not yet emailed will not receive it.' ) );
    }
    if ( 'duplicate' === $do ) {
        $new_id = kounselia_newsletter_save_campaign( array(
            'name' => $campaign->name . ' (copy)', 'subject' => $campaign->subject, 'preheader' => $campaign->preheader,
            'headline' => $campaign->headline, 'content' => $campaign->content, 'btn_text' => $campaign->btn_text,
            'btn_url' => $campaign->btn_url, 'list_key' => $campaign->list_key, 'segment_id' => $campaign->segment_id,
            'rules' => json_decode( (string) $campaign->rules, true ),
        ) );
        kounselia_admin_log( 'created_campaign', 'campaign', $new_id );
        kounselia_send_pure_json_success( array( 'message' => 'Copied.', 'id' => $new_id ) );
    }
    if ( 'delete' === $do ) {
        if ( in_array( $campaign->status, array( 'sending', 'scheduled' ), true ) ) {
            kounselia_send_pure_json_error( array( 'message' => 'Stop this campaign before deleting it.' ), 400 );
        }
        $wpdb->delete( $wpdb->prefix . 'kounselia_campaign_recipients', array( 'campaign_id' => $id ) );
        $wpdb->delete( $wpdb->prefix . 'kounselia_campaigns', array( 'id' => $id ) );
        $wpdb->update( $wpdb->prefix . 'kounselia_posts', array( 'notify_campaign_id' => null ), array( 'notify_campaign_id' => $id ) );
        kounselia_admin_log( 'deleted_campaign', 'campaign', $id );
        kounselia_send_pure_json_success( array( 'message' => 'Campaign deleted.' ) );
    }
    kounselia_send_pure_json_error( array( 'message' => 'Unknown action.' ), 400 );
}
add_action( 'wp_ajax_kounselia_admin_nl_campaign_action', 'kounselia_ajax_admin_nl_campaign_action' );

function kounselia_ajax_admin_nl_count() {
    kounselia_newsletter_admin_guard();
    $segment_id = (int) kounselia_post_field( 'segment_id', 0 );
    $list_key   = 'blog' === kounselia_post_field( 'list_key' ) ? 'blog' : 'newsletter';
    if ( $segment_id && ( $segment = kounselia_newsletter_get_segment( $segment_id ) ) ) {
        $rules = $segment->rules;
    } else {
        $rules = json_decode( (string) kounselia_post_field( 'rules', '{}' ), true );
    }
    $sample = array();
    foreach ( kounselia_newsletter_audience_sample( $rules, $list_key ) as $s ) {
        $sample[] = $s->name ? $s->name . ' <' . $s->email . '>' : $s->email;
    }
    kounselia_send_pure_json_success( array(
        'count'       => kounselia_newsletter_audience_count( $rules, $list_key ),
        'sample'      => $sample,
        'description' => kounselia_newsletter_describe_rules( $rules ),
    ) );
}
add_action( 'wp_ajax_kounselia_admin_nl_count', 'kounselia_ajax_admin_nl_count' );

function kounselia_ajax_admin_nl_save_segment() {
    kounselia_newsletter_admin_guard();
    global $wpdb;
    $id    = (int) kounselia_post_field( 'id', 0 );
    $name  = sanitize_text_field( kounselia_post_field( 'name' ) );
    $rules = kounselia_newsletter_normalize_rules( json_decode( (string) kounselia_post_field( 'rules', '{}' ), true ) );
    if ( '' === $name ) {
        kounselia_send_pure_json_error( array( 'message' => 'Please name this segment.' ), 400 );
    }
    $row = array(
        'name'        => mb_substr( $name, 0, 191 ),
        'description' => mb_substr( sanitize_text_field( kounselia_post_field( 'description' ) ), 0, 500 ),
        'rules'       => wp_json_encode( $rules ),
        'updated_at'  => current_time( 'mysql' ),
    );
    if ( $id ) {
        $wpdb->update( $wpdb->prefix . 'kounselia_segments', $row, array( 'id' => $id ) );
    } else {
        $row['created_by'] = get_current_user_id();
        $row['created_at'] = current_time( 'mysql' );
        $wpdb->insert( $wpdb->prefix . 'kounselia_segments', $row );
        $id = (int) $wpdb->insert_id;
    }
    kounselia_admin_log( 'saved_segment', 'segment', $id );
    kounselia_send_pure_json_success( array( 'message' => 'Segment saved.', 'id' => $id ) );
}
add_action( 'wp_ajax_kounselia_admin_nl_save_segment', 'kounselia_ajax_admin_nl_save_segment' );

function kounselia_ajax_admin_nl_delete_segment() {
    kounselia_newsletter_admin_guard();
    global $wpdb;
    $id = (int) kounselia_post_field( 'id', 0 );
    $in_use = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_campaigns WHERE segment_id = %d AND status IN ('draft','scheduled','sending')", $id ) );
    if ( $in_use ) {
        kounselia_send_pure_json_error( array( 'message' => 'This segment is used by a draft or scheduled campaign. Change that campaign first.' ), 400 );
    }
    $wpdb->delete( $wpdb->prefix . 'kounselia_segments', array( 'id' => $id ) );
    kounselia_admin_log( 'deleted_segment', 'segment', $id );
    kounselia_send_pure_json_success( array( 'message' => 'Segment deleted.' ) );
}
add_action( 'wp_ajax_kounselia_admin_nl_delete_segment', 'kounselia_ajax_admin_nl_delete_segment' );

/**
 * Add one contact by hand, or edit an existing one.
 */
function kounselia_ajax_admin_nl_save_subscriber() {
    kounselia_newsletter_admin_guard();
    $id    = (int) kounselia_post_field( 'id', 0 );
    $email = sanitize_email( kounselia_post_field( 'email' ) );
    if ( $id ) {
        $existing = kounselia_newsletter_get_subscriber( $id );
        if ( ! $existing ) {
            kounselia_send_pure_json_error( array( 'message' => 'Contact not found.' ), 404 );
        }
        $email = $existing->email;
    } elseif ( kounselia_newsletter_get_by_email( $email ) ) {
        kounselia_send_pure_json_error( array( 'message' => 'That email is already a contact.' ), 400 );
    }
    $user   = get_user_by( 'email', $email );
    $status = kounselia_post_field( 'status', 'subscribed' );
    $sub    = kounselia_newsletter_upsert( $email, array(
        'name'            => kounselia_post_field( 'name' ),
        'status'          => in_array( $status, array( 'subscribed', 'unsubscribed' ), true ) ? $status : 'subscribed',
        'list_newsletter' => '1' === kounselia_post_field( 'list_newsletter', '1' ),
        'list_blog'       => '1' === kounselia_post_field( 'list_blog', '1' ),
        'tags'            => kounselia_post_field( 'tags' ),
        'source'          => 'admin',
        'user_id'         => $user ? $user->ID : null,
    ) );
    if ( is_wp_error( $sub ) ) {
        kounselia_send_pure_json_error( array( 'message' => $sub->get_error_message() ), 400 );
    }
    kounselia_admin_log( $id ? 'edited_subscriber' : 'added_subscriber', 'subscriber', $sub->id );
    kounselia_send_pure_json_success( array( 'message' => 'Contact saved.', 'id' => (int) $sub->id ) );
}
add_action( 'wp_ajax_kounselia_admin_nl_save_subscriber', 'kounselia_ajax_admin_nl_save_subscriber' );

/**
 * Bulk actions on selected contacts: tag, untag, unsubscribe,
 * resubscribe, delete.
 */
function kounselia_ajax_admin_nl_bulk_subscribers() {
    kounselia_newsletter_admin_guard();
    global $wpdb;
    $ids = array_slice( array_filter( array_map( 'intval', explode( ',', (string) kounselia_post_field( 'ids' ) ) ) ), 0, 1000 );
    $do  = sanitize_key( kounselia_post_field( 'do' ) );
    $tag = kounselia_newsletter_clean_tags( kounselia_post_field( 'tag' ) );
    if ( ! $ids ) {
        kounselia_send_pure_json_error( array( 'message' => 'Select at least one contact.' ), 400 );
    }
    $done = 0;
    foreach ( $ids as $id ) {
        $sub = kounselia_newsletter_get_subscriber( $id );
        if ( ! $sub ) {
            continue;
        }
        if ( 'delete' === $do ) {
            $wpdb->delete( kounselia_newsletter_table(), array( 'id' => $id ) );
        } elseif ( 'tag' === $do && $tag ) {
            kounselia_newsletter_upsert( $sub->email, array( 'add_tags' => $tag ) );
        } elseif ( 'untag' === $do && $tag ) {
            $remaining = array_diff( explode( ',', (string) $sub->tags ), explode( ',', $tag ) );
            kounselia_newsletter_upsert( $sub->email, array( 'tags' => implode( ',', $remaining ) ) );
        } elseif ( 'unsubscribe' === $do ) {
            kounselia_newsletter_upsert( $sub->email, array( 'status' => 'unsubscribed' ) );
        } elseif ( 'resubscribe' === $do ) {
            kounselia_newsletter_upsert( $sub->email, array( 'status' => 'subscribed' ) );
        } else {
            continue;
        }
        $done++;
    }
    kounselia_admin_log( 'bulk_subscribers_' . $do, 'subscriber', $done );
    kounselia_send_pure_json_success( array( 'message' => number_format_i18n( $done ) . ' contact' . ( 1 === $done ? '' : 's' ) . ' updated.' ) );
}
add_action( 'wp_ajax_kounselia_admin_nl_bulk_subscribers', 'kounselia_ajax_admin_nl_bulk_subscribers' );

/**
 * CSV import. Columns are matched by header name (email, name, tags);
 * without a header row the first column is the email. Existing
 * contacts are updated (name/tags added) but never re-subscribed if
 * they unsubscribed — an import must not override someone's choice.
 */
function kounselia_ajax_admin_nl_import() {
    kounselia_newsletter_admin_guard();
    $csv = (string) kounselia_post_field( 'csv' );
    if ( ! empty( $_FILES['file']['tmp_name'] ) && is_uploaded_file( $_FILES['file']['tmp_name'] ) ) {
        $csv = (string) file_get_contents( $_FILES['file']['tmp_name'], false, null, 0, 5 * MB_IN_BYTES );
    }
    $extra_tags = kounselia_newsletter_clean_tags( kounselia_post_field( 'tags' ) );
    $lines      = preg_split( '/\r\n|\r|\n/', trim( $csv ) );
    if ( ! $lines || '' === trim( $lines[0] ) ) {
        kounselia_send_pure_json_error( array( 'message' => 'The file is empty.' ), 400 );
    }

    $header  = array_map( function ( $h ) { return strtolower( trim( $h ) ); }, str_getcsv( $lines[0] ) );
    $has_hdr = in_array( 'email', $header, true ) || in_array( 'e-mail', $header, true );
    $col     = array(
        'email' => $has_hdr ? max( (int) array_search( 'email', $header, true ), (int) array_search( 'e-mail', $header, true ) ) : 0,
        'name'  => $has_hdr ? array_search( 'name', $header, true ) : 1,
        'tags'  => $has_hdr ? array_search( 'tags', $header, true ) : false,
    );
    if ( $has_hdr ) {
        array_shift( $lines );
    }

    $added = $updated = $skipped = 0;
    foreach ( array_slice( $lines, 0, 20000 ) as $line ) {
        if ( '' === trim( $line ) ) {
            continue;
        }
        $cells = str_getcsv( $line );
        $email = strtolower( trim( $cells[ $col['email'] ] ?? '' ) );
        if ( ! is_email( $email ) ) {
            $skipped++;
            continue;
        }
        $args = array( 'source' => 'import', 'add_tags' => trim( ( false !== $col['tags'] ? ( $cells[ $col['tags'] ] ?? '' ) : '' ) . ',' . $extra_tags, ',' ) );
        if ( false !== $col['name'] && ! empty( $cells[ $col['name'] ] ) ) {
            $args['name'] = $cells[ $col['name'] ];
        }
        $exists = kounselia_newsletter_get_by_email( $email );
        if ( ! $exists ) {
            $user            = get_user_by( 'email', $email );
            $args['user_id'] = $user ? $user->ID : null;
        }
        kounselia_newsletter_upsert( $email, $args );
        $exists ? $updated++ : $added++;
    }
    kounselia_admin_log( 'imported_subscribers', 'subscriber', $added );
    kounselia_send_pure_json_success( array( 'message' => sprintf( '%s added, %s updated, %s skipped (invalid email).', number_format_i18n( $added ), number_format_i18n( $updated ), number_format_i18n( $skipped ) ) ) );
}
add_action( 'wp_ajax_kounselia_admin_nl_import', 'kounselia_ajax_admin_nl_import' );

/**
 * CSV download of contacts (optionally only one audience).
 */
function kounselia_ajax_admin_nl_export() {
    check_admin_referer( 'kounselia_admin_nonce', 'nonce' );
    if ( ! kounselia_admin_can( 'broadcasts' ) ) {
        wp_die( 'You do not have access to the newsletter.' );
    }
    global $wpdb;
    $status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
    $sql    = 'SELECT s.* FROM ' . kounselia_newsletter_table() . ' s';
    if ( in_array( $status, array( 'subscribed', 'unsubscribed', 'pending' ), true ) ) {
        $sql .= $wpdb->prepare( ' WHERE s.status = %s', $status );
    }
    $rows = $wpdb->get_results( $sql . ' ORDER BY s.id ASC' );

    kounselia_admin_log( 'exported_subscribers', 'subscriber', count( $rows ) );
    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="kounselia-contacts-' . date( 'Y-m-d' ) . '.csv"' );
    $out = fopen( 'php://output', 'w' );
    fputcsv( $out, array( 'email', 'name', 'status', 'newsletter', 'blog', 'member', 'source', 'tags', 'joined', 'last_emailed' ) );
    foreach ( $rows as $r ) {
        // Guard against spreadsheet formula injection from user-supplied names.
        $name = preg_match( '/^[=+\-@]/', (string) $r->name ) ? "'" . $r->name : $r->name;
        fputcsv( $out, array( $r->email, $name, $r->status, $r->list_newsletter, $r->list_blog, $r->user_id ? 'yes' : 'no', $r->source, $r->tags, $r->created_at, $r->last_emailed_at ) );
    }
    fclose( $out );
    exit;
}
add_action( 'wp_ajax_kounselia_admin_nl_export', 'kounselia_ajax_admin_nl_export' );

function kounselia_ajax_admin_nl_save_settings() {
    kounselia_newsletter_admin_guard();
    update_option( 'kounselia_newsletter_settings', array(
        'double_optin'           => '1' === kounselia_post_field( 'double_optin' ) ? 1 : 0,
        'auto_subscribe_members' => '1' === kounselia_post_field( 'auto_subscribe_members' ) ? 1 : 0,
        'batch_size'             => max( 5, min( 500, (int) kounselia_post_field( 'batch_size', 40 ) ) ),
        'track_opens'            => '1' === kounselia_post_field( 'track_opens' ) ? 1 : 0,
        'track_clicks'           => '1' === kounselia_post_field( 'track_clicks' ) ? 1 : 0,
        'footer_address'         => sanitize_text_field( kounselia_post_field( 'footer_address' ) ),
        'welcome_enabled'        => '1' === kounselia_post_field( 'welcome_enabled' ) ? 1 : 0,
        'welcome_subject'        => sanitize_text_field( kounselia_post_field( 'welcome_subject' ) ) ?: 'Welcome to Kounselia',
        'welcome_body'           => sanitize_textarea_field( kounselia_post_field( 'welcome_body' ) ),
    ) );
    kounselia_admin_log( 'edited_newsletter_settings', 'settings', 0 );
    kounselia_send_pure_json_success( array( 'message' => 'Settings saved.' ) );
}
add_action( 'wp_ajax_kounselia_admin_nl_save_settings', 'kounselia_ajax_admin_nl_save_settings' );
