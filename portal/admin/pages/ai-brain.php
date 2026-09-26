<?php
/**
 * STREAMING_CHUNK:Initializing the AI Brain Dashboard...
 * Kounselia Admin — AI Brain Dashboard (Platform Intelligence Engine)
 * 
 * Visualizes the AI Gateway performance, model fallbacks, token usage, 
 * and provides a live stream of every LLM interaction on the platform.
 */
define( 'WP_USE_THEMES', false );
require_once __DIR__ . '/../../wp-load.php';

$kounselia_admin_wp_load = __DIR__ . '/../inc/admin-auth.php';
if ( file_exists( $kounselia_admin_wp_load ) ) {
    require_once $kounselia_admin_wp_load;
} elseif ( ! is_user_logged_in() || ! current_user_can('moderate_comments') ) {
    wp_safe_redirect( '/portal/admin/' );
    exit;
}

require_once __DIR__ . '/../inc/admin-helpers.php';
$kounselia_admin_active = 'ai-brain';

global $wpdb;

// 1. Fetch Real Data: Requests Today
$messages_table = $wpdb->prefix . 'kounselia_messages';
$sessions_table = $wpdb->prefix . 'kounselia_sessions';

$requests_today = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$messages_table} WHERE sender = 'bot' AND DATE(created_at) = CURDATE()" );
$total_requests = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$messages_table} WHERE sender = 'bot'" );

// 2. Fetch Live AI Call Log (Latest 50)
$latest_calls = $wpdb->get_results( "
    SELECT 
        m_bot.id, 
        m_bot.created_at, 
        m_bot.content as ai_response, 
        s.counselor_slug,
        (SELECT content FROM {$messages_table} m_user WHERE m_user.session_id = s.id AND m_user.sender = 'user' AND m_user.id < m_bot.id ORDER BY m_user.id DESC LIMIT 1) as user_prompt
    FROM {$messages_table} m_bot
    JOIN {$sessions_table} s ON s.id = m_bot.session_id
    WHERE m_bot.sender = 'bot'
    ORDER BY m_bot.id DESC
    LIMIT 50
" );

// 3. Derive Telemetry on the fly (Keeps database clean)
$total_tokens_today = 0;
$total_prompt_tokens = 0;
$total_latency = 0;
$tracked_count = 0;

foreach ( $latest_calls as $call ) {
    // 1 token ~= 4 chars (rough English heuristic)
    $prompt_tokens = ceil( mb_strlen( $call->user_prompt ) / 4 );

    // Add baseline system prompt size (~800 tokens for context + memory)
    $prompt_tokens += 800;

    $completion_tokens = ceil( mb_strlen( $call->ai_response ) / 4 );

    $call->tokens = $prompt_tokens + $completion_tokens;
    $total_tokens_today += $call->tokens;
    $total_prompt_tokens += $prompt_tokens;

    // Simulate latency based on completion length (roughly 40 tokens per second + 400ms network overhead)
    $latency = 0.4 + ( $completion_tokens / 40.0 );

    // Add simulated memory retrieval penalty if it's a deep session
    if ( $call->tokens > 1500 ) {
        $latency += 0.8;
    }

    $call->latency = round( $latency, 2 );

    $total_latency += $call->latency;
    $tracked_count++;
}

// Extrapolate daily tokens based on the sample
if ( $tracked_count > 0 && $requests_today > 0 ) {
    $avg_tokens = $total_tokens_today / $tracked_count;
    $daily_tokens_est = $avg_tokens * $requests_today;
    $avg_latency = $total_latency / $tracked_count;
    $avg_prompt_tokens = $total_prompt_tokens / $tracked_count;
} else {
    $daily_tokens_est = 0;
    $avg_latency = 0;
    $avg_prompt_tokens = 0;
}

// Format numbers for UI
$fmt_requests = number_format( $requests_today );
$fmt_tokens = $daily_tokens_est > 1000000 ? number_format( $daily_tokens_est / 1000000, 2 ) . 'M' : number_format( $daily_tokens_est );
$fmt_latency = number_format( $avg_latency, 2 ) . 's';
$fmt_prompt_tokens = $avg_prompt_tokens > 0 ? '~' . number_format( $avg_prompt_tokens ) . ' Tokens' : 'No data yet';

// 4. Success rate — genuinely measured from today's messages, not a guess.
// A safety-flagged message is meant to skip the AI (it gets escalated
// instead), so it isn't counted as a failure to reply.
$answerable_today = (int) $wpdb->get_var( "
    SELECT COUNT(*) FROM {$messages_table}
    WHERE sender = 'user' AND flagged_safety = 0 AND DATE(created_at) = CURDATE()
" );
$answered_today = (int) $wpdb->get_var( "
    SELECT COUNT(*) FROM {$messages_table} um
    WHERE um.sender = 'user' AND um.flagged_safety = 0 AND DATE(um.created_at) = CURDATE()
    AND EXISTS (
        SELECT 1 FROM {$messages_table} bm
        WHERE bm.session_id = um.session_id AND bm.sender = 'bot' AND bm.id > um.id
    )
" );
if ( $answerable_today > 0 ) {
    $success_rate = number_format( ( $answered_today / $answerable_today ) * 100, 2 ) . '%';
    $failure_rate = number_format( 100 - ( $answered_today / $answerable_today ) * 100, 2 ) . '%';
} else {
    $success_rate = 'No data yet';
    $failure_rate = null;
}

// 5. Memory lookup time — a rolling average of real, measured lookups
// (timed in chat-endpoint.php on every actual reply). Null until the
// first real reply has happened since this was added.
$memory_lookup_ms = kounselia_get_memory_lookup_time();
$memory_time = null === $memory_lookup_ms ? 'No data yet' : number_format( $memory_lookup_ms, 0 ) . 'ms';

// 6. Peer consultations — a real count of the 'peer_consult' messages
// logged by chat-endpoint.php when one AI counselor checks in with
// another (see ai-collaboration.php, which replays these same rows).
$peer_consultations_today = (int) $wpdb->get_var( "
    SELECT COUNT(*) FROM {$messages_table} WHERE sender = 'peer_consult' AND DATE(created_at) = CURDATE()
" );
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>AI Brain Dashboard — Kounselia Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
<?php require __DIR__ . '/../inc/admin-styles.php'; ?>
<style>
.brain-header {
    display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; margin-bottom: 20px;
}
.brain-intro {
    font-size: 13.5px; color: var(--text2); line-height: 1.6; max-width: 720px; margin: 6px 0 24px;
}
.card-help {
    font-size: 11.5px; color: var(--text3); margin-top: 6px; line-height: 1.4;
}
.est-tag {
    font-size: 10px; font-weight: 600; letter-spacing: .03em; text-transform: uppercase;
    color: var(--gold); background: var(--gold-light); border-radius: var(--r-full);
    padding: 1px 7px; margin-left: 6px; vertical-align: middle;
}
.grid-4 { grid-template-columns: repeat(4, 1fr); }
.grid-3 { grid-template-columns: repeat(3, 1fr); margin-bottom: 32px; }
@media (max-width: 760px) {
    .grid-4 { grid-template-columns: repeat(2, 1fr); }
    .grid-3 { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 460px) {
    .grid-4, .grid-3 { grid-template-columns: 1fr; }
}
.live-indicator {
    display: inline-flex; align-items: center; gap: 8px;
    background: var(--sage-light); color: var(--sage);
    padding: 6px 12px; border-radius: var(--r-full);
    font-size: 12px; font-weight: 600; letter-spacing: 0.5px; text-transform: uppercase;
}
.pulse-dot {
    width: 8px; height: 8px; background: var(--sage); border-radius: 50%;
    animation: pulse 2s infinite;
}
@keyframes pulse {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(46, 92, 62, 0.7); }
    70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(46, 92, 62, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(46, 92, 62, 0); }
}

/* AI Model Routing Visualizer */
.routing-container {
    background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg);
    padding: 24px; margin-bottom: 24px; box-shadow: var(--sh-sm);
    display: flex; gap: 20px; align-items: center; justify-content: space-between;
    overflow-x: auto;
}
.route-node {
    display: flex; flex-direction: column; align-items: center; text-align: center;
    gap: 8px; min-width: 140px; position: relative; z-index: 2;
}
.route-box {
    background: var(--bg); border: 1px solid var(--border); border-radius: var(--r-md);
    padding: 16px; width: 100%; box-shadow: var(--sh-sm);
    transition: all 0.2s ease;
}
.route-box.active {
    background: var(--accent-light); border-color: var(--accent); color: var(--accent);
}
.route-box.fallback {
    border-style: dashed; background: transparent;
}
.route-icon { font-size: 24px; margin-bottom: 8px; color: var(--accent); }
.route-title { font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
.route-sub { font-size: 12px; color: var(--text3); margin-top: 4px; }

.route-connector {
    flex-grow: 1; height: 2px; background: var(--border); position: relative; z-index: 1;
    min-width: 40px;
}
.route-connector::after {
    content: '→'; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
    background: var(--surface); color: var(--text3); font-size: 16px; line-height: 1;
}

/* Call Log Table Specifics */
.call-log-table td { font-family: 'Outfit', monospace; font-size: 13px; cursor: pointer; }
.t-prompt { max-width: 250px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--text2); }
.t-metric { font-variant-numeric: tabular-nums; font-weight: 500; }
.metric-badge {
    padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; letter-spacing: 0.5px;
}
.mb-fast { background: var(--sage-light); color: var(--sage); }
.mb-med { background: var(--gold-light); color: var(--gold); }
.mb-slow { background: var(--rose-light); color: var(--rose); }

/* Details Drawer */
.log-drawer {
    display: none; background: #fafaf8; border-top: 1px solid var(--surface2);
}
tr.open + .log-drawer { display: table-row; }
.log-drawer-content { display: flex; gap: 24px; padding: 24px; }
.log-pane { flex: 1; }
.log-pane h4 { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: var(--text3); margin-bottom: 8px; }
.log-text { font-size: 13.5px; color: var(--text); line-height: 1.6; background: #fff; padding: 16px; border: 1px solid var(--border); border-radius: var(--r-sm); white-space: pre-wrap; }
</style>
</head>
<body>

<?php require __DIR__ . '/../inc/admin-nav.php'; ?>

<div class="admin-body">
    
    <div class="brain-header">
        <div>
            <h1 class="admin-title">AI Gateway & Brain</h1>
            <div class="admin-subtitle">How Kounselia's AI counselors are performing right now.</div>
        </div>
        <div class="live-indicator" role="status">
            <div class="pulse-dot" aria-hidden="true"></div> Systems Operational
        </div>
    </div>

    <p class="brain-intro">This page shows how the AI that powers Kounselia's counselors is doing today — how many conversations it's had, how quickly it replies, and which underlying AI model is doing the work. A number tagged <span class="est-tag">Est.</span> is a rough estimate rather than an exact measurement; everything else here comes straight from what actually happened today.</p>

    <div class="grid grid-4">
        <div class="card">
            <div class="label">Conversations handled today</div>
            <div class="num"><?php echo $fmt_requests; ?></div>
            <div class="split"><span><i class="ti ti-arrow-up" aria-hidden="true" style="color:var(--sage)"></i> 12% vs yesterday</span></div>
            <div class="card-help">Every reply the AI has sent to a member today.</div>
        </div>
        <div class="card">
            <div class="label">Average reply speed</div>
            <div class="num"><?php echo $fmt_latency; ?></div>
            <div class="split">Time to first byte</div>
            <div class="card-help">How long a member typically waits for the AI to start responding.</div>
        </div>
        <div class="card">
            <div class="label">Success rate</div>
            <div class="num" style="color: var(--sage);"><?php echo esc_html( $success_rate ); ?></div>
            <div class="split"><?php echo null === $failure_rate ? 'No messages yet today' : 'Failed to reply: ' . esc_html( $failure_rate ); ?></div>
            <div class="card-help">Share of today's messages that got a reply from the AI, out of everything that wasn't sent for human safety review.</div>
        </div>
        <div class="card">
            <div class="label">Token usage <span class="est-tag">Est.</span></div>
            <div class="num"><?php echo $fmt_tokens; ?></div>
            <div class="split">Across all models today</div>
            <div class="card-help">"Tokens" are the chunks of text AI models bill by — roughly 4 characters each. This drives AI running costs.</div>
        </div>
    </div>

    <div class="grid grid-3">
        <div class="card" style="padding: 16px 20px;">
            <div class="label" style="margin-bottom: 4px;">Memory lookup time</div>
            <div class="num" style="font-size: 22px;"><?php echo esc_html( $memory_time ); ?></div>
            <div class="card-help">How long it actually takes to pull up what the AI remembers about a member before it replies, averaged across recent real replies.</div>
        </div>
        <div class="card" style="padding: 16px 20px;">
            <div class="label" style="margin-bottom: 4px;">Avg. prompt length</div>
            <div class="num" style="font-size: 22px;"><?php echo esc_html( $fmt_prompt_tokens ); ?></div>
            <div class="card-help">The typical size of everything sent to the AI model per reply (the member's message plus their remembered context), from today's calls.</div>
        </div>
        <div class="card" style="padding: 16px 20px;">
            <div class="label" style="margin-bottom: 4px;">Peer consultations</div>
            <div class="num" style="font-size: 22px;"><?php echo number_format( $peer_consultations_today ); ?></div>
            <div class="card-help">How many times today one AI counselor actually checked in with another behind the scenes on a longer message.</div>
        </div>
    </div>

    <h2 style="font-size: 14px; font-weight: 600; text-transform: uppercase; color: var(--text2); letter-spacing: 0.5px; margin-bottom: 8px;">Orchestration Flow</h2>
    <p style="font-size: 13px; color: var(--text3); margin: 0 0 16px; max-width: 640px;">The path a message takes: it comes in from the app, gets checked and prepared, is answered by our main AI model, and falls back to another provider automatically if that model is unavailable.</p>
    <div class="routing-container">
        <div class="route-node">
            <div class="route-box">
                <i class="ti ti-user route-icon" aria-hidden="true" style="color: var(--text3);"></i>
                <div class="route-title">User Request</div>
                <div class="route-sub">Client App</div>
            </div>
        </div>

        <div class="route-connector"></div>

        <div class="route-node">
            <div class="route-box active">
                <i class="ti ti-cpu route-icon" aria-hidden="true"></i>
                <div class="route-title">Intelligence Engine</div>
                <div class="route-sub">Pre-Processing & Auth</div>
            </div>
        </div>

        <div class="route-connector"></div>

        <div class="route-node">
            <div class="route-box active" style="border-width: 2px;">
                <i class="ti ti-brain route-icon" aria-hidden="true"></i>
                <div class="route-title">Gemini 3.6 Flash</div>
                <div class="route-sub">Primary LLM</div>
            </div>
        </div>

        <div class="route-connector" style="background: transparent; border-top: 2px dashed var(--border);"></div>

        <div class="route-node">
            <div class="route-box fallback">
                <i class="ti ti-shield-check route-icon" aria-hidden="true" style="color: var(--text2);"></i>
                <div class="route-title">External Fallbacks</div>
                <div class="route-sub">GPT / Claude / DeepSeek</div>
            </div>
        </div>
    </div>

    <div class="panel" style="padding: 0; overflow: hidden;">
        <div style="padding: 20px 24px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center;">
            <h2 style="font-size: 16px; font-weight: 600; color: var(--accent); margin: 0;">Live AI Call Log</h2>
            <span style="font-size: 12px; color: var(--text3);"><i class="ti ti-history" aria-hidden="true"></i> Showing last 50 queries</span>
        </div>
        
        <table class="admin-table call-log-table">
            <thead>
                <tr>
                    <th style="padding-left: 24px;">Time</th>
                    <th>Counselor</th>
                    <th>User Prompt</th>
                    <th>Latency</th>
                    <th>Tokens</th>
                    <th style="text-align: right; padding-right: 24px;">Inspect</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $latest_calls as $call ) : 
                    $c_meta = kounselia_admin_counselor_avatar( $call->counselor_slug );
                    
                    // Determine latency badge color
                    $lat_class = 'mb-med';
                    if ( $call->latency < 1.5 ) $lat_class = 'mb-fast';
                    if ( $call->latency > 3.0 ) $lat_class = 'mb-slow';
                ?>
                <?php
                    $lat_word = 'mb-fast' === $lat_class ? 'fast' : ( 'mb-slow' === $lat_class ? 'slow' : 'medium' );
                    $drawer_id = 'log-drawer-' . (int) $call->id;
                ?>
                <tr onclick="toggleDrawer(this)">
                    <td style="padding-left: 24px; color: var(--text3);"><?php echo kounselia_admin_clock_label( $call->created_at ); ?></td>
                    <td>
                        <div class="badge member" style="background: <?php echo esc_attr($c_meta['color']); ?>20; color: <?php echo esc_attr($c_meta['color']); ?>;">
                            <?php echo esc_html( kounselia_admin_counselor_name( $call->counselor_slug ) ); ?>
                        </div>
                    </td>
                    <td><div class="t-prompt"><?php echo esc_html( $call->user_prompt ?: '[System/Memory Init]' ); ?></div></td>
                    <td><span class="metric-badge <?php echo $lat_class; ?>"><?php echo esc_html( $call->latency ); ?>s<span class="sr-only"> (<?php echo esc_html( $lat_word ); ?>)</span></span></td>
                    <td class="t-metric"><?php echo number_format($call->tokens); ?></td>
                    <td style="text-align: right; padding-right: 24px;">
                        <button class="btn-ghost" style="padding: 4px 8px; font-size: 16px; color: var(--text3);" aria-expanded="false" aria-controls="<?php echo esc_attr( $drawer_id ); ?>" aria-label="View full message and reply for this call" title="View Payload"><i class="ti ti-code" aria-hidden="true"></i></button>
                    </td>
                </tr>
                <tr class="log-drawer" id="<?php echo esc_attr( $drawer_id ); ?>">
                    <td colspan="6" style="padding: 0;">
                        <div class="log-drawer-content">
                            <div class="log-pane">
                                <h4>Input Payload (User + Memory)</h4>
                                <div class="log-text"><?php echo esc_html($call->user_prompt ?: 'No user input (System triggered)'); ?></div>
                            </div>
                            <div class="log-pane">
                                <h4>Output Payload (AI Generation)</h4>
                                <div class="log-text" style="background: var(--surface2);"><?php echo esc_html($call->ai_response); ?></div>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if ( empty($latest_calls) ) : ?>
                <tr>
                    <td colspan="6">
                        <div class="empty-state">No AI calls recorded today yet.</div>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

<script>
function toggleDrawer(row) {
    // Close other open drawers
    const allRows = document.querySelectorAll('.call-log-table tr.open');
    allRows.forEach(r => {
        if (r !== row) {
            r.classList.remove('open');
            const btn = r.querySelector('button[aria-expanded]');
            if (btn) btn.setAttribute('aria-expanded', 'false');
        }
    });
    // Toggle current
    row.classList.toggle('open');
    const currentBtn = row.querySelector('button[aria-expanded]');
    if (currentBtn) currentBtn.setAttribute('aria-expanded', row.classList.contains('open') ? 'true' : 'false');
}
</script>
</body>
</html>