<?php
/**
 * STREAMING_CHUNK:Initializing the Agentic Boardroom...
 * Kounselia Admin — Autonomous Clinical Boardroom (Multi-Agent Swarm)
 *
 * Allows the admin to present a case study or pull live CRM data.
 * Features an Autonomous Swarm mode where agents dynamically decide who speaks next.
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
$kounselia_admin_active = 'clinical-board';
global $wpdb;

$nonce = wp_create_nonce( 'kounselia_boardroom_nonce' );

// Fetch all active counselors
$counselors = function_exists('kounselia_admin_get_counselors_list') ? kounselia_admin_get_counselors_list() : array();

// ---------------------------------------------------------------------
// HANDLE SELF-CONTAINED AJAX FOR MULTI-AGENT SWARM
// ---------------------------------------------------------------------

// 1. Fetch Recent Live Cases
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'fetch_live_cases' ) {
    header('Content-Type: application/json');
    if ( ! wp_verify_nonce( $_POST['nonce'], 'kounselia_boardroom_nonce' ) ) {
        echo json_encode(['success' => false, 'message' => 'Security check failed.']); exit;
    }

    $cases = $wpdb->get_results("
        SELECT s.id as session_id, s.user_id, s.counselor_slug, s.started_at, u.display_name, u.user_email 
        FROM {$wpdb->prefix}kounselia_sessions s
        LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
        WHERE s.user_id IS NOT NULL
        ORDER BY s.started_at DESC LIMIT 20
    ");
    
    echo json_encode(['success' => true, 'cases' => $cases]);
    exit;
}

// 2. Load Specific Case Data (Memory + Chat)
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'load_case_data' ) {
    header('Content-Type: application/json');
    if ( ! wp_verify_nonce( $_POST['nonce'], 'kounselia_boardroom_nonce' ) ) {
        echo json_encode(['success' => false, 'message' => 'Security check failed.']); exit;
    }
    
    $session_id = absint($_POST['session_id']);
    $user_id = absint($_POST['user_id']);
    
    $memory_json = get_user_meta( $user_id, 'kounselia_core_memory', true );
    $reflection_json = get_user_meta( $user_id, 'kounselia_latest_reflection', true );
    
    $chat_history = $wpdb->get_results($wpdb->prepare("
        SELECT sender, content FROM (
            SELECT id, sender, content FROM {$wpdb->prefix}kounselia_messages 
            WHERE session_id = %d ORDER BY id DESC LIMIT 10
        ) sub ORDER BY id ASC
    ", $session_id));
    
    $presentation = "LIVE CASE LOADED (Session #{$session_id})\n";
    $presentation .= "--------------------------------------------------\n";
    
    if ($memory_json) {
        $mem = json_decode($memory_json, true);
        if (is_array($mem)) {
            $presentation .= "INTELLIGENCE PROFILE:\n";
            $presentation .= "- Identity: " . ($mem['identity'] ?? 'Unknown') . "\n";
            $presentation .= "- Active Context: " . ($mem['temporary_context'] ?? 'None') . "\n";
            if (!empty($mem['current_challenges'])) {
                $presentation .= "- Challenges: " . implode(', ', (array)$mem['current_challenges']) . "\n";
            }
            $presentation .= "--------------------------------------------------\n";
        }
    }
    
    if (!empty($chat_history)) {
        $presentation .= "RECENT TRANSCRIPT:\n";
        foreach ($chat_history as $msg) {
            $speaker = $msg->sender === 'bot' ? 'Counselor' : 'Client';
            $presentation .= "[{$speaker}]: {$msg->content}\n\n";
        }
    }
    
    $presentation .= "--------------------------------------------------\n";
    $presentation .= "DIRECTOR'S QUESTION: Team, based on this profile and the recent transcript, what are we missing here? How should we adjust our approach with this client?";
    
    echo json_encode(['success' => true, 'presentation' => trim($presentation)]);
    exit;
}

// 3. The Swarm Director (Decides who speaks next)
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'director_choose_next' ) {
    header('Content-Type: application/json');
    if ( ! wp_verify_nonce( $_POST['nonce'], 'kounselia_boardroom_nonce' ) ) {
        echo json_encode(['success' => false, 'message' => 'Security check failed.']); exit;
    }
    
    $history_json = isset($_POST['history']) ? wp_unslash($_POST['history']) : '[]';
    $raw_history = json_decode($history_json, true);
    if (!is_array($raw_history)) $raw_history = [];
    
    $available_slugs = isset($_POST['available_slugs']) ? explode(',', sanitize_text_field($_POST['available_slugs'])) : [];
    $available_json = json_encode($available_slugs);
    
    $transcript = "";
    foreach ($raw_history as $turn) {
        $transcript .= "[{$turn['name']}]: {$turn['text']}\n\n";
    }
    
    $director_prompt = "You are the orchestrator of an AI Swarm in a clinical staff lounge.\n\n";
    $director_prompt .= "Available Specialists (Slugs): {$available_json}\n\n";
    $director_prompt .= "Transcript so far:\n{$transcript}\n\n";
    $director_prompt .= "Based on the conversation flow, who should speak next? Pick someone who hasn't spoken recently, or someone whose specialty was just implicated.\n";
    $director_prompt .= "If the conversation has reached a natural conclusion and everyone has weighed in meaningfully, output 'STOP'.\n\n";
    $director_prompt .= "Output STRICTLY a JSON object: {\"next_speaker\": \"slug_or_STOP\"}";
    
    $error = '';
    $result = kounselia_call_gemini(
        "You are an autonomous JSON router.", 
        array(array('role' => 'user', 'parts' => array(array('text' => $director_prompt)))), 
        'gemini-2.5-flash-lite', 
        0.1, 
        100, 
        $error, 
        'application/json',
        10
    );
    
    $chosen_slug = 'STOP';
    if ($result) {
        $start = strpos($result, '{');
        $end = strrpos($result, '}');
        if ($start !== false && $end !== false) {
            $json_slice = substr($result, $start, $end - $start + 1);
            $data = json_decode($json_slice, true);
            if (is_array($data) && !empty($data['next_speaker'])) {
                $chosen_slug = $data['next_speaker'];
            }
        }
    }
    
    echo json_encode(['success' => true, 'next_speaker' => $chosen_slug]);
    exit;
}

// 4. The Therapist Turn (The Staff Lounge Dynamic)
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'boardroom_turn' ) {
    header('Content-Type: application/json');
    if ( ! wp_verify_nonce( $_POST['nonce'], 'kounselia_boardroom_nonce' ) ) {
        echo json_encode(['success' => false, 'message' => 'Security check failed.']);
        exit;
    }

    $slug = sanitize_key($_POST['counselor_slug']);
    $topic = wp_unslash($_POST['topic']); 
    
    $history_json = isset($_POST['history']) ? wp_unslash($_POST['history']) : '[]';
    $raw_history = json_decode($history_json, true);
    if (!is_array($raw_history)) $raw_history = [];

    $prompt_data = kounselia_get_counselor_prompt($slug);
    if (!$prompt_data) {
        echo json_encode(['success' => false, 'message' => 'Counselor not found.']); exit;
    }
    $c_name = kounselia_admin_counselor_name($slug);

    // The "Staff Lounge" override
    $system_prompt = $prompt_data['system_prompt'];
    $system_prompt .= "\n\nCRITICAL CONTEXT (STAFF LOUNGE DYNAMIC): You are NOT talking to a client. You are hanging out in the private staff lounge with your fellow AI therapists. The Clinical Director has brought a case file to the table.";
    $system_prompt .= "\n\nYOUR TASK: Read the case and the ongoing debate from your colleagues. Jump into the conversation naturally. Do NOT be stiff. Banter, disagree, bounce ideas off each other by name, and have fun with it. Be insightful but casual, like brilliant professionals off the clock. Keep it concise (2-4 sentences).";
    $system_prompt .= "\n\nANTI-ROBOT RULES (CRITICAL):\n1. NO CLICHES: NEVER start your response with 'I hear you', 'I agree with', or 'Building on what X said'. Just start talking, be bold, and be direct.\n2. NO PARROTING: DO NOT repeat what others have already said. Bring a completely new angle based on your specialty, or disagree entirely.\n3. NO SCRIPT FORMATTING: Do not prefix your response with your name (e.g. do not write '[Name]:'). Just write the words you are saying.\n4. NO LINE BREAKS: You must output your entire response as a single, continuous paragraph. If you use a line break, you will be cut off.";

    $prompt_text = "CLINICAL DIRECTOR PRESENTS THE CASE TO THE LOUNGE:\n\n" . $topic . "\n\n";

    $discussion_transcript = "";
    foreach ($raw_history as $turn) {
        $speaker = strtoupper($turn['name']);
        $text = $turn['text'];
        $discussion_transcript .= "[{$speaker}]: {$text}\n\n";
    }

    if (!empty($discussion_transcript)) {
        $prompt_text .= "LOUNGE DISCUSSION SO FAR:\n\n" . $discussion_transcript . "\n\n";
    }

    $prompt_text .= "It is your turn to speak, " . $c_name . ". What is your completely unique, non-repetitive take? (Remember: Just your dialogue, no name prefixes, and NO line breaks!).";

    $gemini_history = [];
    $gemini_history[] = array(
        'role' => 'user',
        'parts' => array( array( 'text' => $prompt_text ) )
    );

    $active_model = !empty($prompt_data['ai_model']) ? $prompt_data['ai_model'] : 'gemini-2.5-flash';
    $max_tokens   = !empty($prompt_data['max_tokens']) ? (int)$prompt_data['max_tokens'] : 400;
    $temp         = !empty($prompt_data['temperature']) ? (float)$prompt_data['temperature'] : 0.8;

    $error = '';
    $reply = kounselia_call_gemini($system_prompt, $gemini_history, $active_model, $temp, $max_tokens, $error);

    if ($reply) {
        // Forcefully strip line breaks to bypass stop-sequence truncation
        $reply = str_replace(array("\r", "\n"), ' ', trim($reply));
        
        // Clean up hallucinated script prefixes (e.g. "[Eli]: " or "Marcus: ") up to 20 chars long
        $reply = preg_replace('/^\[?[A-Za-z\.\s]{1,20}\]?:\s*/i', '', $reply);
        
        echo json_encode(['success' => true, 'reply' => $reply]);
    } else {
        echo json_encode(['success' => false, 'message' => $error ?: 'AI connection failed.']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Agentic Boardroom — Kounselia Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
<?php require __DIR__ . '/../inc/admin-styles.php'; ?>
<style>
/* 
 * STREAMING_CHUNK:Styling the Swarm Interface...
 */
.board-layout { display: flex; gap: 24px; height: calc(100vh - 120px); min-height: 700px; }
@media (max-width: 1024px) { .board-layout { flex-direction: column; height: auto; } .board-sidebar { width: 100%; } }

/* Sidebar: Participants */
.board-sidebar { width: 320px; display: flex; flex-direction: column; flex-shrink: 0; background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); box-shadow: var(--sh-sm); overflow: hidden; }
.bs-head { padding: 20px 24px; border-bottom: 1px solid var(--border); background: #FAFAF8; }
.bs-head h3 { font-size: 15px; font-weight: 600; color: var(--accent); margin-bottom: 4px; display: flex; align-items: center; gap: 8px; }
.bs-head p { font-size: 12px; color: var(--text3); }

.c-list { flex: 1; overflow-y: auto; padding: 12px; }
.c-item { display: flex; align-items: center; gap: 12px; padding: 12px; border-radius: var(--r-md); cursor: pointer; transition: background 0.2s; border: 1px solid transparent; }
.c-item:hover { background: #FAFAF8; }
.c-item.selected { background: var(--accent-light); border-color: #C8D8EC; }
.c-item .av { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
.c-item .meta h4 { font-size: 14px; font-weight: 600; color: var(--text); margin-bottom: 2px; }
.c-item .meta p { font-size: 12px; color: var(--text3); }
.c-item .check { margin-left: auto; color: var(--accent); opacity: 0; transition: opacity 0.2s; }
.c-item.selected .check { opacity: 1; }

/* Main Area */
.board-main { flex: 1; background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); box-shadow: var(--sh-md); display: flex; flex-direction: column; overflow: hidden; position: relative; }
.bm-head { padding: 20px 28px; border-bottom: 1px solid var(--border); background: #FAFAF8; display: flex; justify-content: space-between; align-items: center; }
.bm-head h2 { font-family: 'Cormorant Garamond', serif; font-size: 24px; font-weight: 500; color: var(--accent); display: flex; align-items: center; gap: 10px; }
.bm-actions { display: flex; gap: 12px; }

.chat-area { flex: 1; overflow-y: auto; padding: 28px; display: flex; flex-direction: column; gap: 20px; background: var(--bg); }

/* Chat Bubbles */
.msg-wrap { display: flex; flex-direction: column; max-width: 85%; animation: slideUp 0.3s ease; }
@keyframes slideUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

.msg-wrap.director { align-self: flex-end; align-items: flex-end; }
.msg-wrap.ai { align-self: flex-start; align-items: flex-start; }

.msg-name { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: var(--text3); margin-bottom: 6px; display: flex; align-items: center; gap: 6px; }
.msg-bubble { padding: 16px 20px; border-radius: 16px; font-size: 14.5px; line-height: 1.55; box-shadow: var(--sh-sm); }
.msg-wrap.director .msg-bubble { background: var(--accent); color: #fff; border-bottom-right-radius: 4px; font-family: 'JetBrains Mono', monospace; font-size: 13px; }
.msg-wrap.ai .msg-bubble { background: var(--surface); color: var(--text); border: 1px solid var(--border); border-top-left-radius: 4px; }
.msg-bubble p { margin-bottom: 12px; }
.msg-bubble p:last-child { margin-bottom: 0; }

.typing { display: inline-flex; gap: 4px; align-items: center; height: 24px; padding: 0 8px; }
.typing span { width: 6px; height: 6px; background: var(--text3); border-radius: 50%; animation: typeBounce 1.4s infinite ease-in-out both; }
.typing span:nth-child(1) { animation-delay: -0.32s; }
.typing span:nth-child(2) { animation-delay: -0.16s; }

/* Input Area */
.input-area { padding: 20px 28px; border-top: 1px solid var(--border); background: var(--surface); }
.topic-box { width: 100%; padding: 16px; border: 1px solid var(--border); border-radius: var(--r-md); font-family: 'Outfit', sans-serif; font-size: 14.5px; resize: vertical; min-height: 80px; background: var(--bg); transition: border-color 0.2s; margin-bottom: 16px; }
.topic-box:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-light); }
.input-controls { display: flex; justify-content: space-between; align-items: center; }

/* Swarm Toggle */
.swarm-toggle { display: flex; align-items: center; gap: 12px; background: var(--bg); padding: 8px 16px; border-radius: var(--r-full); border: 1px solid var(--border); }
.swarm-toggle label { font-size: 13px; font-weight: 600; color: var(--text); display: flex; align-items: center; gap: 6px; cursor: pointer; }
.swarm-switch { position: relative; width: 40px; height: 22px; background: var(--surface2); border-radius: 20px; transition: 0.3s; cursor: pointer; }
.swarm-switch::after { content: ''; position: absolute; top: 2px; left: 2px; width: 18px; height: 18px; background: #fff; border-radius: 50%; transition: 0.3s; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
.swarm-switch.on { background: var(--accent); }
.swarm-switch.on::after { transform: translateX(18px); }

.btn-primary { padding: 12px 24px; background: var(--accent); color: #fff; border: none; border-radius: var(--r-sm); font-size: 14px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: all 0.2s; }
.btn-primary:hover { background: var(--accent2); transform: translateY(-1px); }
.btn-primary:disabled { opacity: 0.7; cursor: not-allowed; transform: none; }

/* Modal */
.modal-overlay { position: fixed; inset: 0; background: rgba(24,22,15,0.4); backdrop-filter: blur(2px); z-index: 1000; display: none; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.3s ease; }
.modal-overlay.open { display: flex; opacity: 1; }
.modal-box { background: var(--surface); width: 100%; max-width: 600px; border-radius: var(--r-lg); box-shadow: var(--sh-lg); overflow: hidden; transform: translateY(20px); transition: transform 0.3s ease; display: flex; flex-direction: column; max-height: 80vh; }
.modal-overlay.open .modal-box { transform: translateY(0); }
.modal-head { padding: 20px 24px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: #FAFAF8; }
.modal-head h3 { font-size: 18px; font-weight: 600; color: var(--accent); margin: 0; }
.modal-close { background: none; border: none; font-size: 20px; color: var(--text3); cursor: pointer; }
.modal-body { padding: 0; overflow-y: auto; flex: 1; }
.case-item { padding: 16px 24px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; cursor: pointer; transition: background 0.2s; }
.case-item:hover { background: var(--bg); }
.case-item:last-child { border-bottom: none; }
.case-meta h4 { font-size: 14.5px; font-weight: 600; color: var(--text); margin-bottom: 4px; }
.case-meta p { font-size: 12px; color: var(--text3); }

/* Color overrides for AI bubbles */
.msg-wrap.ai[data-color="ic-rose"] .msg-bubble { border-left: 4px solid var(--rose); }
.msg-wrap.ai[data-color="ic-blue"] .msg-bubble { border-left: 4px solid var(--accent); }
.msg-wrap.ai[data-color="ic-sage"] .msg-bubble { border-left: 4px solid var(--sage); }
.msg-wrap.ai[data-color="ic-gold"] .msg-bubble { border-left: 4px solid var(--gold); }
.msg-wrap.ai[data-color="ic-teal"] .msg-bubble { border-left: 4px solid var(--teal); }
.msg-wrap.ai[data-color="ic-plum"] .msg-bubble { border-left: 4px solid var(--plum); }
.msg-wrap.ai[data-color="ic-sienna"] .msg-bubble { border-left: 4px solid var(--sienna); }
.msg-wrap.ai[data-color="ic-navy"] .msg-bubble { border-left: 4px solid var(--navy); }
</style>
</head>
<body>

<?php require __DIR__ . '/../inc/admin-nav.php'; ?>

<div class="admin-body">
    
    <div class="board-layout">
        
        <!-- Sidebar -->
        <div class="board-sidebar">
            <div class="bs-head">
                <h3><i class="ti ti-users-group"></i> Staff Lounge</h3>
                <p>Invite specialists to the table.</p>
            </div>
            <div class="c-list" id="participant-list">
                <?php foreach ($counselors as $slug => $c): if (!$c['is_active']) continue; ?>
                    <div class="c-item" data-slug="<?php echo esc_attr($slug); ?>" data-name="<?php echo esc_attr($c['name']); ?>" data-color="<?php echo esc_attr($c['class']); ?>" onclick="toggleParticipant(this)">
                        <div class="av <?php echo esc_attr($c['class']); ?>"><i class="ti <?php echo esc_attr($c['icon']); ?>"></i></div>
                        <div class="meta">
                            <h4><?php echo esc_html($c['name']); ?></h4>
                            <p><?php echo esc_html($c['spec']); ?></p>
                        </div>
                        <i class="ti ti-circle-check-filled check"></i>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Main Area -->
        <div class="board-main">
            <div class="bm-head">
                <h2><i class="ti ti-brand-hipchat"></i> Agentic Boardroom</h2>
                <div class="bm-actions">
                    <button class="btn-ghost" style="padding: 6px 12px; font-size: 13px; border: 1px solid var(--border);" onclick="openCaseModal()"><i class="ti ti-database-import"></i> Load Live Case</button>
                    <button class="btn-ghost" style="padding: 6px 12px; font-size: 13px;" onclick="clearRoom()"><i class="ti ti-trash"></i> Clear</button>
                </div>
            </div>

            <div class="chat-area" id="chat-area">
                <div class="empty-state" id="empty-state" style="margin: auto;">
                    <i class="ti ti-vector-bezier-2" style="font-size: 48px; color: var(--text3); margin-bottom: 16px; display: block;"></i>
                    Select staff from the left. Type a scenario or load a live CRM case.<br>Turn on Swarm Mode to let the AIs route the conversation autonomously.
                </div>
            </div>

            <div class="input-area">
                <textarea id="topic-input" class="topic-box" placeholder="Present a case study, a scenario, or hit 'Load Live Case' above..."></textarea>
                <div class="input-controls">
                    
                    <div class="swarm-toggle" onclick="toggleSwarm()">
                        <div class="swarm-switch" id="swarm-switch"></div>
                        <label><i class="ti ti-atom"></i> Autonomous Swarm</label>
                    </div>

                    <div style="display:flex; align-items:center; gap: 16px;">
                        <div class="status-text" id="status-text" style="font-size:13px; color:var(--text3); font-weight:500;">Waiting to start...</div>
                        <button class="btn-primary" id="btn-start" onclick="startConference()">
                            <i class="ti ti-player-play"></i> Start Conference
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Load Cases -->
<div class="modal-overlay" id="caseModal">
    <div class="modal-box">
        <div class="modal-head">
            <h3>Load Active Session</h3>
            <button class="modal-close" onclick="closeCaseModal()"><i class="ti ti-x"></i></button>
        </div>
        <div class="modal-body" id="caseList">
            <div style="padding: 40px; text-align: center; color: var(--text3);"><i class="ti ti-loader-2" style="animation: spin 1s linear infinite;"></i> Loading recent cases...</div>
        </div>
    </div>
</div>

<script>
/**
 * STREAMING_CHUNK:Wiring the Agentic Swarm...
 */
const NONCE = "<?php echo esc_js( $nonce ); ?>";

let selectedParticipants = [];
let meetingHistory = [];
let meetingQueue = [];
let currentTopic = "";
let isMeetingActive = false;
let isSwarmMode = false;
let swarmTurns = 0;
const MAX_SWARM_TURNS = 8; // Auto-stop to prevent infinite loops and runaway costs

// UI Toggles
function toggleSwarm() {
    if (isMeetingActive) return;
    isSwarmMode = !isSwarmMode;
    const sw = document.getElementById('swarm-switch');
    if (isSwarmMode) sw.classList.add('on');
    else sw.classList.remove('on');
}

function toggleParticipant(el) {
    if (isMeetingActive) return;
    el.classList.toggle('selected');
    
    selectedParticipants = [];
    document.querySelectorAll('.c-item.selected').forEach(item => {
        selectedParticipants.push({
            slug: item.dataset.slug,
            name: item.dataset.name,
            color: item.dataset.color
        });
    });

    if (selectedParticipants.length > 0) {
        document.getElementById('status-text').innerHTML = `<span style="color:var(--sage);"><i class="ti ti-check"></i> ${selectedParticipants.length} staff ready.</span>`;
    } else {
        document.getElementById('status-text').innerHTML = `Waiting to start...`;
    }
}

function clearRoom() {
    if (isMeetingActive) return;
    document.getElementById('chat-area').innerHTML = `
        <div class="empty-state" id="empty-state" style="margin: auto;">
            <i class="ti ti-vector-bezier-2" style="font-size: 48px; color: var(--text3); margin-bottom: 16px; display: block;"></i>
            Select staff from the left. Type a scenario or load a live CRM case.<br>Turn on Swarm Mode to let the AIs route the conversation autonomously.
        </div>`;
    document.getElementById('topic-input').value = "";
    meetingHistory = [];
}

// Case Loader
function openCaseModal() {
    document.getElementById('caseModal').classList.add('open');
    const formData = new URLSearchParams({ action: 'fetch_live_cases', nonce: NONCE });
    
    fetch(window.location.href, { method: 'POST', body: formData })
    .then(r => r.json()).then(data => {
        const list = document.getElementById('caseList');
        if (!data.success || !data.cases.length) {
            list.innerHTML = '<div style="padding: 20px; text-align: center;">No active cases found.</div>';
            return;
        }
        let html = '';
        data.cases.forEach(c => {
            html += `
            <div class="case-item" onclick="selectCase(${c.session_id}, ${c.user_id})">
                <div class="case-meta">
                    <h4>Session #${c.session_id} - ${c.display_name || c.user_email}</h4>
                    <p>Counselor: ${c.counselor_slug.toUpperCase()} • Started: ${c.started_at}</p>
                </div>
                <i class="ti ti-chevron-right" style="color:var(--text3);"></i>
            </div>`;
        });
        list.innerHTML = html;
    });
}

function closeCaseModal() { document.getElementById('caseModal').classList.remove('open'); }

function selectCase(sessionId, userId) {
    document.getElementById('caseList').innerHTML = '<div style="padding: 40px; text-align: center;"><i class="ti ti-loader-2" style="animation: spin 1s linear infinite;"></i> Compiling case data...</div>';
    
    const formData = new URLSearchParams({ action: 'load_case_data', nonce: NONCE, session_id: sessionId, user_id: userId });
    fetch(window.location.href, { method: 'POST', body: formData })
    .then(r => r.json()).then(data => {
        closeCaseModal();
        if (data.success) {
            document.getElementById('topic-input').value = data.presentation;
        }
    });
}

// Chat UI Helpers
function esc(t){ return String(t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function appendMessage(role, name, text, colorClass = '') {
    const chat = document.getElementById('chat-area');
    document.getElementById('empty-state')?.remove();

    const wrap = document.createElement('div');
    wrap.className = `msg-wrap ${role}`;
    if (colorClass) wrap.setAttribute('data-color', colorClass);

    let formattedText = esc(text).replace(/\n\n/g, '</p><p>').replace(/\n/g, '<br>');
    wrap.innerHTML = `
        <div class="msg-name">${name}</div>
        <div class="msg-bubble"><p>${formattedText}</p></div>
    `;
    
    chat.appendChild(wrap);
    chat.scrollTop = chat.scrollHeight;
    return wrap;
}

function appendTyping(name, colorClass) {
    const chat = document.getElementById('chat-area');
    const wrap = document.createElement('div');
    wrap.className = `msg-wrap ai`;
    wrap.id = 'typing-indicator';
    wrap.setAttribute('data-color', colorClass);
    wrap.innerHTML = `
        <div class="msg-name">${name}</div>
        <div class="msg-bubble" style="padding: 12px 16px;"><div class="typing"><span></span><span></span><span></span></div></div>
    `;
    chat.appendChild(wrap);
    chat.scrollTop = chat.scrollHeight;
}
function removeTyping() { document.getElementById('typing-indicator')?.remove(); }

// Orchestration Logic
function startConference() {
    const topic = document.getElementById('topic-input').value.trim();
    if (!topic) { alert("Please enter a case study or topic."); return; }
    if (selectedParticipants.length === 0) { alert("Please select at least one staff member."); return; }

    isMeetingActive = true;
    swarmTurns = 0;
    document.getElementById('btn-start').disabled = true;
    document.getElementById('topic-input').disabled = true;
    
    currentTopic = topic;
    meetingHistory = []; 
    meetingQueue = [...selectedParticipants]; 

    appendMessage('director', 'Clinical Director', topic);
    document.getElementById('topic-input').value = "";

    if (isSwarmMode) {
        processSwarmNext();
    } else {
        processNextInQueue();
    }
}

// ---------------------------------------------------------------------
// THE AGENTIC SWARM LOOP
// ---------------------------------------------------------------------
function processSwarmNext() {
    if (swarmTurns >= MAX_SWARM_TURNS) {
        endMeeting("Swarm reached maximum turns."); return;
    }

    document.getElementById('status-text').innerHTML = `<i class="ti ti-loader-2" style="animation:spin 1s linear infinite"></i> Director routing...`;
    
    const availableSlugs = selectedParticipants.map(p => p.slug).join(',');
    
    const formData = new URLSearchParams();
    formData.append('action', 'director_choose_next');
    formData.append('nonce', NONCE);
    formData.append('available_slugs', availableSlugs);
    formData.append('history', JSON.stringify(meetingHistory));

    fetch(window.location.href, { method: 'POST', body: formData })
    .then(r => r.json()).then(data => {
        if (!data.success || data.next_speaker === 'STOP') {
            endMeeting("Swarm concluded naturally."); return;
        }

        const nextSlug = data.next_speaker;
        const participant = selectedParticipants.find(p => p.slug === nextSlug);
        
        if (!participant) {
            // Fallback if director hallucinates a non-invited slug
            endMeeting("Director error: Unrecognized agent."); return;
        }

        executeAgentTurn(participant, () => {
            swarmTurns++;
            setTimeout(processSwarmNext, 800); // Recurse
        });
    }).catch(() => endMeeting("Network error routing swarm."));
}

// ---------------------------------------------------------------------
// THE SEQUENTIAL LOOP (Fallback/Legacy)
// ---------------------------------------------------------------------
function processNextInQueue() {
    if (meetingQueue.length === 0) {
        endMeeting("Sequential round concluded."); return;
    }
    const participant = meetingQueue.shift();
    executeAgentTurn(participant, () => {
        setTimeout(processNextInQueue, 800);
    });
}

// ---------------------------------------------------------------------
// CORE EXECUTION
// ---------------------------------------------------------------------
function executeAgentTurn(participant, callback) {
    document.getElementById('status-text').innerHTML = `<i class="ti ti-loader-2" style="animation:spin 1s linear infinite"></i> ${participant.name} speaking...`;
    appendTyping(participant.name, participant.color);

    const formData = new URLSearchParams();
    formData.append('action', 'boardroom_turn');
    formData.append('nonce', NONCE);
    formData.append('counselor_slug', participant.slug);
    formData.append('topic', currentTopic);
    formData.append('history', JSON.stringify(meetingHistory));

    fetch(window.location.href, { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        removeTyping();
        if (data.success) {
            appendMessage('ai', participant.name, data.reply, participant.color);
            meetingHistory.push({ name: participant.name, text: data.reply });
            callback();
        } else {
            endMeeting("Agent failed to respond.");
        }
    })
    .catch(() => {
        removeTyping();
        endMeeting("Network error.");
    });
}

function endMeeting(msg) {
    isMeetingActive = false;
    document.getElementById('btn-start').disabled = false;
    document.getElementById('topic-input').disabled = false;
    document.getElementById('status-text').innerHTML = `<span style="color:var(--accent);"><i class="ti ti-flag-checkered"></i> ${msg}</span>`;
}
</script>
</body>
</html>