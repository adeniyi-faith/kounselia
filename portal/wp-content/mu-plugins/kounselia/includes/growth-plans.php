<?php
/**
 * Kounselia Core — Growth plans (Personal Development).
 *
 * A member picks something they want to work on (discipline, confidence,
 * procrastination…), answers a few short questions, and the AI writes
 * them a 30 day plan: one small task a day. Each day they can tick the
 * task off, and the dashboard (website and app) shows today's task and
 * how far along they are.
 *
 *   - One plan runs at a time. Starting another means finishing or
 *     ending the current one first.
 *   - Missed days don't reset anything: the plan keeps moving with the
 *     calendar, and an earlier day can still be ticked off later.
 *   - How many plans a member can start is a plan benefit
 *     ('growth_plans' in membership.php, 0 = unlimited). A plan ended on
 *     the same day it was started doesn't count, so someone who changes
 *     their mind straight away gets that plan back.
 *   - Daily reminder: a push and in-app notification with today's task
 *     at the hour the member picks (9am unless they change it or turn it
 *     off), sent by an hourly job. Uses the site's time zone.
 *   - Weekly review: after each week (days 7, 14, 21, 28 and the final
 *     day 30) the member can ask for a short review. The AI looks at what
 *     they did, writes a few kind sentences, and can make the next days
 *     easier or harder. One review per week; it is saved with the plan.
 *   - The counselor who goes with plans (Noa, Personal Development) is
 *     told about the member's plan in chat, so they can help with
 *     today's step.
 *
 * Both apps call the same actions: kounselia_growth_get,
 * kounselia_growth_create, kounselia_growth_mark_day, kounselia_growth_end.
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'KOUNSELIA_GROWTH_PLAN_DAYS', 30 );

/**
 * What a member can choose to work on. 'goal' is the first question,
 * written for that area; 'obstacle_hint' is the example under the second.
 */
function kounselia_growth_areas() {
    return array(
        'discipline'      => array(
            'label'         => 'Discipline',
            'icon'          => 'ti-target',
            'blurb'         => 'Follow through on what you say you will do.',
            'goal'          => 'What would being more disciplined look like for you? Name one thing you would be doing differently.',
            'obstacle_hint' => 'For example: I start strong, then stop after a few days.',
        ),
        'procrastination' => array(
            'label'         => 'Beating procrastination',
            'icon'          => 'ti-hourglass',
            'blurb'         => 'Stop putting off the things that matter.',
            'goal'          => 'What are you putting off right now that you most want to get moving on?',
            'obstacle_hint' => 'For example: I scroll on my phone, or the task feels too big to start.',
        ),
        'habits'          => array(
            'label'         => 'Building better habits',
            'icon'          => 'ti-repeat',
            'blurb'         => 'Start a good habit, or let go of one that holds you back.',
            'goal'          => 'Which habit do you want to build, or which one do you want to break?',
            'obstacle_hint' => 'For example: I forget, or I am too tired in the evening.',
        ),
        'time'            => array(
            'label'         => 'Time management and productivity',
            'icon'          => 'ti-clock',
            'blurb'         => 'Get the important things done without burning out.',
            'goal'          => 'Where does your time go that you wish it did not? What would a good day look like?',
            'obstacle_hint' => 'For example: meetings, too many tasks at once, no plan for the day.',
        ),
        'goals'           => array(
            'label'         => 'Goal setting',
            'icon'          => 'ti-flag',
            'blurb'         => 'Turn something you want into steps you can take.',
            'goal'          => 'What is one goal you want to make real progress on in the next 30 days?',
            'obstacle_hint' => 'For example: I do not know where to start, or I lose motivation.',
        ),
        'confidence'      => array(
            'label'         => 'Becoming more confident',
            'icon'          => 'ti-star',
            'blurb'         => 'Trust yourself more and speak up for yourself.',
            'goal'          => 'Where in your life do you most want to feel more confident?',
            'obstacle_hint' => 'For example: I worry what people think, or I talk myself down.',
        ),
        'growth'          => array(
            'label'         => 'Personal growth',
            'icon'          => 'ti-plant-2',
            'blurb'         => 'Become more of the person you want to be.',
            'goal'          => 'What part of yourself do you most want to grow or improve right now?',
            'obstacle_hint' => 'For example: old patterns, busy life, not sure what to change first.',
        ),
    );
}

/**
 * The short questions asked before a plan is written. The first is
 * written for the chosen area; the rest are the same for every area.
 */
function kounselia_growth_questions( $area_key ) {
    $areas = kounselia_growth_areas();
    $area  = isset( $areas[ $area_key ] ) ? $areas[ $area_key ] : reset( $areas );
    return array(
        array( 'key' => 'goal', 'type' => 'text', 'required' => true, 'label' => $area['goal'], 'hint' => '' ),
        array( 'key' => 'obstacle', 'type' => 'text', 'required' => false, 'label' => 'What usually gets in the way?', 'hint' => $area['obstacle_hint'] ),
        array(
            'key'      => 'time',
            'type'     => 'choice',
            'required' => true,
            'label'    => 'How much time can you give this on most days?',
            'hint'     => '',
            'choices'  => array(
                array( 'key' => '5', 'label' => 'About 5 minutes' ),
                array( 'key' => '15', 'label' => 'About 15 minutes' ),
                array( 'key' => '30', 'label' => '30 minutes or more' ),
            ),
        ),
        array( 'key' => 'life', 'type' => 'text', 'required' => false, 'label' => 'Is anything going on in your life that the plan should work around?', 'hint' => 'For example: exams, a new job, young children, night shifts.' ),
        array( 'key' => 'success', 'type' => 'text', 'required' => false, 'label' => 'Thirty days from now, how will you know it worked?', 'hint' => '' ),
    );
}

/**
 * Who talks with members about their plan: Noa when she's available,
 * otherwise the first counselor members can talk to.
 */
function kounselia_growth_counselor_slug() {
    $slugs = function_exists( 'kounselia_public_counselors' ) ? wp_list_pluck( kounselia_public_counselors(), 'slug' ) : array();
    if ( in_array( 'noa', $slugs, true ) || empty( $slugs ) ) {
        return 'noa';
    }
    return $slugs[0];
}

/* -------------------------------------------------------------------------
 * Reading plans
 * ---------------------------------------------------------------------- */

function kounselia_growth_table() {
    global $wpdb;
    return $wpdb->prefix . 'kounselia_growth_plans';
}

function kounselia_growth_progress_table() {
    global $wpdb;
    return $wpdb->prefix . 'kounselia_growth_plan_progress';
}

/**
 * The member's running plan (a database row), or null. A plan whose 30
 * days have all passed is marked completed here, the first time anyone
 * looks at it afterwards.
 */
function kounselia_growth_active_plan( $user_id ) {
    global $wpdb;
    $table = kounselia_growth_table();
    $row   = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE user_id = %d AND status = 'active' ORDER BY id DESC LIMIT 1",
        $user_id
    ) );
    if ( ! $row ) {
        return null;
    }
    if ( kounselia_growth_day_number( $row ) > (int) $row->total_days ) {
        $wpdb->update( $table, array( 'status' => 'completed', 'ended_at' => current_time( 'mysql' ) ), array( 'id' => $row->id ) );
        return null;
    }
    return $row;
}

/**
 * Which day of the plan it is today (1 on the day it was started), in
 * the site's own time zone. Can be past the last day.
 */
function kounselia_growth_day_number( $row ) {
    $start = strtotime( $row->start_date . ' 00:00:00' );
    $today = strtotime( current_time( 'Y-m-d' ) . ' 00:00:00' );
    return (int) floor( ( $today - $start ) / DAY_IN_SECONDS ) + 1;
}

/**
 * Day numbers the member has ticked off for a plan.
 *
 * @return int[]
 */
function kounselia_growth_done_days( $plan_id ) {
    global $wpdb;
    $table = kounselia_growth_progress_table();
    return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
        "SELECT day_number FROM {$table} WHERE plan_id = %d ORDER BY day_number ASC",
        $plan_id
    ) ) );
}

/**
 * Days in a row ticked off, counting back from today (or from yesterday
 * when today's task isn't done yet, so the streak doesn't look broken
 * first thing in the morning).
 */
function kounselia_growth_streak( $current_day, $done ) {
    $done   = array_flip( $done );
    $day    = isset( $done[ $current_day ] ) ? $current_day : $current_day - 1;
    $streak = 0;
    while ( $day >= 1 && isset( $done[ $day ] ) ) {
        $streak++;
        $day--;
    }
    return $streak;
}

/**
 * A plan row as the apps see it.
 */
function kounselia_growth_format_plan( $row, $with_days = true ) {
    $areas   = kounselia_growth_areas();
    $area    = isset( $areas[ $row->area ] ) ? $areas[ $row->area ] : array( 'label' => $row->area, 'icon' => 'ti-plant-2' );
    $total   = (int) $row->total_days;
    $current = max( 1, min( $total, kounselia_growth_day_number( $row ) ) );
    $done    = kounselia_growth_done_days( $row->id );
    $days    = json_decode( (string) $row->days, true );
    $days    = is_array( $days ) ? $days : array();

    $format_day = function ( $d ) use ( $done, $current ) {
        $n = (int) $d['day'];
        return array(
            'day'     => $n,
            'title'   => (string) $d['title'],
            'task'    => (string) $d['task'],
            'minutes' => (int) $d['minutes'],
            'done'    => in_array( $n, $done, true ),
            'state'   => $n < $current ? 'past' : ( $n === $current ? 'today' : 'upcoming' ),
        );
    };

    $today = null;
    foreach ( $days as $d ) {
        if ( (int) $d['day'] === $current ) {
            $today = $format_day( $d );
        }
    }

    $out = array(
        'id'             => (int) $row->id,
        'area'           => $row->area,
        'area_label'     => $area['label'],
        'icon'           => preg_replace( '/^ti-/', '', $area['icon'] ),
        'title'          => $row->title,
        'summary'        => (string) $row->summary,
        'status'         => $row->status,
        'start_date'     => $row->start_date,
        'current_day'    => $current,
        'total_days'     => $total,
        'done_count'     => count( $done ),
        'streak'         => kounselia_growth_streak( $current, $done ),
        'today'          => $today,
        'counselor_slug' => kounselia_growth_counselor_slug(),
        'remind_hour'    => (int) $row->remind_hour, // 0-23 in site time, -1 = off
        'reviews'        => array_values( kounselia_growth_reviews( $row ) ),
        'review_ready'   => kounselia_growth_next_review( $row ),
    );
    if ( $with_days ) {
        $out['days'] = array_map( $format_day, $days );
    }
    return $out;
}

/**
 * The member's earlier plans (finished or ended), newest first.
 */
function kounselia_growth_previous_plans( $user_id, $limit = 10 ) {
    global $wpdb;
    $table    = kounselia_growth_table();
    $progress = kounselia_growth_progress_table();
    $rows     = $wpdb->get_results( $wpdb->prepare(
        "SELECT p.id, p.area, p.title, p.status, p.start_date, p.total_days,
                (SELECT COUNT(*) FROM {$progress} g WHERE g.plan_id = p.id) AS done_count
         FROM {$table} p WHERE p.user_id = %d AND p.status <> 'active' ORDER BY p.id DESC LIMIT %d",
        $user_id,
        $limit
    ) );
    $areas = kounselia_growth_areas();
    return array_map( function ( $r ) use ( $areas ) {
        return array(
            'id'         => (int) $r->id,
            'title'      => $r->title,
            'area_label' => isset( $areas[ $r->area ] ) ? $areas[ $r->area ]['label'] : $r->area,
            'status'     => $r->status,
            'start_date' => $r->start_date,
            'done_count' => (int) $r->done_count,
            'total_days' => (int) $r->total_days,
        );
    }, $rows );
}

/**
 * How many plans the member can start on their plan, and how many they
 * have used. limit 0 = unlimited (remaining is then null).
 */
function kounselia_growth_allowance( $user_id ) {
    global $wpdb;
    $limit = function_exists( 'kounselia_member_benefit' ) ? (int) kounselia_member_benefit( $user_id, 'growth_plans' ) : 0;
    $table = kounselia_growth_table();
    // A plan ended on the day it started is given back.
    $used = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND NOT ( status = 'ended' AND DATE(ended_at) = start_date )",
        $user_id
    ) );
    return array(
        'limit'     => $limit,
        'used'      => $used,
        'remaining' => $limit ? max( 0, $limit - $used ) : null,
    );
}

/**
 * Everything the "Growth plan" screen needs.
 */
function kounselia_growth_overview( $user_id ) {
    $areas = array();
    foreach ( kounselia_growth_areas() as $key => $a ) {
        $areas[] = array(
            'key'       => $key,
            'label'     => $a['label'],
            'icon'      => preg_replace( '/^ti-/', '', $a['icon'] ),
            'blurb'     => $a['blurb'],
            'questions' => kounselia_growth_questions( $key ),
        );
    }
    $active = kounselia_growth_active_plan( $user_id );
    return array(
        'plan'      => $active ? kounselia_growth_format_plan( $active ) : null,
        'previous'  => kounselia_growth_previous_plans( $user_id ),
        'areas'     => $areas,
        'allowance' => kounselia_growth_allowance( $user_id ),
        'is_pro'    => function_exists( 'kounselia_member_is_pro' ) && kounselia_member_is_pro( $user_id ),
    );
}

/**
 * The Home card's summary of the running plan (no day list), or null.
 */
function kounselia_growth_home_summary( $user_id ) {
    $active = kounselia_growth_active_plan( $user_id );
    return $active ? kounselia_growth_format_plan( $active, false ) : null;
}

/* -------------------------------------------------------------------------
 * Writing a plan with the AI
 * ---------------------------------------------------------------------- */

/**
 * Cleans the member's answers: only known questions, trimmed, at most
 * 600 characters each. Returns WP_Error when a required one is missing.
 */
function kounselia_growth_clean_answers( $area_key, $answers ) {
    $clean = array();
    foreach ( kounselia_growth_questions( $area_key ) as $q ) {
        $value = isset( $answers[ $q['key'] ] ) ? trim( sanitize_textarea_field( (string) $answers[ $q['key'] ] ) ) : '';
        if ( 'choice' === $q['type'] && '' !== $value && ! in_array( $value, wp_list_pluck( $q['choices'], 'key' ), true ) ) {
            $value = '';
        }
        if ( $q['required'] && '' === $value ) {
            return new WP_Error( 'missing_answer', 'Please answer: ' . $q['label'] );
        }
        $clean[ $q['key'] ] = mb_substr( $value, 0, 600 );
    }
    return $clean;
}

function kounselia_growth_build_prompt( $area_key, $answers, $first_name ) {
    $areas = kounselia_growth_areas();
    $area  = $areas[ $area_key ];
    $lines = array();
    foreach ( kounselia_growth_questions( $area_key ) as $q ) {
        $answer = $answers[ $q['key'] ] ?? '';
        if ( 'choice' === $q['type'] ) {
            foreach ( $q['choices'] as $c ) {
                if ( $c['key'] === $answer ) {
                    $answer = $c['label'];
                }
            }
        }
        $lines[] = 'Q: ' . $q['label'] . "\nA: " . ( '' !== $answer ? $answer : '(skipped)' );
    }

    return "Write a " . KOUNSELIA_GROWTH_PLAN_DAYS . " day personal development plan for "
        . ( $first_name ? $first_name : 'this person' ) . ".\n\n"
        . "FOCUS AREA: {$area['label']}\n\n"
        . "THEIR ANSWERS:\n" . implode( "\n\n", $lines ) . "\n\n"
        . "RULES:\n"
        . "- Exactly " . KOUNSELIA_GROWTH_PLAN_DAYS . " days, numbered 1 to " . KOUNSELIA_GROWTH_PLAN_DAYS . ", one task per day.\n"
        . "- Each task is one small, concrete action they can do today, sized to the time they said they have. Start very small in week one and build up gently.\n"
        . "- Make it about THEIR goal and THEIR obstacles, not generic advice. Work around what is going on in their life.\n"
        . "- Every 7th day (days 7, 14, 21, 28) is a short reflection on how the week went. Day 30 looks back on the whole month and picks what to keep doing.\n"
        . "- Plain, warm, everyday English, speaking to them as 'you'. No jargon, no markdown, no emojis.\n"
        . "- No medical, medication or diagnosis advice.\n\n"
        . "Return ONLY JSON in this shape:\n"
        . '{"title": "a short name for the plan, at most 6 words", "summary": "two sentences on what this month is about", "days": [{"day": 1, "title": "at most 6 words", "task": "one or two sentences saying exactly what to do", "minutes": 5}]}';
}

/**
 * Turns the AI's reply into a clean plan, or null if it isn't usable.
 *
 * @return array{title:string,summary:string,days:array}|null
 */
function kounselia_growth_parse_plan( $raw ) {
    $raw  = trim( preg_replace( '/^```(?:json)?|```$/m', '', (string) $raw ) );
    $data = json_decode( $raw, true );
    if ( ! is_array( $data ) || empty( $data['days'] ) || ! is_array( $data['days'] ) ) {
        return null;
    }

    $days = array();
    foreach ( $data['days'] as $d ) {
        if ( ! is_array( $d ) ) {
            continue;
        }
        $task = isset( $d['task'] ) ? trim( sanitize_textarea_field( (string) $d['task'] ) ) : '';
        if ( '' === $task ) {
            continue;
        }
        $days[] = array(
            'day'     => count( $days ) + 1,
            'title'   => mb_substr( trim( sanitize_text_field( (string) ( $d['title'] ?? '' ) ) ), 0, 80 ),
            'task'    => mb_substr( $task, 0, 500 ),
            'minutes' => max( 1, min( 120, (int) ( $d['minutes'] ?? 10 ) ) ),
        );
        if ( count( $days ) === KOUNSELIA_GROWTH_PLAN_DAYS ) {
            break;
        }
    }
    if ( count( $days ) < KOUNSELIA_GROWTH_PLAN_DAYS ) {
        return null;
    }

    $title = mb_substr( trim( sanitize_text_field( (string) ( $data['title'] ?? '' ) ) ), 0, 120 );
    return array(
        'title'   => '' !== $title ? $title : 'Your 30 day plan',
        'summary' => mb_substr( trim( sanitize_textarea_field( (string) ( $data['summary'] ?? '' ) ) ), 0, 600 ),
        'days'    => $days,
    );
}

/**
 * Asks the AI for a plan (one retry if the first reply can't be used).
 * Tests replace the AI with the 'kounselia_growth_plan_ai_response'
 * filter, which gets the prompt and returns the raw reply.
 *
 * @return array|WP_Error
 */
function kounselia_growth_generate( $area_key, $answers, $first_name ) {
    $prompt = kounselia_growth_build_prompt( $area_key, $answers, $first_name );
    $system = 'You are a warm, practical personal development coach at Kounselia. You design realistic 30 day plans made of small daily steps. You output only valid JSON. Escape all quotes inside strings.';

    for ( $attempt = 0; $attempt < 2; $attempt++ ) {
        $raw = apply_filters( 'kounselia_growth_plan_ai_response', null, $prompt );
        if ( null === $raw ) {
            $error = '';
            $raw   = kounselia_call_gemini(
                $system,
                array( array( 'role' => 'user', 'parts' => array( array( 'text' => $prompt ) ) ) ),
                'gemini-3.6-flash',
                0.6,
                8000,
                $error,
                'application/json',
                60
            );
        }
        $plan = kounselia_growth_parse_plan( $raw );
        if ( $plan ) {
            return $plan;
        }
    }
    return new WP_Error( 'ai_failed', 'We could not write your plan just now. Please try again in a minute.' );
}

/**
 * Starts a new plan for a member. Returns the new plan row's id, or a
 * WP_Error with a message the member can read.
 */
function kounselia_growth_create_plan( $user_id, $area_key, $answers ) {
    if ( ! array_key_exists( $area_key, kounselia_growth_areas() ) ) {
        return new WP_Error( 'unknown_area', 'Please choose what you want to work on.' );
    }
    if ( kounselia_growth_active_plan( $user_id ) ) {
        return new WP_Error( 'has_plan', 'You already have a plan running. Finish or end it before starting a new one.' );
    }
    $allowance = kounselia_growth_allowance( $user_id );
    if ( null !== $allowance['remaining'] && $allowance['remaining'] < 1 ) {
        return new WP_Error( 'limit', sprintf( 'You have used all %d free growth plans. Upgrade to Pro for unlimited plans.', $allowance['limit'] ) );
    }

    $answers = kounselia_growth_clean_answers( $area_key, $answers );
    if ( is_wp_error( $answers ) ) {
        return $answers;
    }

    $user  = get_userdata( $user_id );
    $first = $user ? explode( ' ', trim( $user->display_name ) )[0] : '';
    $plan  = kounselia_growth_generate( $area_key, $answers, $first );
    if ( is_wp_error( $plan ) ) {
        return $plan;
    }

    global $wpdb;
    $wpdb->insert( kounselia_growth_table(), array(
        'user_id'    => $user_id,
        'area'       => $area_key,
        'title'      => $plan['title'],
        'summary'    => $plan['summary'],
        'answers'    => wp_json_encode( $answers ),
        'days'       => wp_json_encode( $plan['days'] ),
        'total_days' => count( $plan['days'] ),
        'start_date' => current_time( 'Y-m-d' ),
        // Day 1's task is on screen right now, so the first reminder is tomorrow's.
        'last_reminded' => current_time( 'Y-m-d' ),
        'status'     => 'active',
        'created_at' => current_time( 'mysql' ),
    ) );
    return (int) $wpdb->insert_id;
}

/**
 * Ticks a day off (or un-ticks it). Only days up to today can be ticked.
 *
 * @return true|WP_Error
 */
function kounselia_growth_set_day_done( $user_id, $plan_id, $day, $done ) {
    $row = kounselia_growth_active_plan( $user_id );
    if ( ! $row || (int) $row->id !== (int) $plan_id ) {
        return new WP_Error( 'no_plan', 'This plan has finished or ended.' );
    }
    $day = (int) $day;
    if ( $day < 1 || $day > min( (int) $row->total_days, kounselia_growth_day_number( $row ) ) ) {
        return new WP_Error( 'bad_day', 'You can tick off this day when it comes.' );
    }

    global $wpdb;
    $table = kounselia_growth_progress_table();
    if ( $done ) {
        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$table} (plan_id, user_id, day_number, done_at) VALUES (%d, %d, %d, %s)",
            $row->id, $user_id, $day, current_time( 'mysql' )
        ) );
    } else {
        $wpdb->delete( $table, array( 'plan_id' => $row->id, 'day_number' => $day ) );
    }
    return true;
}

function kounselia_growth_end_plan( $user_id, $plan_id ) {
    global $wpdb;
    return (bool) $wpdb->update(
        kounselia_growth_table(),
        array( 'status' => 'ended', 'ended_at' => current_time( 'mysql' ) ),
        array( 'id' => (int) $plan_id, 'user_id' => $user_id, 'status' => 'active' )
    );
}

/* -------------------------------------------------------------------------
 * Daily reminder
 * ---------------------------------------------------------------------- */

/**
 * Changes when the reminder goes out. -1 turns it off.
 *
 * @return true|WP_Error
 */
function kounselia_growth_set_reminder( $user_id, $plan_id, $hour ) {
    $row = kounselia_growth_active_plan( $user_id );
    if ( ! $row || (int) $row->id !== (int) $plan_id ) {
        return new WP_Error( 'no_plan', 'This plan has finished or ended.' );
    }
    $hour = (int) $hour;
    if ( $hour < -1 || $hour > 23 ) {
        return new WP_Error( 'bad_hour', 'Please choose a time of day.' );
    }
    global $wpdb;
    $wpdb->update( kounselia_growth_table(), array( 'remind_hour' => $hour ), array( 'id' => $row->id ) );
    return true;
}

add_action( 'init', function () {
    if ( ! wp_next_scheduled( 'kounselia_growth_send_reminders' ) ) {
        wp_schedule_event( time(), 'hourly', 'kounselia_growth_send_reminders' );
    }
} );

/**
 * Hourly: sends today's task to everyone whose reminder hour has come
 * and who has not been reminded today. Someone who already ticked off
 * today's task is left alone. Running late (a quiet site only runs its
 * jobs when someone visits) still sends the same day.
 */
function kounselia_growth_send_reminders() {
    global $wpdb;
    $table = kounselia_growth_table();
    $today = current_time( 'Y-m-d' );
    $rows  = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE status = 'active' AND remind_hour >= 0 AND remind_hour <= %d AND ( last_reminded IS NULL OR last_reminded < %s ) LIMIT 500",
        (int) current_time( 'G' ),
        $today
    ) );

    foreach ( $rows as $row ) {
        // Marked first, so an overlapping run can never send it twice.
        $wpdb->update( $table, array( 'last_reminded' => $today ), array( 'id' => $row->id ) );

        $active = kounselia_growth_active_plan( $row->user_id );
        if ( ! $active || (int) $active->id !== (int) $row->id ) {
            continue; // Finished since.
        }
        $plan = kounselia_growth_format_plan( $active, false );
        if ( ! $plan['today'] || $plan['today']['done'] ) {
            continue;
        }

        $body = wp_html_excerpt( $plan['today']['task'], 140, '…' );
        if ( $plan['review_ready'] ) {
            $body .= ' Your week ' . (int) $plan['review_ready'] . ' review is ready too.';
        }
        kounselia_notify_user(
            $row->user_id,
            'growth_reminder',
            'Day ' . $plan['current_day'] . ': ' . ( $plan['today']['title'] ? $plan['today']['title'] : 'today\'s step' ),
            $body,
            '/dashboard.php?tab=growth'
        );
    }
}
add_action( 'kounselia_growth_send_reminders', 'kounselia_growth_send_reminders' );

/* -------------------------------------------------------------------------
 * Weekly review
 * ---------------------------------------------------------------------- */

/**
 * Reviews already written for a plan, keyed by week number (1 to 5; week
 * 5 is just the last two days).
 */
function kounselia_growth_reviews( $row ) {
    $reviews = json_decode( (string) ( $row->reviews ?? '' ), true );
    return is_array( $reviews ) ? $reviews : array();
}

/**
 * First week the member can review and has not yet, or null.
 */
function kounselia_growth_next_review( $row ) {
    $total   = (int) $row->total_days;
    $current = min( $total, kounselia_growth_day_number( $row ) );
    $done    = kounselia_growth_reviews( $row );
    for ( $n = 1; $n <= (int) ceil( $total / 7 ); $n++ ) {
        if ( $current >= min( 7 * $n, $total ) && ! isset( $done[ $n ] ) ) {
            return $n;
        }
    }
    return null;
}

function kounselia_growth_build_review_prompt( $row, $week, $first_name ) {
    $total = (int) $row->total_days;
    $first = 7 * ( $week - 1 ) + 1;
    $last  = min( 7 * $week, $total );
    $days  = json_decode( (string) $row->days, true );
    $done  = kounselia_growth_done_days( $row->id );

    $lines = array();
    foreach ( $days as $d ) {
        if ( $d['day'] >= $first && $d['day'] <= $last ) {
            $lines[] = "Day {$d['day']}: {$d['task']} [" . ( in_array( (int) $d['day'], $done, true ) ? 'DONE' : 'not done' ) . ']';
        }
    }

    $current  = min( $total, kounselia_growth_day_number( $row ) );
    $upcoming = kounselia_growth_review_upcoming_days( $row, $current );
    $next     = array();
    foreach ( $days as $d ) {
        if ( in_array( (int) $d['day'], $upcoming, true ) ) {
            $next[] = "Day {$d['day']}: {$d['task']}";
        }
    }

    $out  = ( $first_name ? $first_name : 'This person' ) . " is on a {$total} day personal development plan called \"{$row->title}\" (focus: {$row->area}).\n"
        . "Review of days {$first} to {$last}.\n\nTHEIR DAYS:\n" . implode( "\n", $lines ) . "\n\n";
    if ( $next ) {
        $out .= "THE NEXT DAYS AS PLANNED:\n" . implode( "\n", $next ) . "\n\n";
    }
    $out .= "Write a short, warm review. Say what went well, be kind about anything missed (never shame), and name one thing to focus on next.\n";
    if ( $next ) {
        $out .= "Then decide if the next days should be 'easier' (they struggled), 'same', or 'harder' (they found it easy), and rewrite exactly those next days to match, keeping each day's purpose. Keep any reflection days a reflection.\n";
    }
    $out .= "Plain everyday English, speaking to them as 'you'. No markdown, no emojis, no medical advice.\n\n"
        . 'Return ONLY JSON: {"note": "3 to 5 sentences", "level": "easier|same|harder", "upcoming": [{"day": 8, "title": "at most 6 words", "task": "one or two sentences", "minutes": 10}]}'
        . ( $next ? '' : ' (use an empty "upcoming" list)' );
    return $out;
}

/**
 * The day numbers a review may rewrite: up to 7 days after today.
 */
function kounselia_growth_review_upcoming_days( $row, $current ) {
    $total = (int) $row->total_days;
    $days  = array();
    for ( $d = $current + 1; $d <= min( $total, $current + 7 ); $d++ ) {
        $days[] = $d;
    }
    return $days;
}

/**
 * Writes the review for a week (once), saves it, and applies any change
 * to the next days.
 *
 * @return true|WP_Error
 */
function kounselia_growth_review_week( $user_id, $plan_id, $week ) {
    $row = kounselia_growth_active_plan( $user_id );
    if ( ! $row || (int) $row->id !== (int) $plan_id ) {
        return new WP_Error( 'no_plan', 'This plan has finished or ended.' );
    }
    $week = (int) $week;
    $next = kounselia_growth_next_review( $row );
    $done = kounselia_growth_reviews( $row );
    if ( isset( $done[ $week ] ) ) {
        return new WP_Error( 'already', 'You already have a review for this week.' );
    }
    if ( null === $next || $week !== $next ) {
        return new WP_Error( 'not_ready', 'This review is not ready yet. It opens at the end of the week.' );
    }

    $user    = get_userdata( $user_id );
    $first   = $user ? explode( ' ', trim( $user->display_name ) )[0] : '';
    $prompt  = kounselia_growth_build_review_prompt( $row, $week, $first );
    $current = min( (int) $row->total_days, kounselia_growth_day_number( $row ) );
    $allowed = kounselia_growth_review_upcoming_days( $row, $current );

    $data = null;
    for ( $attempt = 0; $attempt < 2 && ! $data; $attempt++ ) {
        $raw = apply_filters( 'kounselia_growth_review_ai_response', null, $prompt );
        if ( null === $raw ) {
            $error = '';
            $raw   = kounselia_call_gemini(
                'You are a warm, practical personal development coach at Kounselia. You output only valid JSON. Escape all quotes inside strings.',
                array( array( 'role' => 'user', 'parts' => array( array( 'text' => $prompt ) ) ) ),
                'gemini-3.6-flash',
                0.6,
                4000,
                $error,
                'application/json',
                60
            );
        }
        $data = kounselia_growth_parse_review( $raw );
    }
    if ( ! $data ) {
        return new WP_Error( 'ai_failed', 'We could not write your review just now. Please try again in a minute.' );
    }

    // Apply the rewritten days only when they are exactly the days asked for.
    $changed = 0;
    $days    = json_decode( (string) $row->days, true );
    $given   = array();
    foreach ( $data['upcoming'] as $u ) {
        $given[ (int) $u['day'] ] = $u;
    }
    if ( $allowed && 'same' !== $data['level'] && count( $given ) === count( $allowed ) && ! array_diff( $allowed, array_keys( $given ) ) ) {
        foreach ( $days as &$d ) {
            if ( isset( $given[ (int) $d['day'] ] ) ) {
                $u            = $given[ (int) $d['day'] ];
                $d['title']   = $u['title'] ? $u['title'] : $d['title'];
                $d['task']    = $u['task'];
                $d['minutes'] = $u['minutes'];
                $changed++;
            }
        }
        unset( $d );
    }

    $done[ $week ] = array(
        'week'    => $week,
        'note'    => $data['note'],
        'level'   => $changed ? $data['level'] : 'same',
        'changed' => $changed,
        'at'      => current_time( 'mysql' ),
    );

    global $wpdb;
    $wpdb->update( kounselia_growth_table(), array( 'reviews' => wp_json_encode( $done ), 'days' => wp_json_encode( $days ) ), array( 'id' => $row->id ) );
    return true;
}

/**
 * @return array{note:string,level:string,upcoming:array}|null
 */
function kounselia_growth_parse_review( $raw ) {
    $raw  = trim( preg_replace( '/^```(?:json)?|```$/m', '', (string) $raw ) );
    $data = json_decode( $raw, true );
    if ( ! is_array( $data ) || empty( $data['note'] ) ) {
        return null;
    }
    $upcoming = array();
    foreach ( (array) ( $data['upcoming'] ?? array() ) as $u ) {
        if ( ! is_array( $u ) || empty( $u['day'] ) || empty( $u['task'] ) ) {
            continue;
        }
        $upcoming[] = array(
            'day'     => (int) $u['day'],
            'title'   => mb_substr( trim( sanitize_text_field( (string) ( $u['title'] ?? '' ) ) ), 0, 80 ),
            'task'    => mb_substr( trim( sanitize_textarea_field( (string) $u['task'] ) ), 0, 500 ),
            'minutes' => max( 1, min( 120, (int) ( $u['minutes'] ?? 10 ) ) ),
        );
    }
    $level = isset( $data['level'] ) ? strtolower( (string) $data['level'] ) : 'same';
    return array(
        'note'     => mb_substr( trim( sanitize_textarea_field( (string) $data['note'] ) ), 0, 1200 ),
        'level'    => in_array( $level, array( 'easier', 'harder' ), true ) ? $level : 'same',
        'upcoming' => $upcoming,
    );
}

/* -------------------------------------------------------------------------
 * In chat: the plan's counselor knows where the member is
 * ---------------------------------------------------------------------- */

function kounselia_growth_chat_clause( $user_id, $counselor_slug ) {
    if ( kounselia_growth_counselor_slug() !== $counselor_slug ) {
        return '';
    }
    $row = kounselia_growth_active_plan( $user_id );
    if ( ! $row ) {
        return '';
    }
    $p     = kounselia_growth_format_plan( $row, false );
    $today = $p['today'];
    return "\n\nGROWTH PLAN: This person is following a {$p['total_days']} day personal development plan on Kounselia called \"{$p['title']}\" (focus: {$p['area_label']}). "
        . "Today is day {$p['current_day']}, and they have ticked off {$p['done_count']} days so far. "
        . ( $today ? "Today's task is \"{$today['task']}\", which they have " . ( $today['done'] ? 'already done' : 'not done yet' ) . '. ' : '' )
        . 'If they bring up the plan, help them with today\'s step, encourage them warmly and never shame them for missed days. '
        . 'Only raise the plan yourself when it fits; if they came to talk about something else, follow them.';
}

/* -------------------------------------------------------------------------
 * AJAX (website and app)
 * ---------------------------------------------------------------------- */

function kounselia_growth_require_member() {
    kounselia_verify_nonce();
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in again.', 'signed_out' => true ), 401 );
    }
    return get_current_user_id();
}

function kounselia_ajax_growth_get() {
    $user_id = kounselia_growth_require_member();
    wp_send_json_success( kounselia_growth_overview( $user_id ) );
}
add_action( 'wp_ajax_kounselia_growth_get', 'kounselia_ajax_growth_get' );
add_action( 'wp_ajax_nopriv_kounselia_growth_get', 'kounselia_ajax_growth_get' );

function kounselia_ajax_growth_create() {
    $user_id = kounselia_growth_require_member();
    if ( kounselia_rate_limited( 'growth_create', 6, HOUR_IN_SECONDS ) ) {
        wp_send_json_error( array( 'message' => 'Please wait a little before making another plan.' ), 429 );
    }
    $area    = isset( $_POST['area'] ) ? sanitize_key( wp_unslash( $_POST['area'] ) ) : '';
    $answers = json_decode( (string) wp_unslash( $_POST['answers'] ?? '{}' ), true );
    $result  = kounselia_growth_create_plan( $user_id, $area, is_array( $answers ) ? $answers : array() );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ), 'ai_failed' === $result->get_error_code() ? 503 : 400 );
    }
    wp_send_json_success( kounselia_growth_overview( $user_id ) );
}
add_action( 'wp_ajax_kounselia_growth_create', 'kounselia_ajax_growth_create' );
add_action( 'wp_ajax_nopriv_kounselia_growth_create', 'kounselia_ajax_growth_create' );

function kounselia_ajax_growth_mark_day() {
    $user_id = kounselia_growth_require_member();
    $result  = kounselia_growth_set_day_done(
        $user_id,
        absint( $_POST['plan_id'] ?? 0 ),
        absint( $_POST['day'] ?? 0 ),
        ! empty( $_POST['done'] )
    );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
    }
    $row = kounselia_growth_active_plan( $user_id );
    wp_send_json_success( array( 'plan' => $row ? kounselia_growth_format_plan( $row ) : null ) );
}
add_action( 'wp_ajax_kounselia_growth_mark_day', 'kounselia_ajax_growth_mark_day' );
add_action( 'wp_ajax_nopriv_kounselia_growth_mark_day', 'kounselia_ajax_growth_mark_day' );

function kounselia_ajax_growth_end() {
    $user_id = kounselia_growth_require_member();
    if ( ! kounselia_growth_end_plan( $user_id, absint( $_POST['plan_id'] ?? 0 ) ) ) {
        wp_send_json_error( array( 'message' => 'This plan has already finished or ended.' ), 400 );
    }
    wp_send_json_success( kounselia_growth_overview( $user_id ) );
}
add_action( 'wp_ajax_kounselia_growth_end', 'kounselia_ajax_growth_end' );
add_action( 'wp_ajax_nopriv_kounselia_growth_end', 'kounselia_ajax_growth_end' );

function kounselia_ajax_growth_set_reminder() {
    $user_id = kounselia_growth_require_member();
    $result  = kounselia_growth_set_reminder( $user_id, absint( $_POST['plan_id'] ?? 0 ), isset( $_POST['hour'] ) ? (int) $_POST['hour'] : -1 );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
    }
    $row = kounselia_growth_active_plan( $user_id );
    wp_send_json_success( array( 'plan' => $row ? kounselia_growth_format_plan( $row ) : null ) );
}
add_action( 'wp_ajax_kounselia_growth_set_reminder', 'kounselia_ajax_growth_set_reminder' );
add_action( 'wp_ajax_nopriv_kounselia_growth_set_reminder', 'kounselia_ajax_growth_set_reminder' );

function kounselia_ajax_growth_review() {
    $user_id = kounselia_growth_require_member();
    if ( kounselia_rate_limited( 'growth_review', 10, HOUR_IN_SECONDS ) ) {
        wp_send_json_error( array( 'message' => 'Please wait a little before asking again.' ), 429 );
    }
    $result = kounselia_growth_review_week( $user_id, absint( $_POST['plan_id'] ?? 0 ), absint( $_POST['week'] ?? 0 ) );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ), 'ai_failed' === $result->get_error_code() ? 503 : 400 );
    }
    $row = kounselia_growth_active_plan( $user_id );
    wp_send_json_success( array( 'plan' => $row ? kounselia_growth_format_plan( $row ) : null ) );
}
add_action( 'wp_ajax_kounselia_growth_review', 'kounselia_ajax_growth_review' );
add_action( 'wp_ajax_nopriv_kounselia_growth_review', 'kounselia_ajax_growth_review' );
