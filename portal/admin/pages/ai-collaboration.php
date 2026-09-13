<?php
/**
 * STREAMING_CHUNK:Initializing the AI Collaboration Center...
 * Kounselia Admin — AI Collaboration Center.
 *
 * Visualizes multi-agent orchestration. Replays historical sessions where 
 * the primary AI consulted peer AIs before formulating a final response.
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
$kounselia_admin_active = 'ai-collaboration';

global $wpdb;

// ---------------------------------------------------------------------
// FETCH REAL HISTORICAL INTERNAL THOUGHTS
// ---------------------------------------------------------------------
// We look for 'peer_consult' messages (logged silently by chat-endpoint.php), 
// then fetch the preceding user message and the subsequent bot message to form the complete story.
$intercepts_raw = $wpdb->get_results("
    SELECT m_peer.id, m_peer.session_id, m_peer.content as peer_json, m_peer.created_at,
           (SELECT content FROM {$wpdb->prefix}kounselia_messages WHERE session_id = m_peer.session_id AND sender = 'user' AND id < m_peer.id ORDER BY id DESC LIMIT 1) as user_text,
           (SELECT content FROM {$wpdb->prefix}kounselia_messages WHERE session_id = m_peer.session_id AND sender = 'bot' AND id > m_peer.id ORDER BY id ASC LIMIT 1) as final_reply,
           s.counselor_slug as primary_slug, s.user_id 
    FROM {$wpdb->prefix}kounselia_messages m_peer
    JOIN {$wpdb->prefix}kounselia_sessions s ON s.id = m_peer.session_id
    WHERE m_peer.sender = 'peer_consult'
    ORDER BY m_peer.id DESC LIMIT 20
");

$intercepts = array();
foreach ($intercepts_raw as $row) {
    $peer_data = json_decode($row->peer_json, true);
    if (!$peer_data) continue;
    
    $primary_meta = kounselia_admin_counselor_avatar($row->primary_slug);
    $primary_name = kounselia_admin_counselor_name($row->primary_slug);
    
    $peer_slug = $peer_data['peer_slug'] ?? 'unknown';
    $peer_meta = kounselia_admin_counselor_avatar($peer_slug);
    $peer_name = kounselia_admin_counselor_name($peer_slug);
    
    $intercepts[$row->id] = array(
        'id'           => $row->id,
        'user_text'    => $row->user_text ?: '(Session context start)',
        'final_reply'  => $row->final_reply ?: '(Processing interrupted)',
        'peer_insight' => $peer_data['insight'] ?? '',
        'time_label'   => kounselia_admin_time_label($row->created_at),
        'user_label'   => $row->user_id ? 'Member ID: '.$row->user_id : 'Guest',
        'primary'      => ['slug'=>$row->primary_slug, 'name'=>$primary_name, 'color'=>$primary_meta['color'], 'initial'=>$primary_meta['letter']],
        'peer'         => ['slug'=>$peer_slug, 'name'=>$peer_name, 'color'=>$peer_meta['color'], 'initial'=>$peer_meta['letter']]
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>AI Collaboration Center — Kounselia</title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
<?php require __DIR__ . '/../inc/admin-styles.php'; ?>
<style>
/* 
 * STREAMING_CHUNK:Styling the Orchestration Visualizer... 
 */
.collab-layout { display: flex; gap: 24px; height: calc(100vh - 120px); min-height: 700px; }
@media (max-width: 1024px) { .collab-layout { flex-direction: column; height: auto; } }

/* Sidebar */
.collab-sidebar { width: 340px; display: flex; flex-direction: column; flex-shrink: 0; background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); box-shadow: var(--sh-sm); overflow: hidden; }
.cs-head { padding: 20px 24px; border-bottom: 1px solid var(--border); background: #FAFAF8; }
.cs-head h3 { font-size: 15px; font-weight: 600; color: var(--accent); margin-bottom: 4px; }
.cs-head p { font-size: 12px; color: var(--text3); }
.cs-list { flex: 1; overflow-y: auto; padding: 12px; }
.cs-item { padding: 16px; border: 1px solid transparent; border-bottom: 1px solid var(--surface2); cursor: pointer; transition: all 0.2s; border-radius: var(--r-sm); margin-bottom: 4px; }
.cs-item:hover { background: #FAFAF8; }
.cs-item.active { background: var(--accent-light); border-color: #C8D8EC; }
.cs-meta { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; font-size: 11.5px; color: var(--text3); }
.cs-text { font-size: 13.5px; color: var(--text); line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }

/* Main Visualizer Area */
.collab-main { flex: 1; background: var(--bg); border: 1px solid var(--border); border-radius: var(--r-lg); box-shadow: inset 0 2px 12px rgba(0,0,0,0.02); display: flex; flex-direction: column; overflow: hidden; position: relative; }
.cm-head { padding: 20px 32px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); background: var(--surface); z-index: 10; }
.cm-head h2 { font-family: 'Cormorant Garamond', serif; font-size: 24px; font-weight: 500; color: var(--accent); }
.status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: var(--r-full); font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; background: var(--surface2); color: var(--text3); transition: all 0.3s; }
.status-badge.active { background: var(--sage-light); color: var(--sage); }
.status-badge .dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
.status-badge.active .dot { animation: pulse 1.5s infinite; }
@keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(46,92,62,0.4); } 70% { box-shadow: 0 0 0 6px rgba(46,92,62,0); } 100% { box-shadow: 0 0 0 0 rgba(46,92,62,0); } }

/* The Node Graph */
.graph-container { flex: 1; overflow: auto; padding: 40px; display: flex; flex-direction: column; align-items: center; position: relative; }

.node-row { display: flex; width: 100%; max-width: 800px; margin-bottom: 60px; position: relative; justify-content: center; opacity: 0; transform: translateY(20px); transition: all 0.6s cubic-bezier(0.16, 1, 0.3, 1); }
.node-row.visible { opacity: 1; transform: translateY(0); }

/* Connectors */
.connector-v { position: absolute; top: -60px; left: 50%; width: 2px; height: 60px; background: var(--border); transform: translateX(-50%); transform-origin: top; scale: 1 0; transition: scale 0.6s ease; }
.connector-v.visible { scale: 1 1; }
.connector-v::after { content: ''; position: absolute; bottom: -4px; left: -4px; border: 5px solid transparent; border-top-color: var(--border); }

/* Specific Rows */
.row-user { justify-content: flex-start; }
.row-peer { justify-content: flex-end; }
.row-final { justify-content: flex-start; }

/* The Nodes */
.node { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-md); padding: 20px; box-shadow: var(--sh-md); width: 100%; max-width: 480px; position: relative; z-index: 5; transition: all 0.3s; }
.node.processing { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-light); }

.n-head { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; border-bottom: 1px solid var(--surface2); padding-bottom: 12px; }
.n-av { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 600; color: #fff; }
.n-meta h4 { font-size: 13px; font-weight: 600; color: var(--text); }
.n-meta p { font-size: 11px; color: var(--text3); text-transform: uppercase; letter-spacing: 0.5px; }

.n-body { font-size: 14.5px; color: var(--text); line-height: 1.6; }
.n-body.code { font-family: 'JetBrains Mono', monospace; font-size: 12px; color: var(--accent); background: var(--accent-light); padding: 12px; border-radius: var(--r-sm); }

/* Routing Path (SVG) */
.svg-layer { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; z-index: 1; }
.path-line { fill: none; stroke: var(--border); stroke-width: 2; stroke-dasharray: 6 6; stroke-dashoffset: 100; animation: dash 20s linear infinite; opacity: 0; transition: opacity 0.5s; }
.path-line.visible { opacity: 1; }
@keyframes dash { to { stroke-dashoffset: 0; } }

/* Typing indicator */
.typing { display: inline-flex; gap: 4px; align-items: center; height: 20px; }
.typing span { width: 6px; height: 6px; background: var(--text3); border-radius: 50%; animation: typeBounce 1.4s infinite ease-in-out both; }
.typing span:nth-child(1) { animation-delay: -0.32s; }
.typing span:nth-child(2) { animation-delay: -0.16s; }
@keyframes typeBounce { 0%, 80%, 100% { transform: scale(0); } 40% { transform: scale(1); } }
</style>
</head>
<body>

<?php require __DIR__ . '/../inc/admin-nav.php'; ?>

<div class="admin-body">
    
    <div class="collab-layout">
        
        <!-- Sidebar -->
        <div class="collab-sidebar">
            <div class="cs-head">
                <h3>Historical Consults</h3>
                <p>Real internal thoughts from the AI Gateway.</p>
            </div>
            <div class="cs-list" id="query-list">
                <?php if (empty($intercepts)): ?>
                    <div style="padding:20px; text-align:center; color:var(--text3); font-size:13px; line-height:1.6;">
                        No peer consultations logged yet.<br><br>
                        <em>Start a chat and send a long message (40+ chars) to trigger a team consult!</em>
                    </div>
                <?php else: ?>
                    <?php foreach ($intercepts as $q): ?>
                        <div class="cs-item" onclick="runSimulation(<?php echo $q['id']; ?>, this)">
                            <div class="cs-meta">
                                <span><i class="ti ti-user"></i> <?php echo esc_html($q['user_label']); ?></span>
                                <span><?php echo $q['time_label']; ?></span>
                            </div>
                            <div class="cs-text"><?php echo esc_html($q['user_text']); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Main Visualizer -->
        <div class="collab-main">
            <div class="cm-head">
                <h2>Orchestration Replay</h2>
                <div class="status-badge" id="sim-status">
                    <div class="dot"></div> <span id="sim-status-text">Standby</span>
                </div>
            </div>

            <div class="graph-container" id="graph-container">
                <svg class="svg-layer" id="svg-layer">
                    <!-- Complex diagonal paths injected via JS -->
                </svg>

                <!-- Initial Empty State -->
                <div id="empty-state" style="margin: auto; text-align: center; color: var(--text3); max-width: 300px;">
                    <i class="ti ti-route" style="font-size: 48px; margin-bottom: 16px; display: block; opacity: 0.5;"></i>
                    Select a logged event from the sidebar to audit the AI's internal reasoning flow.
                </div>

                <!-- Dynamic Nodes (Hidden initially) -->
                
                <!-- NODE 1: USER -->
                <div class="node-row row-user" id="node-user" style="display:none;">
                    <div class="node">
                        <div class="n-head">
                            <div class="n-av" style="background:var(--surface2); color:var(--text);"><i class="ti ti-user"></i></div>
                            <div class="n-meta"><h4>Client Input</h4><p>Incoming Request</p></div>
                        </div>
                        <div class="n-body" id="text-user"></div>
                    </div>
                </div>

                <!-- NODE 2: PRIMARY COUNSELOR (EVALUATING) -->
                <div class="node-row" id="node-primary-eval" style="display:none;">
                    <div class="connector-v" id="conn-1"></div>
                    <div class="node processing" id="box-primary-eval">
                        <div class="n-head">
                            <div class="n-av" id="av-primary-1"></div>
                            <div class="n-meta"><h4 id="name-primary-1">Primary</h4><p>Needs Clinical Consult</p></div>
                        </div>
                        <div class="n-body code" id="text-primary-eval">
                            <div class="typing"><span></span><span></span><span></span></div>
                        </div>
                    </div>
                </div>

                <!-- NODE 3: PEER COUNSELOR -->
                <div class="node-row row-peer" id="node-peer" style="display:none;">
                    <div class="node processing" id="box-peer" style="border-color: var(--gold); box-shadow: 0 0 0 3px var(--gold-light);">
                        <div class="n-head">
                            <div class="n-av" id="av-peer"></div>
                            <div class="n-meta"><h4 id="name-peer">Peer</h4><p>Specialist Opinion</p></div>
                        </div>
                        <div class="n-body code" id="text-peer" style="color: var(--gold); background: var(--gold-light);">
                            <div class="typing"><span></span><span></span><span></span></div>
                        </div>
                    </div>
                </div>

                <!-- NODE 4: PRIMARY (SYNTHESIS) -->
                <div class="node-row row-final" id="node-primary-synth" style="display:none;">
                    <div class="node processing" id="box-primary-synth" style="border-color: var(--sage); box-shadow: 0 0 0 3px var(--sage-light);">
                        <div class="n-head">
                            <div class="n-av" id="av-primary-2"></div>
                            <div class="n-meta"><h4 id="name-primary-2">Primary</h4><p>Final Synthesis</p></div>
                        </div>
                        <div class="n-body" id="text-primary-synth">
                            <div class="typing"><span></span><span></span><span></span></div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
/**
 * STREAMING_CHUNK:Wiring the orchestration animation logic...
 */
const INTERCEPTS = <?php echo wp_json_encode($intercepts); ?>;
let isSimulating = false;

function esc(t){ return String(t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function drawSVGPaths() {
    const svg = document.getElementById('svg-layer');
    svg.innerHTML = '';
    
    // Draw diagonal from Primary to Peer
    const boxP = document.getElementById('box-primary-eval');
    const boxPeer = document.getElementById('box-peer');
    const boxSynth = document.getElementById('box-primary-synth');
    
    if(!boxP || !boxPeer || !boxSynth) return;
    
    const svgRect = svg.getBoundingClientRect();
    
    // Line 1: Primary Eval to Peer
    if(boxPeer.offsetParent !== null) {
        const pRect = boxP.getBoundingClientRect();
        const peerRect = boxPeer.getBoundingClientRect();
        
        const startX = pRect.right - svgRect.left;
        const startY = pRect.top + (pRect.height/2) - svgRect.top;
        const endX = peerRect.left - svgRect.left;
        const endY = peerRect.top + (peerRect.height/2) - svgRect.top;
        
        const path1 = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path1.setAttribute('class', 'path-line');
        path1.setAttribute('id', 'svg-line-1');
        path1.setAttribute('d', `M ${startX} ${startY} C ${startX + 100} ${startY}, ${endX - 100} ${endY}, ${endX} ${endY}`);
        svg.appendChild(path1);
    }
    
    // Line 2: Peer to Synthesis
    if(boxSynth.offsetParent !== null) {
        const peerRect = boxPeer.getBoundingClientRect();
        const sRect = boxSynth.getBoundingClientRect();
        
        const startX = peerRect.left - svgRect.left;
        const startY = peerRect.bottom - 20 - svgRect.top;
        const endX = sRect.right - svgRect.left;
        const endY = sRect.top + (sRect.height/2) - svgRect.top;
        
        const path2 = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path2.setAttribute('class', 'path-line');
        path2.setAttribute('id', 'svg-line-2');
        path2.setAttribute('d', `M ${startX} ${startY} C ${startX - 100} ${startY}, ${endX + 100} ${endY}, ${endX} ${endY}`);
        svg.appendChild(path2);
    }
}

function runSimulation(msgId, el) {
    if (isSimulating) return;
    
    const data = INTERCEPTS[msgId];
    if (!data) return;

    // UI Resets
    document.querySelectorAll('.cs-item').forEach(i => i.classList.remove('active'));
    el.classList.add('active');
    
    document.getElementById('empty-state').style.display = 'none';
    
    // Hide nodes
    const nodes = ['node-user', 'node-primary-eval', 'node-peer', 'node-primary-synth'];
    nodes.forEach(id => {
        const n = document.getElementById(id);
        n.style.display = 'none';
        n.classList.remove('visible');
    });
    
    document.getElementById('conn-1').classList.remove('visible');
    document.getElementById('svg-layer').innerHTML = '';
    
    // Set Status
    isSimulating = true;
    const badge = document.getElementById('sim-status');
    const bText = document.getElementById('sim-status-text');
    badge.classList.add('active');
    bText.textContent = 'Replaying audit log...';

    // Populate Nodes structurally
    document.getElementById('text-user').innerHTML = esc(data.user_text).replace(/\n/g, '<br>');
    
    const pAv = `<div class="n-av" style="background:${data.primary.color};">${data.primary.initial}</div>`;
    document.getElementById('av-primary-1').outerHTML = pAv;
    document.getElementById('av-primary-2').outerHTML = pAv;
    document.getElementById('name-primary-1').textContent = data.primary.name;
    document.getElementById('name-primary-2').textContent = data.primary.name;
    
    const peerAv = `<div class="n-av" style="background:${data.peer.color};">${data.peer.initial}</div>`;
    document.getElementById('av-peer').outerHTML = peerAv;
    document.getElementById('name-peer').textContent = data.peer.name;
    
    // Reset typing indicators
    const typingHtml = '<div class="typing"><span></span><span></span><span></span></div>';
    document.getElementById('text-primary-eval').innerHTML = typingHtml;
    document.getElementById('text-peer').innerHTML = typingHtml;
    document.getElementById('text-primary-synth').innerHTML = typingHtml;

    // The Animation Sequence (Simulating the "time" it takes to read the log)
    
    // Step 1: User Input
    const nUser = document.getElementById('node-user');
    nUser.style.display = 'flex';
    setTimeout(() => nUser.classList.add('visible'), 50);

    // Step 2: Primary Evaluates (Delay 800ms)
    setTimeout(() => {
        const nPrim = document.getElementById('node-primary-eval');
        nPrim.style.display = 'flex';
        setTimeout(() => {
            nPrim.classList.add('visible');
            document.getElementById('conn-1').classList.add('visible');
        }, 50);
    }, 800);

    // Step 3: Route to Peer (Delay 2000ms)
    setTimeout(() => {
        document.getElementById('text-primary-eval').textContent = `[SYSTEM] Complexity detected. Routing to ${data.peer.name} for specialist opinion...`;
        document.getElementById('box-primary-eval').classList.remove('processing');
        
        const nPeer = document.getElementById('node-peer');
        nPeer.style.display = 'flex';
        
        setTimeout(() => {
            nPeer.classList.add('visible');
            drawSVGPaths();
            document.getElementById('svg-line-1').classList.add('visible');
        }, 50);
    }, 2000);

    // Step 4: Peer Answers (Delay 3800ms)
    setTimeout(() => {
        document.getElementById('text-peer').innerHTML = esc(data.peer_insight);
        document.getElementById('box-peer').classList.remove('processing');
    }, 3800);

    // Step 5: Primary Synthesizes (Delay 5000ms)
    setTimeout(() => {
        const nSynth = document.getElementById('node-primary-synth');
        nSynth.style.display = 'flex';
        
        setTimeout(() => {
            nSynth.classList.add('visible');
            drawSVGPaths(); // Redraw to include path 2
            if(document.getElementById('svg-line-1')) document.getElementById('svg-line-1').classList.add('visible');
            if(document.getElementById('svg-line-2')) document.getElementById('svg-line-2').classList.add('visible');
        }, 50);
    }, 5000);

    // Step 6: Final Output Streams (Delay 6500ms)
    setTimeout(() => {
        document.getElementById('text-primary-synth').innerHTML = esc(data.final_reply).replace(/\n/g, '<br>');
        document.getElementById('box-primary-synth').classList.remove('processing');
        
        // Finish
        isSimulating = false;
        badge.classList.remove('active');
        bText.textContent = 'Replay Completed';
        
    }, 6500);
}

// Redraw SVG on resize
window.addEventListener('resize', () => {
    if(!isSimulating && document.getElementById('node-peer').style.display !== 'none') {
        drawSVGPaths();
    }
});
</script>
</body>
</html>