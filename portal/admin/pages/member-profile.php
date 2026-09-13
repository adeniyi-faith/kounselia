<?php
/**
 * Kounselia Admin — User Intelligence Center (Digital Twin)
 * 
 * This page visualizes the Intelligence Layer (kounselia_core_memory and 
 * kounselia_latest_reflection) as a comprehensive CRM for a human being.
 */
define( 'WP_USE_THEMES', false );
require_once __DIR__ . '/../../wp-load.php';

// Ensure the user is a confirmed admin (assuming admin-auth.php handles the redirect)
$kounselia_admin_wp_load = __DIR__ . '/../inc/admin-auth.php';
if ( file_exists( $kounselia_admin_wp_load ) ) {
    require_once $kounselia_admin_wp_load;
} elseif ( ! is_user_logged_in() || ! current_user_can('moderate_comments') ) {
    wp_safe_redirect( '/portal/admin/' );
    exit;
}

require_once __DIR__ . '/../inc/admin-helpers.php';

$user_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
$user_obj = get_userdata( $user_id );

if ( ! $user_obj ) {
    wp_die( 'User not found.' );
}

// 1. Fetch the Core Data (The Intelligence Layer)
$memory_json     = get_user_meta( $user_id, 'kounselia_core_memory', true );
$reflection_json = get_user_meta( $user_id, 'kounselia_latest_reflection', true );

$memory     = $memory_json ? json_decode( $memory_json, true ) : [];
$reflection = $reflection_json ? json_decode( $reflection_json, true ) : [];

// 2. Fetch Latest Activity
global $wpdb;
$last_activity = $wpdb->get_var( $wpdb->prepare(
    "SELECT created_at FROM {$wpdb->prefix}kounselia_messages 
     WHERE session_id IN (SELECT id FROM {$wpdb->prefix}kounselia_sessions WHERE user_id = %d) 
     ORDER BY created_at DESC LIMIT 1",
    $user_id
) );

// 3. Derived Insights (Dynamic Heuristics)
// Instead of saving these to the DB, we derive them on the fly from the AI's structural data.

function kounselia_derive_wellbeing( $reflection ) {
    // A simple heuristic scoring system based on the balance of growth vs fears
    $score = 65; // Baseline
    if ( ! empty( $reflection['growth'] ) ) $score += ( count( $reflection['growth'] ) * 5 );
    if ( ! empty( $reflection['achievements'] ) ) $score += ( count( $reflection['achievements'] ) * 4 );
    if ( ! empty( $reflection['recurring_fears'] ) ) $score -= ( count( $reflection['recurring_fears'] ) * 3 );
    if ( ! empty( $reflection['blind_spots'] ) ) $score -= ( count( $reflection['blind_spots'] ) * 4 );
    return min( 98, max( 12, $score ) ); // Clamp between 12% and 98%
}

function kounselia_derive_burnout_risk( $memory ) {
    $risk_words = ['exhausted', 'burnout', 'overwhelmed', 'tired', 'drowning', 'stress', 'quit', 'heavy'];
    $text_corpus = strtolower( 
        ($memory['health'] ?? '') . ' ' . 
        implode( ' ', $memory['current_challenges'] ?? [] ) . ' ' . 
        ($memory['temporary_context'] ?? '')
    );
    
    $matches = 0;
    foreach( $risk_words as $word ) {
        if ( strpos( $text_corpus, $word ) !== false ) $matches++;
    }
    
    if ( $matches >= 3 ) return ['level' => 'High', 'color' => 'var(--rose)'];
    if ( $matches == 2 ) return ['level' => 'Moderate', 'color' => 'var(--gold)'];
    if ( $matches == 1 ) return ['level' => 'Elevated', 'color' => 'var(--sienna)'];
    return ['level' => 'Low', 'color' => 'var(--sage)'];
}

function kounselia_derive_stress( $memory ) {
    $stress_words = ['anxious', 'panic', 'stress', 'pressure', 'tight', 'deadline', 'money', 'worry'];
    $text_corpus = strtolower( implode( ' ', $memory['current_challenges'] ?? [] ) . ' ' . implode( ' ', $memory['triggers'] ?? [] ) );
    
    $matches = 0;
    foreach( $stress_words as $word ) {
        if ( strpos( $text_corpus, $word ) !== false ) $matches++;
    }
    
    if ( $matches >= 3 ) return 'High';
    if ( $matches > 0 ) return 'Moderate';
    return 'Managed';
}

$wellbeing_score = kounselia_derive_wellbeing( $reflection );
$burnout_risk    = kounselia_derive_burnout_risk( $memory );
$stress_level    = kounselia_derive_stress( $memory );
$confidence_trend = ( $wellbeing_score > 60 ) ? 'Increasing' : ( ( $wellbeing_score < 40 ) ? 'Decreasing' : 'Stable' );

$kounselia_admin_active = 'members';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>User Intelligence: <?php echo esc_html( $user_obj->display_name ); ?> — Kounselia</title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<?php require __DIR__ . '/../inc/admin-styles.php'; ?>
<style>
/* Page-Specific Dashboard Styles */
.profile-header {
    display: flex; align-items: flex-start; justify-content: space-between; gap: 24px;
    margin-bottom: 28px; flex-wrap: wrap;
}
.profile-id {
    display: flex; align-items: center; gap: 16px;
}
.profile-av {
    width: 64px; height: 64px; border-radius: var(--r-full);
    background: var(--accent); color: #fff; font-size: 24px; font-weight: 500;
    display: flex; align-items: center; justify-content: center;
}
.profile-meta .name { font-size: 24px; font-family: 'Cormorant Garamond', serif; font-weight: 600; color: var(--accent); line-height: 1.1; margin-bottom: 4px; }
.profile-meta .email { font-size: 14px; color: var(--text2); }

.intel-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px;
}
.intel-col { display: flex; flex-direction: column; gap: 20px; }

/* Timeline UI */
.timeline {
    position: relative; padding-left: 24px; margin-top: 12px;
}
.timeline::before {
    content: ''; position: absolute; left: 5px; top: 8px; bottom: -8px; width: 2px;
    background: var(--surface2);
}
.timeline-item {
    position: relative; margin-bottom: 20px;
}
.timeline-item::before {
    content: ''; position: absolute; left: -24px; top: 6px; width: 12px; height: 12px;
    border-radius: 50%; background: var(--surface); border: 2px solid var(--accent);
}
.timeline-year { font-size: 12px; font-weight: 600; color: var(--gold); letter-spacing: 0.05em; margin-bottom: 2px; }
.timeline-event { font-size: 14px; color: var(--text); font-weight: 500; }
.timeline-impact { font-size: 13px; color: var(--text2); margin-top: 4px; }

/* List UI */
.data-list { list-style: none; padding: 0; margin: 0; }
.data-list li {
    padding: 10px 12px; border-bottom: 1px solid var(--surface2);
    font-size: 13.5px; color: var(--text); line-height: 1.4;
}
.data-list li:last-child { border-bottom: none; }
.data-list.bullets li {
    position: relative; padding-left: 18px; border-bottom: none; padding-top: 6px; padding-bottom: 6px;
}
.data-list.bullets li::before {
    content: '•'; position: absolute; left: 0; color: var(--gold); font-size: 18px; line-height: 1; top: 5px;
}

/* Tag UI */
.tag-cloud { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
.tag {
    background: var(--surface2); color: var(--text2); font-size: 12px; font-weight: 500;
    padding: 4px 10px; border-radius: var(--r-full);
}

.section-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--text3); font-weight: 600; margin-bottom: 12px; display: block; border-bottom: 1px solid var(--border); padding-bottom: 6px; }
</style>
</head>
<body>

<?php require __DIR__ . '/../inc/admin-nav.php'; // Outputs the topbar ?>

<div class="admin-body">
    
    <div class="back-link">
        <a href="members.php">← Back to Members CRM</a>
    </div>

    <div class="profile-header">
        <div class="profile-id">
            <div class="profile-av"><?php echo esc_html( strtoupper( substr( $user_obj->display_name, 0, 1 ) ) ); ?></div>
            <div class="profile-meta">
                <div class="name"><?php echo esc_html( $user_obj->display_name ); ?></div>
                <div class="email"><?php echo esc_html( $user_obj->user_email ); ?> • Joined <?php echo date_i18n( 'M j, Y', strtotime( $user_obj->user_registered ) ); ?></div>
            </div>
        </div>
        <div style="text-align: right;">
            <div class="section-label" style="border: none; padding: 0; margin-bottom: 4px;">Last Activity</div>
            <div style="font-size: 15px; font-weight: 500; color: var(--accent);">
                <?php echo $last_activity ? esc_html( kounselia_admin_time_label( $last_activity ) ) : 'No sessions yet'; ?>
            </div>
        </div>
    </div>

    <!-- Vital Stats Row -->
    <div class="grid">
        <div class="card">
            <div class="label">Overall Wellbeing</div>
            <div class="num"><?php echo $wellbeing_score; ?>%</div>
            <div class="split">Derived from growth & patterns</div>
        </div>
        <div class="card">
            <div class="label">Confidence Trend</div>
            <div class="num" style="font-size: 24px; margin-top: 4px;"><?php echo $confidence_trend; ?></div>
            <div class="split">Based on emotional mapping</div>
        </div>
        <div class="card">
            <div class="label">Stress Level</div>
            <div class="num" style="font-size: 24px; margin-top: 4px;"><?php echo $stress_level; ?></div>
            <div class="split">Extracted from challenges</div>
        </div>
        <div class="card">
            <div class="label">Burnout Risk</div>
            <div class="num" style="font-size: 24px; margin-top: 4px; color: <?php echo $burnout_risk['color']; ?>;"><?php echo $burnout_risk['level']; ?></div>
            <div class="split">Career & health indicators</div>
        </div>
    </div>

    <?php if ( empty( $memory ) ) : ?>
        <div class="panel empty-state">
            <i class="ti ti-brain" style="font-size: 32px; color: var(--text3); margin-bottom: 12px; display: block;"></i>
            No intelligence data available yet. This user needs to complete a few more sessions for the AI to synthesize their Life Model.
        </div>
    <?php else : ?>
        
        <div class="intel-grid">
            
            <!-- Column 1: Identity & Traits -->
            <div class="intel-col">
                <div class="panel">
                    <span class="section-label">Identity & Core Context</span>
                    <p style="font-size: 14px; line-height: 1.6; color: var(--text);">
                        <?php echo esc_html( $memory['identity'] ?? 'Not enough data yet.' ); ?>
                    </p>
                    
                    <?php if ( ! empty( $memory['temporary_context'] ) ) : ?>
                        <div style="margin-top: 16px; padding: 12px; background: var(--surface2); border-radius: var(--r-sm); font-size: 13px;">
                            <strong style="color: var(--accent); display: block; margin-bottom: 4px;">Current State (Temporary)</strong>
                            <?php echo esc_html( $memory['temporary_context'] ); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <span class="section-label">Traits & Preferences</span>
                    
                    <div style="margin-bottom: 16px;">
                        <strong style="font-size: 12px; color: var(--text2);">Personality</strong>
                        <div style="font-size: 14px; margin-top: 4px;"><?php echo esc_html( $memory['personality'] ?: 'Unknown' ); ?></div>
                    </div>
                    
                    <div style="margin-bottom: 16px;">
                        <strong style="font-size: 12px; color: var(--text2);">Communication Style</strong>
                        <div style="font-size: 14px; margin-top: 4px;"><?php echo esc_html( $memory['communication_style'] ?: 'Unknown' ); ?></div>
                    </div>

                    <div style="margin-bottom: 16px;">
                        <strong style="font-size: 12px; color: var(--text2);">Career</strong>
                        <div style="font-size: 14px; margin-top: 4px;"><?php echo esc_html( $memory['career'] ?: 'Unknown' ); ?></div>
                    </div>

                    <?php if ( ! empty( $memory['values'] ) ) : ?>
                        <div>
                            <strong style="font-size: 12px; color: var(--text2);">Core Values</strong>
                            <div class="tag-cloud">
                                <?php foreach( (array) $memory['values'] as $v ) echo '<span class="tag">'.esc_html($v).'</span>'; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <span class="section-label">Emotional Map & Network</span>
                    
                    <?php if ( ! empty( $memory['emotional_map'] ) && is_array( $memory['emotional_map'] ) ) : ?>
                        <ul class="data-list" style="margin-bottom: 20px;">
                            <?php foreach ( $memory['emotional_map'] as $entity => $data ) : 
                                $intensity = is_array($data) ? ($data['intensity'] ?? 'Medium') : 'Medium';
                                $emotion = is_array($data) ? ($data['emotion'] ?? '') : $data;
                                $dot = ($intensity === 'High') ? 'var(--rose)' : (($intensity === 'Low') ? 'var(--sage)' : 'var(--gold)');
                            ?>
                                <li style="display: flex; justify-content: space-between; align-items: center;">
                                    <span>
                                        <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:<?php echo $dot; ?>; margin-right:6px;"></span>
                                        <strong><?php echo esc_html( $entity ); ?></strong>
                                    </span>
                                    <span style="color: var(--text2); font-size: 12.5px;"><?php echo esc_html( $emotion ); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if ( ! empty( $memory['important_people'] ) ) : ?>
                        <strong style="font-size: 12px; color: var(--text2);">Important People</strong>
                        <div class="tag-cloud">
                            <?php foreach( (array) $memory['important_people'] as $p ) echo '<span class="tag">'.esc_html($p).'</span>'; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Column 2: The Journey & Reflections -->
            <div class="intel-col">
                
                <div class="panel">
                    <span class="section-label">Clinical Synthesis (Reflection Engine)</span>
                    
                    <?php if ( empty( $reflection ) ) : ?>
                        <p style="font-size: 13.5px; color: var(--text3);">Reflection engine requires more data to generate insights.</p>
                    <?php else : ?>
                        
                        <?php if ( ! empty( $reflection['patterns'] ) ) : ?>
                            <div style="margin-bottom: 20px;">
                                <strong style="font-size: 13px; color: var(--accent);">Observed Patterns</strong>
                                <ul class="data-list bullets">
                                    <?php foreach( (array) $reflection['patterns'] as $p ) echo '<li>'.esc_html($p).'</li>'; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <?php if ( ! empty( $reflection['blind_spots'] ) ) : ?>
                            <div style="margin-bottom: 20px;">
                                <strong style="font-size: 13px; color: var(--rose);">Potential Blind Spots</strong>
                                <ul class="data-list bullets">
                                    <?php foreach( (array) $reflection['blind_spots'] as $p ) echo '<li>'.esc_html($p).'</li>'; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <?php if ( ! empty( $reflection['growth'] ) ) : ?>
                            <div style="margin-bottom: 20px;">
                                <strong style="font-size: 13px; color: var(--sage);">Growth & Evolution</strong>
                                <ul class="data-list bullets">
                                    <?php foreach( (array) $reflection['growth'] as $p ) echo '<li>'.esc_html($p).'</li>'; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <span class="section-label">Current Challenges & Goals</span>
                    
                    <?php if ( ! empty( $memory['current_challenges'] ) ) : ?>
                        <div style="margin-bottom: 20px;">
                            <strong style="font-size: 12px; color: var(--text2);">Active Struggles</strong>
                            <ul class="data-list bullets">
                                <?php foreach( (array) $memory['current_challenges'] as $c ) echo '<li>'.esc_html($c).'</li>'; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if ( ! empty( $memory['goals'] ) ) : ?>
                        <div>
                            <strong style="font-size: 12px; color: var(--text2);">Stated Goals</strong>
                            <ul class="data-list bullets">
                                <?php foreach( (array) $memory['goals'] as $g ) echo '<li>'.esc_html($g).'</li>'; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php if ( ! empty( $memory['triggers'] ) ) : ?>
                    <div class="panel">
                        <span class="section-label">Known Triggers</span>
                        <div class="tag-cloud">
                            <?php foreach( (array) $memory['triggers'] as $t ) echo '<span class="tag" style="background:var(--rose-light);color:var(--rose);">'.esc_html($t).'</span>'; ?>
                        </div>
                    </div>
                <?php endif; ?>

            </div>

            <!-- Column 3: Timeline -->
            <div class="intel-col">
                <div class="panel">
                    <span class="section-label">Life Timeline</span>
                    
                    <?php if ( empty( $memory['life_timeline'] ) || ! is_array( $memory['life_timeline'] ) ) : ?>
                        <p style="font-size: 13.5px; color: var(--text3);">No historical events captured yet.</p>
                    <?php else : ?>
                        <div class="timeline">
                            <?php foreach( $memory['life_timeline'] as $event ) : ?>
                                <div class="timeline-item">
                                    <div class="timeline-year"><?php echo esc_html( $event['year'] ?? 'Unknown' ); ?></div>
                                    <div class="timeline-event"><?php echo esc_html( $event['event'] ?? '' ); ?></div>
                                    <?php if ( ! empty( $event['impact'] ) ) : ?>
                                        <div class="timeline-impact"><?php echo esc_html( $event['impact'] ); ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ( ! empty( $memory['traumas'] ) ) : ?>
                    <div class="panel">
                        <span class="section-label">Trauma History</span>
                        <ul class="data-list bullets">
                            <?php foreach( (array) $memory['traumas'] as $t ) echo '<li>'.esc_html($t).'</li>'; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $memory['wins'] ) ) : ?>
                    <div class="panel">
                        <span class="section-label">Wins & Resilience</span>
                        <ul class="data-list bullets">
                            <?php foreach( (array) $memory['wins'] as $w ) echo '<li>'.esc_html($w).'</li>'; ?>
                        </ul>
                    </div>
                <?php endif; ?>

            </div>

        </div>

    <?php endif; ?>
</div>

</body>
</html>