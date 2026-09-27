<?php
/**
 * Kounselia Admin — Articles by professionals, and the community.
 *
 *   ?tab=queue      articles and edits waiting for an editor (default)
 *   ?tab=all        every professional's article, with its state
 *   ?tab=writers    who may publish: one setting per professional
 *   ?tab=community  comments to approve, reported, held by the safety check
 *   ?tab=settings   every switch for articles and the community
 *
 * Uses the Blog permission. Logic lives in includes/articles.php and
 * includes/community.php; this page is the controls.
 */
require_once __DIR__ . '/../inc/admin-auth.php';
require_once __DIR__ . '/../inc/admin-helpers.php';
$kounselia_admin_active = 'articles';

if ( ! kounselia_admin_can( 'blog' ) ) {
    wp_die( 'You do not have permission to manage articles.' );
}

global $wpdb;
$posts_table = $wpdb->prefix . 'kounselia_posts';
$pros_table  = $wpdb->prefix . 'kounselia_professionals';
$c_table     = $wpdb->prefix . 'kounselia_post_comments';
$settings    = kounselia_article_settings();
$tabs        = array( 'queue', 'all', 'writers', 'community', 'settings' );
$tab         = isset( $_GET['tab'] ) && in_array( $_GET['tab'], $tabs, true ) ? $_GET['tab'] : 'queue';
$pending_n   = kounselia_article_pending_count();
$attention_n = kounselia_community_attention_count();
$safety_n    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$c_table} WHERE status = 'pending' AND hidden_reason = 'safety'" );

function kounselia_admin_article_pill( $state ) {
    $colors = array( 'live' => 'green', 'live_pending' => 'gold', 'live_draft' => 'green', 'pending' => 'gold', 'changes' => 'rose', 'rejected' => 'rose', 'removed' => 'rose', 'scheduled' => 'blue', 'draft' => 'grey' );
    return '<span class="pill ' . esc_attr( isset( $colors[ $state ] ) ? $colors[ $state ] : 'grey' ) . '">' . esc_html( kounselia_article_state_label( $state ) ) . '</span>';
}

function kounselia_admin_pro_name( $professional_id ) {
    static $names = array();
    if ( ! isset( $names[ $professional_id ] ) ) {
        $pro = kounselia_get_professional_by_id( $professional_id );
        $names[ $professional_id ] = $pro ? $pro->display_name : 'Unknown professional';
    }
    return $names[ $professional_id ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kounselia Admin — Articles</title>
<meta name="robots" content="noindex, nofollow">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<?php require __DIR__ . '/../inc/admin-styles.php'; ?>
<?php require __DIR__ . '/../inc/admin-cms.php'; ?>
<style>
.q-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:18px;margin-bottom:16px;box-shadow:var(--sh-sm)}
.q-top{display:flex;gap:14px;align-items:flex-start}
.q-top img,.q-top .thumb{width:96px;height:72px;border-radius:10px;object-fit:cover;flex-shrink:0}
.q-title{font-family:'Cormorant Garamond',serif;font-size:24px;font-weight:500;line-height:1.2;color:var(--text)}
.q-sub{font-size:13.5px;color:var(--text2);margin-top:4px}
.q-meta{display:flex;gap:8px 14px;flex-wrap:wrap;font-size:12.5px;color:var(--text3);margin-top:8px;align-items:center}
.q-kind{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--gold);margin-bottom:4px}
.q-body{margin-top:14px;border-top:1px solid var(--border);padding-top:14px}
.q-body summary{cursor:pointer;font-size:13.5px;font-weight:500;color:var(--accent)}
.q-read{max-height:520px;overflow:auto;margin-top:12px;padding:16px 18px;background:var(--bg);border-radius:var(--r-md);font-size:15px;line-height:1.7;color:var(--text)}
.q-read h2{font-family:'Cormorant Garamond',serif;font-weight:500;font-size:24px;margin:1em 0 .3em}
.q-read h3{font-size:16px;margin:1em 0 .3em}
.q-read p,.q-read ul,.q-read ol,.q-read blockquote{margin:0 0 .9em}
.q-read ul,.q-read ol{padding-left:20px}
.q-read img,.q-read iframe{max-width:100%;height:auto;border-radius:10px}
.q-read blockquote{border-left:3px solid var(--gold);padding-left:14px;font-style:italic}
.q-was{font-size:12.5px;color:var(--text3);margin-top:8px}
.q-act{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;align-items:flex-start}
.q-act textarea{flex:1 1 260px;min-height:42px;padding:9px 12px;border:1px solid var(--border);border-radius:var(--r-sm);font-family:inherit;font-size:13px;resize:vertical}
.q-act .btn-row{flex:1 1 auto}
@media (max-width:640px){.q-top img,.q-top .thumb{display:none}.q-act .btn{flex:1 1 auto}.q-title{font-size:21px}}
.w-row{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(0,1fr) 110px 90px;gap:12px;align-items:center;padding:14px 0;border-bottom:1px solid var(--border)}
.w-row:last-child{border-bottom:none}
.w-name{font-weight:600;font-size:14px}
.w-name small{display:block;font-weight:400;color:var(--text3);font-size:12px}
.w-row select,.w-row input[type=text]{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:var(--r-sm);font-family:inherit;font-size:13px;background:var(--surface)}
.w-stat{font-size:12.5px;color:var(--text2)}
@media (max-width:760px){.w-row{grid-template-columns:1fr 1fr}.w-row .w-name{grid-column:1 / -1}}
.c-card{border:1px solid var(--border);border-radius:var(--r-md);padding:14px 16px;margin-bottom:12px;background:var(--surface)}
.c-head{display:flex;gap:8px;flex-wrap:wrap;align-items:center;font-size:12.5px;color:var(--text3)}
.c-head b{color:var(--text);font-size:13.5px}
.c-text{font-size:14px;line-height:1.55;margin:8px 0;white-space:pre-wrap;word-break:break-word;color:var(--text)}
.c-on{font-size:12.5px;color:var(--text2)}
.c-reports{font-size:12.5px;color:var(--rose);background:var(--rose-light);border-radius:8px;padding:6px 10px;margin-top:6px}
.set-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start}
@media (max-width:900px){.set-grid{grid-template-columns:1fr}}
.set-grid .panel h2{font-family:'Cormorant Garamond',serif;font-weight:500;font-size:22px;color:var(--accent);margin-bottom:14px}
</style>
</head>
<body>
<?php require __DIR__ . '/../inc/admin-nav.php'; ?>

<div class="admin-body">
  <div class="cms-head">
    <div>
      <h1 class="admin-title">Articles &amp; community</h1>
      <div class="admin-subtitle">Articles written by verified professionals for <a href="/blog/" target="_blank"><?php echo esc_html( kounselia_blog_settings()['title'] ); ?></a>, and the conversation around them.<?php echo empty( $settings['enabled'] ) ? ' <span class="pill rose">Publishing is switched off</span>' : ''; ?></div>
    </div>
  </div>

  <div class="cms-tabs">
    <a href="?tab=queue" class="<?php echo 'queue' === $tab ? 'active' : ''; ?>">Review queue <span class="count"><?php echo (int) $pending_n; ?></span></a>
    <a href="?tab=all" class="<?php echo 'all' === $tab ? 'active' : ''; ?>">All articles</a>
    <a href="?tab=writers" class="<?php echo 'writers' === $tab ? 'active' : ''; ?>">Writers</a>
    <a href="?tab=community" class="<?php echo 'community' === $tab ? 'active' : ''; ?>">Community <span class="count"><?php echo (int) ( $attention_n + $safety_n ); ?></span></a>
    <a href="?tab=settings" class="<?php echo 'settings' === $tab ? 'active' : ''; ?>"><i class="ti ti-settings"></i> Settings</a>
  </div>

<?php if ( 'queue' === $tab ) :
    $queue = $wpdb->get_results( "SELECT * FROM {$posts_table} WHERE author_type = 'professional' AND review_status = 'pending' ORDER BY submitted_at ASC" );
    ?>
  <?php if ( ! $queue ) : ?>
    <div class="panel"><div class="empty-state"><i class="ti ti-circle-check" style="font-size:22px;color:var(--sage)"></i><br>Nothing waiting. New articles and edits from professionals appear here.</div></div>
  <?php endif; ?>
  <?php foreach ( $queue as $p ) :
      $fields  = kounselia_article_editable_fields( $p );
      $is_edit = kounselia_blog_post_is_live( $p );
      $words   = kounselia_article_word_count( $fields['content'] );
      ?>
    <div class="q-card" id="q-<?php echo (int) $p->id; ?>">
      <div class="q-top">
        <?php if ( $fields['cover_image'] ) : ?><img src="<?php echo esc_url( $fields['cover_image'] ); ?>" alt=""><?php else : ?><span class="thumb"></span><?php endif; ?>
        <div style="min-width:0">
          <div class="q-kind"><?php echo $is_edit ? 'Edit to a live article' : 'New article'; ?></div>
          <div class="q-title"><?php echo esc_html( $fields['title'] ); ?></div>
          <?php if ( $fields['subtitle'] ) : ?><div class="q-sub"><?php echo esc_html( $fields['subtitle'] ); ?></div><?php endif; ?>
          <div class="q-meta">
            <span><i class="ti ti-user"></i> <?php echo esc_html( kounselia_admin_pro_name( $p->professional_id ) ); ?></span>
            <span><?php echo esc_html( number_format_i18n( $words ) ); ?> words</span>
            <?php if ( $fields['tags'] ) : ?><span><?php echo esc_html( $fields['tags'] ); ?></span><?php endif; ?>
            <span>Sent <?php echo esc_html( kounselia_admin_time_label( get_gmt_from_date( $p->submitted_at ) ) ); ?></span>
            <?php if ( ! $fields['allow_comments'] ) : ?><span class="pill grey">Comments off</span><?php endif; ?>
          </div>
          <?php if ( $is_edit && $fields['title'] !== $p->title ) : ?><div class="q-was">Live title: “<?php echo esc_html( $p->title ); ?>”</div><?php endif; ?>
        </div>
      </div>
      <div class="q-body">
        <details <?php echo count( $queue ) === 1 ? 'open' : ''; ?>>
          <summary>Read the <?php echo $is_edit ? 'new version' : 'article'; ?></summary>
          <?php if ( $fields['excerpt'] ) : ?><p class="q-sub" style="margin-top:10px"><b>Summary:</b> <?php echo esc_html( $fields['excerpt'] ); ?></p><?php endif; ?>
          <div class="q-read"><?php echo $fields['content']; // Cleaned by kounselia_article_clean_html() when saved. ?></div>
          <?php if ( $is_edit ) : ?><p class="q-was"><a href="<?php echo esc_url( kounselia_blog_url( $p->slug ) ); ?>" target="_blank">Open the live version</a> to compare.</p><?php endif; ?>
        </details>
      </div>
      <div class="q-act">
        <textarea id="note-<?php echo (int) $p->id; ?>" placeholder="Note to the professional (needed for changes or not approving; optional when approving)"></textarea>
        <div class="btn-row">
          <button class="btn btn-primary btn-sm" onclick="review(<?php echo (int) $p->id; ?>,'approve')"><i class="ti ti-check"></i> Approve<?php echo $is_edit ? ' changes' : ' &amp; publish'; ?></button>
          <button class="btn btn-light btn-sm" onclick="review(<?php echo (int) $p->id; ?>,'changes')"><i class="ti ti-message-dots"></i> Ask for changes</button>
          <button class="btn btn-danger btn-sm" onclick="review(<?php echo (int) $p->id; ?>,'reject')"><i class="ti ti-x"></i> Don't approve</button>
        </div>
      </div>
    </div>
  <?php endforeach; ?>

<?php elseif ( 'all' === $tab ) :
    $filter = isset( $_GET['state'] ) ? sanitize_key( $_GET['state'] ) : '';
    $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
    $sql    = "SELECT * FROM {$posts_table} WHERE author_type = 'professional'";
    $params = array();
    if ( '' !== $search ) {
        $sql     .= ' AND title LIKE %s';
        $params[] = '%' . $wpdb->esc_like( $search ) . '%';
    }
    $sql  .= ' ORDER BY COALESCE(published_at, updated_at) DESC LIMIT 300';
    $rows  = $wpdb->get_results( $params ? $wpdb->prepare( $sql, $params ) : $sql );
    $items = array();
    foreach ( $rows as $r ) {
        $state = kounselia_article_state( $r );
        if ( ! $filter || $state === $filter || ( 'live' === $filter && 0 === strpos( $state, 'live' ) ) ) {
            $items[] = array( $r, $state );
        }
    }
    ?>
  <form class="filters" method="get">
    <input type="hidden" name="tab" value="all">
    <select name="state" onchange="this.form.submit()">
      <?php foreach ( array( '' => 'Every state', 'live' => 'Live', 'pending' => 'Waiting for review', 'changes' => 'Changes requested', 'rejected' => 'Not approved', 'removed' => 'Taken down', 'draft' => 'Drafts' ) as $k => $label ) : ?>
        <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $filter, $k ); ?>><?php echo esc_html( $label ); ?></option>
      <?php endforeach; ?>
    </select>
    <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search titles">
    <button type="submit">Search</button>
  </form>
  <div class="panel">
    <?php if ( ! $items ) : ?>
      <div class="empty-state">No articles here yet.</div>
    <?php else : ?>
      <table class="admin-table">
        <thead><tr><th>Article</th><th>State</th><th>Reads</th><th>Loves</th><th>Comments</th><th></th></tr></thead>
        <tbody>
        <?php foreach ( $items as $it ) : list( $r, $state ) = $it; ?>
          <tr id="row-<?php echo (int) $r->id; ?>">
            <td data-label="Article"><div class="row-title"><?php echo esc_html( $r->title ); ?></div><div class="row-sub"><?php echo esc_html( kounselia_admin_pro_name( $r->professional_id ) ); ?> · <?php echo esc_html( mysql2date( 'M j, Y', $r->published_at ? $r->published_at : $r->updated_at ) ); ?></div></td>
            <td data-label="State"><?php echo kounselia_admin_article_pill( $state ); ?></td>
            <td data-label="Reads"><?php echo esc_html( number_format_i18n( $r->views ) ); ?></td>
            <td data-label="Loves"><?php echo esc_html( number_format_i18n( $r->love_count ) ); ?></td>
            <td data-label="Comments"><?php echo esc_html( number_format_i18n( $r->comment_count ) ); ?></td>
            <td data-label=""><div class="row-actions">
              <a class="btn btn-light btn-sm" href="<?php echo esc_url( kounselia_blog_url( $r->slug ) ); ?>" target="_blank" title="View"><i class="ti ti-eye"></i></a>
              <a class="btn btn-light btn-sm" href="/portal/admin/pages/blog.php?edit=<?php echo (int) $r->id; ?>" title="Edit in the Blog editor"><i class="ti ti-pencil"></i></a>
              <?php if ( 0 === strpos( $state, 'live' ) ) : ?>
                <button class="btn btn-danger btn-sm" onclick="takeDown(<?php echo (int) $r->id; ?>)"><i class="ti ti-eye-off"></i> Take down</button>
              <?php elseif ( in_array( $state, array( 'removed', 'rejected', 'changes', 'draft' ), true ) ) : ?>
                <button class="btn btn-light btn-sm" onclick="review(<?php echo (int) $r->id; ?>,'approve')"><i class="ti ti-check"></i> Publish</button>
              <?php endif; ?>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

<?php elseif ( 'writers' === $tab ) :
    $pros = $wpdb->get_results(
        "SELECT p.*, u.display_name,
                (SELECT COUNT(*) FROM {$posts_table} a WHERE a.professional_id = p.id AND a.status = 'published') AS live_count,
                (SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_follows f WHERE f.professional_id = p.id) AS followers
         FROM {$pros_table} p INNER JOIN {$wpdb->users} u ON u.ID = p.user_id
         WHERE p.status = 'verified' ORDER BY u.display_name ASC"
    );
    $options = kounselia_article_publishing_options();
    $default = 'chosen' === $settings['who'] ? 'Can\'t publish (by invitation only)' : ( 'trusted' === $settings['default_mode'] ? 'Trusted' : 'Needs review' );
    ?>
  <div class="panel">
    <p class="hint" style="margin-bottom:10px;font-size:13px">Right now, a professional on “Follow the site setting” is: <b><?php echo esc_html( $default ); ?></b>. Change that in <a href="?tab=settings">Settings</a>. The private note is only for your team.</p>
    <?php if ( ! $pros ) : ?>
      <div class="empty-state">No verified professionals yet.</div>
    <?php endif; ?>
    <?php foreach ( $pros as $pro ) : ?>
      <div class="w-row" data-pro="<?php echo (int) $pro->id; ?>">
        <div class="w-name"><?php echo esc_html( $pro->display_name ); ?><small><?php echo esc_html( $pro->title ); ?></small></div>
        <div>
          <select class="w-set" aria-label="Publishing for <?php echo esc_attr( $pro->display_name ); ?>">
            <?php foreach ( $options as $k => $label ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $pro->publishing ? $pro->publishing : 'default', $k ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
          </select>
          <input type="text" class="w-note" style="margin-top:6px" placeholder="Private note (optional)" value="<?php echo esc_attr( (string) $pro->publishing_note ); ?>">
        </div>
        <div class="w-stat"><?php echo (int) $pro->live_count; ?> live<br><?php echo (int) $pro->followers; ?> followers</div>
        <div><button class="btn btn-primary btn-sm w-save" type="button">Save</button></div>
      </div>
    <?php endforeach; ?>
  </div>

<?php elseif ( 'community' === $tab ) :
    $status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'attention';
    if ( 'safety' === $status ) {
        $where = "c.status = 'pending' AND c.hidden_reason = 'safety'";
    } elseif ( 'hidden' === $status ) {
        $where = "c.status = 'hidden'";
    } elseif ( 'recent' === $status ) {
        $where = "c.status = 'visible'";
    } else {
        $status = 'attention';
        $where  = "(c.status = 'pending' AND c.hidden_reason IN ('approval','reports')) OR (c.status = 'visible' AND EXISTS (SELECT 1 FROM {$wpdb->prefix}kounselia_comment_reports r WHERE r.comment_id = c.id AND r.status = 'open'))";
    }
    $comments = $wpdb->get_results( "SELECT c.*, p.title AS post_title, p.slug AS post_slug FROM {$c_table} c INNER JOIN {$posts_table} p ON p.id = c.post_id WHERE {$where} ORDER BY c.created_at DESC LIMIT 100" );
    $reasons  = kounselia_comment_report_reasons();
    ?>
  <div class="cms-tabs" style="margin-top:-10px">
    <a href="?tab=community&amp;status=attention" class="<?php echo 'attention' === $status ? 'active' : ''; ?>">Needs a look <span class="count"><?php echo (int) $attention_n; ?></span></a>
    <a href="?tab=community&amp;status=safety" class="<?php echo 'safety' === $status ? 'active' : ''; ?>"><i class="ti ti-shield-heart"></i> Held by the safety check <span class="count"><?php echo (int) $safety_n; ?></span></a>
    <a href="?tab=community&amp;status=hidden" class="<?php echo 'hidden' === $status ? 'active' : ''; ?>">Hidden</a>
    <a href="?tab=community&amp;status=recent" class="<?php echo 'recent' === $status ? 'active' : ''; ?>">Recent</a>
  </div>
  <?php if ( 'safety' === $status ) : ?>
    <p class="hint" style="font-size:13px;margin-bottom:14px">These comments used words linked to crisis or self-harm. Nobody else can see them. Each one is also on the <a href="/portal/admin/pages/safety-flags.php">Safety page</a>, where your care team can follow up with the person. Publishing one is rarely right.</p>
  <?php endif; ?>
  <?php if ( ! $comments ) : ?>
    <div class="panel"><div class="empty-state">Nothing here.</div></div>
  <?php endif; ?>
  <?php foreach ( $comments as $c ) :
      $writer  = get_userdata( $c->user_id );
      $label   = kounselia_community_identity( $c->user_id );
      $banned  = get_user_meta( $c->user_id, 'kounselia_community_banned', true );
      $reports = $wpdb->get_results( $wpdb->prepare( "SELECT reason, note FROM {$wpdb->prefix}kounselia_comment_reports WHERE comment_id = %d AND status = 'open'", $c->id ) );
      ?>
    <div class="c-card" id="c-<?php echo (int) $c->id; ?>">
      <div class="c-head">
        <b><?php echo esc_html( $c->is_author ? kounselia_admin_pro_name( kounselia_get_blog_post( $c->post_id )->professional_id ) . ' (author)' : $label['name'] ); ?></b>
        <span title="Only staff see this"><?php echo esc_html( $writer ? $writer->user_email : 'deleted account' ); ?></span>
        <span>· <?php echo esc_html( kounselia_admin_time_label( get_gmt_from_date( $c->created_at ) ) ); ?></span>
        <?php if ( 'pending' === $c->status ) : ?><span class="pill gold"><?php echo esc_html( array( 'safety' => 'Held: safety check', 'approval' => 'Waiting for approval', 'reports' => 'Hidden by reports' )[ $c->hidden_reason ] ?? 'Waiting' ); ?></span><?php endif; ?>
        <?php if ( 'hidden' === $c->status ) : ?><span class="pill grey">Hidden by <?php echo 'author' === $c->hidden_reason ? 'the author' : 'a moderator'; ?></span><?php endif; ?>
        <?php if ( $c->flag_reason ) : ?><span class="pill rose">Matched “<?php echo esc_html( $c->flag_reason ); ?>”</span><?php endif; ?>
        <?php if ( $banned ) : ?><span class="pill rose">Can't comment</span><?php endif; ?>
      </div>
      <div class="c-text"><?php echo esc_html( $c->content ); ?></div>
      <div class="c-on">On <a href="<?php echo esc_url( kounselia_blog_url( $c->post_slug ) ); ?>#comments" target="_blank"><?php echo esc_html( $c->post_title ); ?></a><?php echo $c->parent_id ? ' (a reply)' : ''; ?></div>
      <?php if ( $reports ) : ?>
        <div class="c-reports"><i class="ti ti-flag"></i> <?php echo count( $reports ); ?> report<?php echo 1 === count( $reports ) ? '' : 's'; ?>: <?php echo esc_html( implode( '; ', array_map( function ( $r ) use ( $reasons ) { return ( $reasons[ $r->reason ] ?? $r->reason ) . ( $r->note ? ' (“' . $r->note . '”)' : '' ); }, $reports ) ) ); ?></div>
      <?php endif; ?>
      <div class="btn-row" style="margin-top:10px">
        <?php if ( 'visible' !== $c->status ) : ?><button class="btn btn-primary btn-sm" onclick="moderate(<?php echo (int) $c->id; ?>,'approve'<?php echo 'safety' === $c->hidden_reason ? ",'This comment was held by the safety check. Make it public anyway?'" : ''; ?>)"><i class="ti ti-check"></i> Show it</button><?php endif; ?>
        <?php if ( 'visible' === $c->status ) : ?><button class="btn btn-light btn-sm" onclick="moderate(<?php echo (int) $c->id; ?>,'hide')"><i class="ti ti-eye-off"></i> Hide</button><?php endif; ?>
        <?php if ( $reports && 'visible' === $c->status ) : ?><button class="btn btn-light btn-sm" onclick="moderate(<?php echo (int) $c->id; ?>,'dismiss')"><i class="ti ti-flag-off"></i> Dismiss reports</button><?php endif; ?>
        <button class="btn btn-danger btn-sm" onclick="moderate(<?php echo (int) $c->id; ?>,'delete','Delete this comment for good?')"><i class="ti ti-trash"></i> Delete</button>
        <?php if ( ! $c->is_author ) : ?><button class="btn btn-light btn-sm" onclick="ban(<?php echo (int) $c->user_id; ?>,<?php echo $banned ? 0 : 1; ?>)"><i class="ti ti-user-<?php echo $banned ? 'check' : 'off'; ?>"></i> <?php echo $banned ? 'Let them comment again' : 'Stop this member commenting'; ?></button><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>

<?php else : // settings
    $s = $settings;
    $check = function ( $key, $title, $help = '' ) use ( $s ) {
        echo '<label class="check"><input type="checkbox" data-key="' . esc_attr( $key ) . '" ' . checked( ! empty( $s[ $key ] ), true, false ) . '> <span><b>' . esc_html( $title ) . '</b>' . ( $help ? '<small>' . esc_html( $help ) . '</small>' : '' ) . '</span></label>';
    };
    $number = function ( $key, $label, $min, $max, $help = '' ) use ( $s ) {
        echo '<div class="field"><label>' . esc_html( $label ) . '</label><input type="number" data-key="' . esc_attr( $key ) . '" min="' . (int) $min . '" max="' . (int) $max . '" value="' . (int) $s[ $key ] . '">' . ( $help ? '<div class="hint">' . esc_html( $help ) . '</div>' : '' ) . '</div>';
    };
    ?>
  <div class="set-grid">
    <div class="panel">
      <h2>Publishing</h2>
      <?php $check( 'enabled', 'Professionals can write articles', 'Switching this off stops all writing and sending. Articles already live stay live.' ); ?>
      <div class="field"><label>Who can write</label>
        <select data-key="who">
          <option value="all" <?php selected( $s['who'], 'all' ); ?>>Every verified professional</option>
          <option value="chosen" <?php selected( $s['who'], 'chosen' ); ?>>Only professionals I choose (on the Writers tab)</option>
        </select>
      </div>
      <div class="field"><label>For professionals on “Follow the site setting”</label>
        <select data-key="default_mode">
          <option value="review" <?php selected( $s['default_mode'], 'review' ); ?>>An editor reviews every article first</option>
          <option value="trusted" <?php selected( $s['default_mode'], 'trusted' ); ?>>Articles go live straight away</option>
        </select>
      </div>
      <div class="field-row">
        <?php $number( 'min_words', 'Shortest article (words)', 0, 5000 ); ?>
        <?php $number( 'max_words', 'Longest article (words)', 100, 30000 ); ?>
      </div>
      <div class="field-row">
        <?php $number( 'weekly_limit', 'New articles per person per week', 0, 50, '0 means no limit.' ); ?>
        <?php $number( 'max_tags', 'Topics per article', 1, 8 ); ?>
      </div>
      <?php $check( 'allow_images', 'Images inside articles' ); ?>
      <?php $check( 'allow_embeds', 'Videos and audio (YouTube, Vimeo, Spotify, SoundCloud)' ); ?>
      <?php $check( 'notify_editors', 'Email editors when something is waiting', 'At most once an hour.' ); ?>
      <?php $check( 'show_book_button', 'Show “Book a session” on professionals’ articles' ); ?>
      <?php $check( 'disclaimer_enabled', 'Add a care note to the end of every professional’s article' ); ?>
      <div class="field"><label>The care note</label><textarea data-key="disclaimer_text" maxlength="600"><?php echo esc_textarea( $s['disclaimer_text'] ); ?></textarea><div class="hint">A link to your safety resources is added after it.</div></div>
      <div class="field"><label>Name of the filter on the Journal</label><input type="text" data-key="filter_label" maxlength="40" value="<?php echo esc_attr( $s['filter_label'] ); ?>"></div>
    </div>

    <div class="panel">
      <h2>Community</h2>
      <?php $check( 'follows_enabled', 'Members can follow professionals' ); ?>
      <?php $check( 'notify_followers', 'Tell followers when someone they follow publishes', 'In the app, in their notifications, and by push.' ); ?>
      <?php $check( 'notify_followers_email', 'Also email followers', 'Members can turn these emails off for themselves.' ); ?>
      <?php $check( 'loves_enabled', 'Loves on articles and comments' ); ?>
      <?php $check( 'comments_enabled', 'Comments on articles', 'Professionals can also turn comments off on each article.' ); ?>
      <?php $check( 'comments_on_staff', 'Loves and comments on the team’s own posts too' ); ?>
      <div class="field"><label>When someone comments</label>
        <select data-key="comment_moderation">
          <option value="open" <?php selected( $s['comment_moderation'], 'open' ); ?>>Show it straight away (after the safety check)</option>
          <option value="approve_first" <?php selected( $s['comment_moderation'], 'approve_first' ); ?>>Hold it until a moderator approves it</option>
        </select>
        <div class="hint">Every comment goes through the same crisis check as the chat, whichever you choose.</div>
      </div>
      <?php $check( 'authors_can_moderate', 'Professionals can pin and hide comments on their own articles' ); ?>
      <div class="field-row">
        <?php $number( 'comment_max_length', 'Longest comment (characters)', 100, 5000 ); ?>
        <?php $number( 'comments_per_hour', 'Comments per person per hour', 1, 200 ); ?>
      </div>
      <?php $number( 'report_threshold', 'Reports that hide a comment until reviewed', 1, 50 ); ?>
    </div>
  </div>
  <div style="margin-top:18px"><button class="btn btn-primary" id="s-save"><i class="ti ti-device-floppy"></i> Save settings</button></div>
<?php endif; ?>
</div>

<script>
function review(id, decision){
  var note = document.getElementById('note-' + id);
  note = note ? note.value : '';
  if ((decision === 'changes' || decision === 'reject') && !note.trim()) { KAdmin.toast('Please write a short note so they know what to change.', true); if (document.getElementById('note-' + id)) document.getElementById('note-' + id).focus(); return; }
  if (decision === 'approve' && !confirm('Publish this now? Followers will be told about new articles.')) return;
  KAdmin.post('kounselia_admin_article_review', { id: id, decision: decision, note: note })
    .then(function(d){ KAdmin.toast(d.message); setTimeout(function(){ location.reload(); }, 700); })
    .catch(function(e){ KAdmin.toast(e.message, true); });
}
function takeDown(id){
  var note = prompt('Take this article down? Write a short note for the professional (they will see it):');
  if (note === null) return;
  if (!note.trim()) { KAdmin.toast('A note is needed so they know why.', true); return; }
  KAdmin.post('kounselia_admin_article_review', { id: id, decision: 'remove', note: note })
    .then(function(d){ KAdmin.toast(d.message); setTimeout(function(){ location.reload(); }, 700); })
    .catch(function(e){ KAdmin.toast(e.message, true); });
}
function moderate(id, act, question){
  if (question && !confirm(question)) return;
  KAdmin.post('kounselia_admin_comment_moderate', { comment_id: id, act: act })
    .then(function(){ var el = document.getElementById('c-' + id); if (el) el.remove(); KAdmin.toast('Done.'); })
    .catch(function(e){ KAdmin.toast(e.message, true); });
}
function ban(userId, on){
  if (on && !confirm('Stop this member from commenting anywhere? Their existing comments stay unless you delete them.')) return;
  KAdmin.post('kounselia_admin_community_ban', { user_id: userId, ban: on })
    .then(function(d){ KAdmin.toast(d.message); setTimeout(function(){ location.reload(); }, 700); })
    .catch(function(e){ KAdmin.toast(e.message, true); });
}
document.querySelectorAll('.w-row').forEach(function(row){
  row.querySelector('.w-save').addEventListener('click', function(){
    var setting = row.querySelector('.w-set').value, takeDown = 0;
    if (setting === 'blocked') {
      if (!confirm('Block this professional from publishing?')) return;
      takeDown = confirm('Also take down the articles they already have live?\n\nOK = take them down. Cancel = leave them up.') ? 1 : 0;
    }
    KAdmin.post('kounselia_admin_set_pro_publishing', { professional_id: row.dataset.pro, publishing: setting, note: row.querySelector('.w-note').value, take_down: takeDown })
      .then(function(d){ KAdmin.toast(d.message); })
      .catch(function(e){ KAdmin.toast(e.message, true); });
  });
});
var saveBtn = document.getElementById('s-save');
if (saveBtn) saveBtn.addEventListener('click', function(){
  var out = {};
  document.querySelectorAll('[data-key]').forEach(function(el){ out[el.dataset.key] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value; });
  KAdmin.post('kounselia_admin_save_article_settings', { settings: JSON.stringify(out) })
    .then(function(d){ KAdmin.toast(d.message); })
    .catch(function(e){ KAdmin.toast(e.message, true); });
});
</script>
</body>
</html>
