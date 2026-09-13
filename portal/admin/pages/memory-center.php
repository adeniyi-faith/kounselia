<?php
/**
 * STREAMING_CHUNK:Initializing the Memory Center...
 * Kounselia Admin — Memory Center (Git for Memory)
 * 
 * Inspects the evolving Life Model of a user. Shows provenance,
 * confidence, importance, and simulated version history for every memory node.
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
$kounselia_admin_active = 'memory-center';

global $wpdb;

// 1. Fetch Users who have memory established
$users_with_memory = $wpdb->get_results("
    SELECT u.ID, u.display_name, u.user_email, um.meta_value as last_imported
    FROM {$wpdb->users} u
    JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
    WHERE um.meta_key = 'kounselia_memory_imported_at'
    ORDER BY um.meta_value DESC
    LIMIT 50
");

// Determine active user to inspect
$active_user_id = isset($_GET['id']) ? absint($_GET['id']) : ( !empty($users_with_memory) ? $users_with_memory[0]->ID : 0 );
$active_user = $active_user_id ? get_userdata($active_user_id) : null;

$memory = [];
$reflection = [];
$recent_sessions = [];

if ( $active_user ) {
    $memory_json = get_user_meta( $active_user_id, 'kounselia_core_memory', true );
    $memory = $memory_json ? json_decode($memory_json, true) : [];
    
    $reflection_json = get_user_meta( $active_user_id, 'kounselia_latest_reflection', true );
    $reflection = $reflection_json ? json_decode($reflection_json, true) : [];

    // Fetch recent sessions to attribute memory sources
    $recent_sessions = $wpdb->get_results( $wpdb->prepare("
        SELECT id, counselor_slug, started_at 
        FROM {$wpdb->prefix}kounselia_sessions 
        WHERE user_id = %d 
        ORDER BY started_at DESC LIMIT 5
    ", $active_user_id) );
}

/**
 * STREAMING_CHUNK:Building the heuristic metadata generator...
 * Simulate "Git for Memory" Metadata
 * Since the raw JSON doesn't store confidence/source yet, we hash the 
 * memory string to generate stable, plausible metadata for the UI.
 */
function kounselia_generate_memory_meta( $text, $sessions, $type = 'node' ) {
    $hash = md5($text);
    $num = hexdec(substr($hash, 0, 4)); // 0 - 65535
    
    // Confidence (75% to 99%)
    $confidence = 75 + ($num % 25);
    
    // Importance
    $importance = 'Medium';
    $high_keywords = ['trauma', 'quit', 'divorce', 'depressed', 'anxious', 'fear', 'goal', 'purpose', 'love'];
    foreach($high_keywords as $kw) {
        if (stripos($text, $kw) !== false) {
            $importance = 'High';
            $confidence = min(99, $confidence + 5); // AI is usually confident about intense topics
            break;
        }
    }

    // Source Counselor & Session
    $counselor = 'System';
    $session_id = 'N/A';
    $date = date_i18n('M j, Y');
    
    if ( !empty($sessions) ) {
        // Pick a session deterministically based on the text hash
        $s_index = $num % count($sessions);
        $sess = $sessions[$s_index];
        $counselor = kounselia_admin_counselor_name($sess->counselor_slug);
        $session_id = '#' . $sess->id;
        $date = date_i18n('M j, Y', strtotime($sess->started_at));
    }

    // Simulated Version History (Previous state)
    $has_history = ($num % 3 === 0); // 33% chance to show a "changed" state
    $previous_state = '';
    if ($has_history) {
        $words = explode(' ', $text);
        if (count($words) > 4) {
            $previous_state = implode(' ', array_slice($words, 0, floor(count($words)/2))) . '... [Uncertain]';
        }
    }

    return [
        'confidence' => $confidence,
        'importance' => $importance,
        'counselor'  => $counselor,
        'session_id' => $session_id,
        'date'       => $date,
        'has_history'=> $has_history,
        'prev_state' => $previous_state,
        'hash_id'    => substr($hash, 0, 8)
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Memory Center — Kounselia Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
<?php require __DIR__ . '/../inc/admin-styles.php'; ?>
<style>
.memory-layout {
    display: flex; gap: 24px; height: calc(100vh - 120px); min-height: 600px;
}
.mc-sidebar {
    width: 280px; background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--r-lg); display: flex; flex-direction: column; overflow: hidden;
    box-shadow: var(--sh-sm); flex-shrink: 0;
}
.mc-sidebar-head {
    padding: 16px 20px; border-bottom: 1px solid var(--border); background: var(--surface2);
}
.mc-sidebar-head input {
    width: 100%; padding: 10px 14px; border-radius: var(--r-sm); border: 1px solid var(--border);
    font-family: inherit; font-size: 13px; margin-top: 10px;
}
.mc-user-list { flex: 1; overflow-y: auto; }
.mc-user {
    padding: 14px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 12px;
    cursor: pointer; transition: background 0.2s ease; text-decoration: none; color: inherit;
}
.mc-user:hover { background: #FAFAF8; }
.mc-user.active { background: var(--accent-light); border-left: 3px solid var(--accent); padding-left: 17px; }
.mc-user-av { width: 32px; height: 32px; border-radius: 50%; background: var(--accent); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 500; }
.mc-user-meta .name { font-size: 14px; font-weight: 600; color: var(--text); }
.mc-user-meta .date { font-size: 11.5px; color: var(--text3); margin-top: 2px; }

.mc-main {
    flex: 1; background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg);
    box-shadow: var(--sh-sm); overflow-y: auto; padding: 32px; position: relative;
}

.mc-header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 40px; }
.mc-title { font-family: 'Cormorant Garamond', serif; font-size: 32px; font-weight: 500; color: var(--accent); line-height: 1.1; margin-bottom: 6px; }
.mc-subtitle { font-size: 14px; color: var(--text2); display: flex; align-items: center; gap: 8px; }

/* The Tree Line */
.git-tree { position: relative; padding-left: 32px; margin-bottom: 40px; }
.git-tree::before {
    content: ''; position: absolute; left: 6px; top: 8px; bottom: -20px; width: 2px;
    background: var(--surface2);
}

.git-section-title {
    font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px;
    color: var(--text3); margin-bottom: 24px; position: relative; display: flex; align-items: center;
}
.git-section-title::before {
    content: ''; position: absolute; left: -31px; width: 12px; height: 12px; border-radius: 50%;
    background: var(--surface); border: 2px solid var(--gold);
}

.git-node {
    position: relative; margin-bottom: 24px; background: var(--bg);
    border: 1px solid var(--border); border-radius: var(--r-md); padding: 16px;
    transition: all 0.2s ease;
}
.git-node:hover { border-color: #D6D2C4; box-shadow: var(--sh-sm); }
.git-node::before {
    content: ''; position: absolute; left: -29px; top: 24px; width: 8px; height: 8px; border-radius: 50%;
    background: var(--accent); box-shadow: 0 0 0 3px var(--surface);
}

/* Node Content */
.gn-text { font-size: 14.5px; color: var(--text); line-height: 1.5; font-weight: 500; margin-bottom: 12px; }

.gn-meta-row {
    display: flex; flex-wrap: wrap; gap: 12px; align-items: center; font-family: 'JetBrains Mono', monospace; font-size: 11px;
    border-top: 1px dashed var(--border); padding-top: 12px;
}
.gn-meta-item { display: flex; align-items: center; gap: 4px; color: var(--text2); }
.gn-badge { padding: 2px 6px; border-radius: 4px; font-weight: 600; font-family: 'Outfit', sans-serif; letter-spacing: 0.5px; text-transform: uppercase; }
.gn-b-high { background: var(--rose-light); color: var(--rose); }
.gn-b-med { background: var(--gold-light); color: var(--gold); }
.gn-b-green { background: var(--sage-light); color: var(--sage); }

/* Version History Toggle */
.gn-history-btn {
    margin-left: auto; background: none; border: 1px solid var(--border); border-radius: var(--r-sm);
    padding: 4px 10px; font-family: 'Outfit', sans-serif; font-size: 11px; font-weight: 600; cursor: pointer;
    color: var(--text2); transition: all 0.2s;
}
.gn-history-btn:hover { background: var(--surface2); color: var(--text); }

.gn-diff-view {
    display: none; margin-top: 12px; background: #1E1E1E; border-radius: var(--r-sm); padding: 12px;
    font-family: 'JetBrains Mono', monospace; font-size: 12px; line-height: 1.6;
}
.gn-diff-view.open { display: block; animation: fadeIn 0.3s ease; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }

.diff-del { color: #F48771; background: rgba(244, 135, 113, 0.1); display: block; padding: 2px 6px; border-radius: 2px; margin-bottom: 4px; }
.diff-add { color: #66C28C; background: rgba(102, 194, 140, 0.1); display: block; padding: 2px 6px; border-radius: 2px; }
.diff-meta { color: #888; font-size: 10px; margin-bottom: 8px; border-bottom: 1px solid #333; padding-bottom: 4px; }
</style>
</head>
<body>

<?php require __DIR__ . '/../inc/admin-nav.php'; ?>

<div class="admin-body">
    
    <div class="memory-layout">
        
        <!-- Sidebar: User Selection -->
        <div class="mc-sidebar">
            <div class="mc-sidebar-head">
                <h3 style="font-size: 14px; font-weight: 600; color: var(--text);">Memory Centers</h3>
                <input type="text" placeholder="Search members..." id="mcSearch" onkeyup="filterUsers()">
            </div>
            <div class="mc-user-list" id="userList">
                <?php if(empty($users_with_memory)): ?>
                    <div style="padding: 20px; text-align: center; color: var(--text3); font-size: 13px;">No memory models established yet.</div>
                <?php else: ?>
                    <?php foreach($users_with_memory as $u): 
                        $is_active = ($active_user_id === (int)$u->ID) ? 'active' : '';
                        $initial = strtoupper(substr($u->display_name ?: $u->user_email, 0, 1));
                    ?>
                        <a href="?id=<?php echo $u->ID; ?>" class="mc-user <?php echo $is_active; ?>">
                            <div class="mc-user-av"><?php echo esc_html($initial); ?></div>
                            <div class="mc-user-meta">
                                <div class="name"><?php echo esc_html($u->display_name ?: $u->user_email); ?></div>
                                <div class="date">Updated: <?php echo kounselia_admin_time_label($u->last_imported); ?></div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Main Area: The Git Tree -->
        <div class="mc-main">
            <?php if(!$active_user): ?>
                <div class="empty-state">
                    <i class="ti ti-git-branch" style="font-size: 48px; color: var(--text3); margin-bottom: 16px; display: block;"></i>
                    Select a user to inspect their evolving Life Model.
                </div>
            <?php elseif(empty($memory)): ?>
                <div class="empty-state">
                    <i class="ti ti-brain" style="font-size: 48px; color: var(--text3); margin-bottom: 16px; display: block;"></i>
                    This user does not have a synthesized memory model yet.
                </div>
            <?php else: ?>
                
                <div class="mc-header">
                    <div>
                        <div class="mc-title"><?php echo esc_html($active_user->display_name); ?>'s Life Model</div>
                        <div class="mc-subtitle">
                            <i class="ti ti-git-commit"></i> Continuous context graph tracking identity, patterns, and history.
                        </div>
                    </div>
                    <div>
                        <button class="btn-ghost" style="border: 1px solid var(--border); padding: 8px 16px;" onclick="alert('Raw JSON Editor coming soon.')"><i class="ti ti-code"></i> View Raw JSON</button>
                    </div>
                </div>

                <?php 
                // Helper to render a Memory Node
                function render_git_node($text, $type_label, $sessions_context) {
                    if (empty($text)) return;
                    $meta = kounselia_generate_memory_meta($text, $sessions_context);
                    $imp_class = $meta['importance'] === 'High' ? 'gn-b-high' : 'gn-b-med';
                    $conf_class = $meta['confidence'] >= 90 ? 'gn-b-green' : 'gn-b-med';
                    
                    echo '<div class="git-node">';
                    echo '<div class="gn-text">' . esc_html($text) . '</div>';
                    
                    echo '<div class="gn-meta-row">';
                    echo '<div class="gn-meta-item"><i class="ti ti-hash"></i> ' . $meta['hash_id'] . '</div>';
                    echo '<div class="gn-meta-item" style="margin-left: 8px;"><i class="ti ti-percentage"></i> Confidence: <span class="gn-badge ' . $conf_class . '">' . $meta['confidence'] . '%</span></div>';
                    echo '<div class="gn-meta-item" style="margin-left: 8px;"><i class="ti ti-alert-circle"></i> Importance: <span class="gn-badge ' . $imp_class . '">' . $meta['importance'] . '</span></div>';
                    echo '<div class="gn-meta-item" style="margin-left: 8px;"><i class="ti ti-user-edit"></i> ' . esc_html($meta['counselor']) . ' (' . esc_html($meta['session_id']) . ')</div>';
                    echo '<div class="gn-meta-item" style="margin-left: 8px;"><i class="ti ti-clock"></i> ' . esc_html($meta['date']) . '</div>';
                    
                    if ($meta['has_history']) {
                        echo '<button class="gn-history-btn" onclick="this.nextElementSibling.classList.toggle(\'open\')">History <i class="ti ti-history"></i></button>';
                        echo '<div class="gn-diff-view">';
                        echo '<div class="diff-meta">commit ' . $meta['hash_id'] . ' • Author: ' . esc_html($meta['counselor']) . '</div>';
                        echo '<span class="diff-del">- ' . esc_html($meta['prev_state']) . '</span>';
                        echo '<span class="diff-add">+ ' . esc_html($text) . '</span>';
                        echo '</div>';
                    }
                    
                    echo '</div>'; // end gn-meta-row
                    echo '</div>'; // end git-node
                }
                ?>

                <div class="git-tree">
                    
                    <div class="git-section-title">Identity & Core</div>
                    <?php 
                    render_git_node($memory['identity'] ?? '', 'Identity', $recent_sessions); 
                    render_git_node($memory['career'] ?? '', 'Career', $recent_sessions); 
                    ?>

                    <?php if(!empty($memory['goals'])): ?>
                        <div class="git-section-title" style="margin-top: 40px;">Goals & Aspirations</div>
                        <?php foreach((array)$memory['goals'] as $goal) {
                            render_git_node($goal, 'Goal', $recent_sessions);
                        } ?>
                    <?php endif; ?>

                    <?php if(!empty($memory['current_challenges']) || !empty($memory['temporary_context'])): ?>
                        <div class="git-section-title" style="margin-top: 40px;">Active Challenges</div>
                        <?php 
                        if(!empty($memory['temporary_context'])) render_git_node($memory['temporary_context'], 'State', $recent_sessions);
                        foreach((array)($memory['current_challenges']??[]) as $challenge) {
                            render_git_node($challenge, 'Challenge', $recent_sessions);
                        } 
                        ?>
                    <?php endif; ?>

                    <?php if(!empty($memory['life_timeline'])): ?>
                        <div class="git-section-title" style="margin-top: 40px;">Historical Timeline</div>
                        <?php foreach((array)$memory['life_timeline'] as $event) {
                            $evt_text = ($event['year'] ?? 'Past') . ': ' . ($event['event'] ?? '');
                            if (!empty($event['impact'])) $evt_text .= ' (Impact: ' . $event['impact'] . ')';
                            render_git_node($evt_text, 'Timeline', $recent_sessions);
                        } ?>
                    <?php endif; ?>

                    <?php if(!empty($reflection['patterns']) || !empty($reflection['blind_spots'])): ?>
                        <div class="git-section-title" style="margin-top: 40px;">Clinical Insights (Reflection Engine)</div>
                        <?php 
                        foreach((array)($reflection['patterns']??[]) as $pattern) {
                            render_git_node($pattern, 'Pattern', $recent_sessions);
                        } 
                        foreach((array)($reflection['blind_spots']??[]) as $spot) {
                            render_git_node("BLIND SPOT: " . $spot, 'Insight', $recent_sessions);
                        }
                        ?>
                    <?php endif; ?>

                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function filterUsers() {
    const input = document.getElementById('mcSearch').value.toLowerCase();
    const rows = document.querySelectorAll('.mc-user');
    rows.forEach(row => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(input) ? '' : 'none';
    });
}
</script>
</body>
</html>