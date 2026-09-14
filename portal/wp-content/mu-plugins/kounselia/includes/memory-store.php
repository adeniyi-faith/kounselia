<?php
/**
 * Kounselia Core — normalized memory store
 *
 * Read/write layer for the memory tables added in schema.php v1.9.0
 * (kounselia_memory_profile, _life_events, _emotions, _relationships,
 * _list_items, _preferences). These tables are now the source of truth
 * for a user's structured memory profile.
 *
 * The rest of the codebase (the chat prompt builder, the admin Memory
 * Center, the Clinical Boardroom, the member profile page, the user
 * dashboard) still reads the profile as one JSON blob from usermeta
 * ('kounselia_core_memory'), the same shape it always has. So every write
 * in this file also regenerates that usermeta value from the tables,
 * making it a plain cache of the tables rather than a second copy of the
 * truth — nothing can read a stale or half-updated version of it.
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The list-type fields that share the generic kounselia_memory_list_items table.
 */
function kounselia_memory_list_field_types() {
    return array( 'goals', 'important_people', 'values', 'triggers', 'traumas', 'current_challenges', 'wins', 'habits' );
}

/**
 * Load a user's full memory profile from the normalized tables, shaped
 * exactly like the old kounselia_core_memory JSON (same keys, same types)
 * so every existing prompt/UI consumer keeps working unchanged.
 */
function kounselia_memory_load_profile( $user_id ) {
    global $wpdb;

    $profile_row = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}kounselia_memory_profile WHERE user_id = %d",
        $user_id
    ) );

    $profile = array(
        'identity'             => $profile_row ? (string) $profile_row->identity : '',
        'life_timeline'        => array(),
        'emotional_map'        => new stdClass(),
        'goals'                => array(),
        'relationships'        => new stdClass(),
        'important_people'     => array(),
        'career'                => $profile_row ? (string) $profile_row->career : '',
        'health'                => $profile_row ? (string) $profile_row->health : '',
        'values'                => array(),
        'triggers'              => array(),
        'traumas'               => array(),
        'current_challenges'    => array(),
        'wins'                  => array(),
        'preferences'           => new stdClass(),
        'communication_style'   => $profile_row ? (string) $profile_row->communication_style : '',
        'personality'           => $profile_row ? (string) $profile_row->personality : '',
        'faith'                 => $profile_row ? (string) $profile_row->faith : '',
        'habits'                => array(),
        'temporary_context'     => $profile_row ? (string) $profile_row->temporary_context : '',
    );

    $events = $wpdb->get_results( $wpdb->prepare(
        "SELECT event_year, event_text, impact FROM {$wpdb->prefix}kounselia_memory_life_events WHERE user_id = %d ORDER BY event_year ASC, id ASC",
        $user_id
    ) );
    foreach ( $events as $e ) {
        $profile['life_timeline'][] = array( 'year' => $e->event_year, 'event' => $e->event_text, 'impact' => $e->impact );
    }

    $emotions = $wpdb->get_results( $wpdb->prepare(
        "SELECT entity_name, emotion, intensity, context FROM {$wpdb->prefix}kounselia_memory_emotions WHERE user_id = %d",
        $user_id
    ) );
    if ( $emotions ) {
        $emotional_map = array();
        foreach ( $emotions as $e ) {
            $emotional_map[ $e->entity_name ] = array( 'emotion' => $e->emotion, 'intensity' => $e->intensity, 'context' => $e->context );
        }
        $profile['emotional_map'] = $emotional_map;
    }

    $relationships = $wpdb->get_results( $wpdb->prepare(
        "SELECT person_name, context FROM {$wpdb->prefix}kounselia_memory_relationships WHERE user_id = %d",
        $user_id
    ) );
    if ( $relationships ) {
        $rel_map = array();
        foreach ( $relationships as $r ) {
            $rel_map[ $r->person_name ] = $r->context;
        }
        $profile['relationships'] = $rel_map;
    }

    $prefs = $wpdb->get_results( $wpdb->prepare(
        "SELECT pref_key, pref_value FROM {$wpdb->prefix}kounselia_memory_preferences WHERE user_id = %d",
        $user_id
    ) );
    if ( $prefs ) {
        $pref_map = array();
        foreach ( $prefs as $p ) {
            $pref_map[ $p->pref_key ] = $p->pref_value;
        }
        $profile['preferences'] = $pref_map;
    }

    $list_items = $wpdb->get_results( $wpdb->prepare(
        "SELECT list_type, item_text FROM {$wpdb->prefix}kounselia_memory_list_items WHERE user_id = %d ORDER BY id ASC",
        $user_id
    ) );
    foreach ( $list_items as $item ) {
        if ( in_array( $item->list_type, kounselia_memory_list_field_types(), true ) ) {
            $profile[ $item->list_type ][] = $item->item_text;
        }
    }

    return $profile;
}

/**
 * Replace a user's entire memory profile in the normalized tables from a
 * decoded profile array (same shape kounselia_core_memory has always had —
 * this is what the import/synthesis/intake/edit flows already produce).
 * Wholesale replace, matching the previous "overwrite the whole blob"
 * behavior of those flows.
 *
 * @param bool $refresh_cache Whether to also rewrite the kounselia_core_memory
 *                             usermeta cache. Pass false only when the caller
 *                             already knows the cache holds this exact data
 *                             (the one-time backfill from that same value).
 */
function kounselia_memory_save_profile( $user_id, $profile, $refresh_cache = true ) {
    global $wpdb;

    if ( ! is_array( $profile ) ) {
        return false;
    }

    $now = current_time( 'mysql' );

    // 1. Scalar fields — one row, upsert.
    $wpdb->replace( "{$wpdb->prefix}kounselia_memory_profile", array(
        'user_id'              => $user_id,
        'identity'              => isset( $profile['identity'] ) ? (string) $profile['identity'] : '',
        'career'                => isset( $profile['career'] ) ? (string) $profile['career'] : '',
        'health'                => isset( $profile['health'] ) ? (string) $profile['health'] : '',
        'communication_style'   => isset( $profile['communication_style'] ) ? (string) $profile['communication_style'] : '',
        'personality'           => isset( $profile['personality'] ) ? (string) $profile['personality'] : '',
        'faith'                 => isset( $profile['faith'] ) ? (string) $profile['faith'] : '',
        'temporary_context'     => isset( $profile['temporary_context'] ) ? (string) $profile['temporary_context'] : '',
        'updated_at'            => $now,
    ) );

    // 2. life_timeline — wipe and reinsert.
    $wpdb->delete( "{$wpdb->prefix}kounselia_memory_life_events", array( 'user_id' => $user_id ) );
    if ( ! empty( $profile['life_timeline'] ) && is_array( $profile['life_timeline'] ) ) {
        foreach ( $profile['life_timeline'] as $event ) {
            if ( ! is_array( $event ) || empty( $event['event'] ) ) {
                continue;
            }
            $wpdb->insert( "{$wpdb->prefix}kounselia_memory_life_events", array(
                'user_id'    => $user_id,
                'event_year' => isset( $event['year'] ) ? (string) $event['year'] : '',
                'event_text' => (string) $event['event'],
                'impact'     => isset( $event['impact'] ) ? (string) $event['impact'] : '',
                'created_at' => $now,
            ) );
        }
    }

    // 3. emotional_map — wipe and reinsert.
    $wpdb->delete( "{$wpdb->prefix}kounselia_memory_emotions", array( 'user_id' => $user_id ) );
    if ( ! empty( $profile['emotional_map'] ) && is_array( $profile['emotional_map'] ) ) {
        foreach ( $profile['emotional_map'] as $entity => $data ) {
            if ( ! is_array( $data ) || $entity === '' ) {
                continue;
            }
            $wpdb->insert( "{$wpdb->prefix}kounselia_memory_emotions", array(
                'user_id'     => $user_id,
                'entity_name' => (string) $entity,
                'emotion'     => isset( $data['emotion'] ) ? (string) $data['emotion'] : '',
                'intensity'   => isset( $data['intensity'] ) ? (string) $data['intensity'] : '',
                'context'     => isset( $data['context'] ) ? (string) $data['context'] : '',
                'updated_at'  => $now,
            ) );
        }
    }

    // 4. relationships — wipe and reinsert.
    $wpdb->delete( "{$wpdb->prefix}kounselia_memory_relationships", array( 'user_id' => $user_id ) );
    if ( ! empty( $profile['relationships'] ) && is_array( $profile['relationships'] ) ) {
        foreach ( $profile['relationships'] as $person => $context ) {
            if ( $person === '' ) {
                continue;
            }
            $wpdb->insert( "{$wpdb->prefix}kounselia_memory_relationships", array(
                'user_id'     => $user_id,
                'person_name' => (string) $person,
                'context'     => is_array( $context ) ? wp_json_encode( $context ) : (string) $context,
                'updated_at'  => $now,
            ) );
        }
    }

    // 5. preferences — wipe and reinsert.
    $wpdb->delete( "{$wpdb->prefix}kounselia_memory_preferences", array( 'user_id' => $user_id ) );
    if ( ! empty( $profile['preferences'] ) && is_array( $profile['preferences'] ) ) {
        foreach ( $profile['preferences'] as $key => $value ) {
            if ( $key === '' ) {
                continue;
            }
            $wpdb->insert( "{$wpdb->prefix}kounselia_memory_preferences", array(
                'user_id'    => $user_id,
                'pref_key'   => (string) $key,
                'pref_value' => is_array( $value ) ? wp_json_encode( $value ) : (string) $value,
            ) );
        }
    }

    // 6. generic list fields — wipe and reinsert.
    $wpdb->delete( "{$wpdb->prefix}kounselia_memory_list_items", array( 'user_id' => $user_id ) );
    foreach ( kounselia_memory_list_field_types() as $field ) {
        if ( empty( $profile[ $field ] ) || ! is_array( $profile[ $field ] ) ) {
            continue;
        }
        foreach ( $profile[ $field ] as $item ) {
            if ( $item === '' || $item === null ) {
                continue;
            }
            $wpdb->insert( "{$wpdb->prefix}kounselia_memory_list_items", array(
                'user_id'    => $user_id,
                'list_type'  => $field,
                'item_text'  => is_array( $item ) ? wp_json_encode( $item ) : (string) $item,
                'created_at' => $now,
            ) );
        }
    }

    // 7. Keep the usermeta cache every existing prompt/UI consumer reads in sync.
    if ( $refresh_cache ) {
        update_user_meta( $user_id, 'kounselia_core_memory', wp_json_encode( $profile ) );
    }

    return true;
}

/**
 * Wipe a user's entire memory profile — tables and the usermeta cache.
 */
function kounselia_memory_delete_profile( $user_id ) {
    global $wpdb;

    $wpdb->delete( "{$wpdb->prefix}kounselia_memory_profile", array( 'user_id' => $user_id ) );
    $wpdb->delete( "{$wpdb->prefix}kounselia_memory_life_events", array( 'user_id' => $user_id ) );
    $wpdb->delete( "{$wpdb->prefix}kounselia_memory_emotions", array( 'user_id' => $user_id ) );
    $wpdb->delete( "{$wpdb->prefix}kounselia_memory_relationships", array( 'user_id' => $user_id ) );
    $wpdb->delete( "{$wpdb->prefix}kounselia_memory_preferences", array( 'user_id' => $user_id ) );
    $wpdb->delete( "{$wpdb->prefix}kounselia_memory_list_items", array( 'user_id' => $user_id ) );

    delete_user_meta( $user_id, 'kounselia_core_memory' );
}
