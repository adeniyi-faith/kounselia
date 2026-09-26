<?php
/**
 * The blog ("The Kounselia Journal").
 *
 *   /blog/                 latest posts (+ ?q=search, ?page=2)
 *   /blog/<slug>           one post
 *   /blog/tag/<tag>        posts on one topic
 *   /blog/feed             RSS feed
 *
 * Posts are written in Admin → Blog. Drafts and scheduled posts are
 * only visible to staff, who see a banner saying so.
 */
require dirname( __DIR__ ) . '/inc/kounselia-boot.php';

$kounselia_segments = kounselia_request_segments( 'blog' );
$kounselia_settings = kounselia_blog_settings();
$kounselia_is_staff = function_exists( 'kounselia_admin_can' ) && kounselia_admin_can( 'blog' );
$kounselia_nav_current = 'blog';

if ( isset( $kounselia_segments[0] ) && in_array( $kounselia_segments[0], array( 'feed', 'rss' ), true ) ) {
    kounselia_blog_render_feed();
    exit;
}

/* -------------------------------------------------------------------------
 * Shared bits
 * ---------------------------------------------------------------------- */

function kounselia_blog_av( $author ) {
    return '<span class="k-av">' . ( $author['avatar'] ? '<img src="' . esc_url( $author['avatar'] ) . '" alt="">' : esc_html( $author['initial'] ) ) . '</span>';
}

function kounselia_blog_date( $post ) {
    $ts = strtotime( $post->published_at ? $post->published_at : $post->created_at );
    return date_i18n( date( 'Y', $ts ) === date( 'Y' ) ? 'M j' : 'M j, Y', $ts );
}

function kounselia_blog_feed_item( $post ) {
    $author = kounselia_blog_author( $post );
    $tags   = kounselia_blog_post_tags( $post );
    ?>
    <a class="k-feed-item<?php echo $post->cover_image ? '' : ' no-thumb'; ?>" href="<?php echo esc_url( kounselia_blog_url( $post->slug ) ); ?>">
      <div>
        <div class="k-meta"><?php echo kounselia_blog_av( $author ); ?><b><?php echo esc_html( $author['name'] ); ?></b></div>
        <h3><?php echo esc_html( $post->title ); ?></h3>
        <p><?php echo esc_html( kounselia_blog_summary( $post, 30 ) ); ?></p>
        <div class="k-feed-foot">
          <span><?php echo esc_html( kounselia_blog_date( $post ) ); ?></span>
          <span class="dot"></span>
          <span><?php echo (int) $post->reading_minutes; ?> min read</span>
          <?php if ( $tags ) : ?><span class="k-tag-chip"><?php echo esc_html( $tags[0]['name'] ); ?></span><?php endif; ?>
        </div>
      </div>
      <?php if ( $post->cover_image ) : ?>
        <div class="k-feed-thumb"><img src="<?php echo esc_url( $post->cover_image ); ?>" alt="" loading="lazy"></div>
      <?php endif; ?>
    </a>
    <?php
}

function kounselia_blog_sidebar( $settings, $current_tag = '' ) {
    $popular = kounselia_blog_query( array( 'per_page' => 5, 'orderby' => 'views' ) );
    $tags    = array_slice( kounselia_blog_all_tags(), 0, 14 );
    ?>
    <aside class="k-sidebar">
      <?php if ( $popular['items'] ) : ?>
        <div>
          <div class="k-side-title">Most read</div>
          <ol class="k-popular">
            <?php foreach ( $popular['items'] as $p ) : $a = kounselia_blog_author( $p ); ?>
              <li><div>
                <a href="<?php echo esc_url( kounselia_blog_url( $p->slug ) ); ?>"><?php echo esc_html( $p->title ); ?></a>
                <div class="k-meta"><?php echo esc_html( $a['name'] ); ?> <span class="dot"></span> <?php echo (int) $p->reading_minutes; ?> min read</div>
              </div></li>
            <?php endforeach; ?>
          </ol>
        </div>
      <?php endif; ?>

      <?php if ( $tags ) : ?>
        <div>
          <div class="k-side-title">Explore topics</div>
          <div class="k-side-topics">
            <?php foreach ( $tags as $t ) : ?>
              <a class="k-pill<?php echo $current_tag === $t['slug'] ? ' active' : ''; ?>" href="<?php echo esc_url( kounselia_blog_tag_url( $t['slug'] ) ); ?>"><?php echo esc_html( $t['name'] ); ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="k-side-card">
        <h4>Get new stories in your inbox</h4>
        <p>One thoughtful email when we publish something new. Unsubscribe any time.</p>
        <?php echo kounselia_newsletter_form_html( 'blog', 'Subscribe' ); ?>
      </div>

      <div class="k-side-links">
        <a href="/blog/feed"><i class="ti ti-rss"></i> RSS</a>
        <a href="<?php echo esc_url( kounselia_page_url( 'our-mission' ) ); ?>"><i class="ti ti-heart"></i> About Kounselia</a>
      </div>
    </aside>
    <?php
}

/* -------------------------------------------------------------------------
 * Single post
 * ---------------------------------------------------------------------- */

$kounselia_is_listing = empty( $kounselia_segments ) || 'tag' === $kounselia_segments[0];

if ( ! $kounselia_is_listing ) {
    $slug = $kounselia_segments[0];
    $post = kounselia_get_blog_post_by_slug( $slug, ! $kounselia_is_staff );
    if ( ! $post ) {
        $moved = kounselia_blog_slug_redirect( $slug );
        if ( $moved ) {
            wp_safe_redirect( kounselia_blog_url( $moved ), 301 );
            exit;
        }
        kounselia_public_not_found( 'story' );
    }

    $live = kounselia_blog_post_is_live( $post );
    if ( $live && ! $kounselia_is_staff && ! preg_match( '/bot|crawl|spider|slurp|preview|facebookexternalhit/i', isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '' ) ) {
        kounselia_blog_count_view( $post->id );
    }

    $author   = kounselia_blog_author( $post );
    $tags     = kounselia_blog_post_tags( $post );
    $url      = kounselia_blog_url( $post->slug, true );
    $summary  = $post->meta_description ? $post->meta_description : kounselia_blog_summary( $post, 30 );
    $related  = $live ? kounselia_blog_related( $post, 3 ) : array();
    $adjacent = $live ? kounselia_blog_adjacent( $post ) : array( 'older' => null, 'newer' => null );
    $iso      = mysql2date( 'c', $post->published_at ? $post->published_at : $post->created_at, false );

    $jsonld = array(
        '@context'         => 'https://schema.org',
        '@type'            => 'BlogPosting',
        'headline'         => $post->title,
        'description'      => $summary,
        'datePublished'    => $iso,
        'dateModified'     => mysql2date( 'c', $post->updated_at, false ),
        'author'           => array( '@type' => 'Person', 'name' => $author['name'] ),
        'publisher'        => array( '@type' => 'Organization', 'name' => 'Kounselia', 'logo' => array( '@type' => 'ImageObject', 'url' => 'https://kounselia.com/img/Kounselia_Logo_IconMark_MidnightNavy.png' ) ),
        'mainEntityOfPage' => $url,
    );
    if ( $post->cover_image ) {
        $jsonld['image'] = $post->cover_image;
    }

    kounselia_public_head( array(
        'title'       => $post->title . ' — ' . $kounselia_settings['title'],
        'description' => $summary,
        'url'         => $url,
        'image'       => $post->cover_image ? $post->cover_image : 'https://kounselia.com/img/Kounselia_Banner_02_16_9.png',
        'type'        => 'article',
        'noindex'     => ! $live,
        'extra'       => '<meta property="article:published_time" content="' . esc_attr( $iso ) . '">'
            . '<script type="application/ld+json">' . wp_json_encode( $jsonld, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ) . '</script>',
    ) );
    $share_text = rawurlencode( $post->title );
    $share_url  = rawurlencode( $url );
    ?>
<body class="k-site">
<div class="k-progress" id="k-progress"></div>
<?php if ( $kounselia_is_staff ) : ?>
  <div class="k-draft-bar"<?php echo $live ? ' style="background:var(--accent)"' : ''; ?>>
    <?php
    if ( $live ) {
        echo 'Published · ' . number_format_i18n( $post->views ) . ' views.';
    } elseif ( 'published' === $post->status ) {
        echo 'Scheduled for ' . esc_html( mysql2date( 'M j, Y g:ia', $post->published_at ) ) . ' — only staff can see it until then.';
    } else {
        echo 'Draft preview — only staff can see this post.';
    }
    ?>
    <a href="/portal/admin/pages/blog.php?edit=<?php echo (int) $post->id; ?>">Edit post</a>
  </div>
<?php endif; ?>
<?php require dirname( __DIR__ ) . '/inc/kounselia-site-nav.php'; ?>

<main>
  <article class="k-article">
    <header class="k-article-head">
      <?php if ( $tags ) : ?>
        <a class="k-article-tag" href="<?php echo esc_url( kounselia_blog_tag_url( $tags[0]['slug'] ) ); ?>"><?php echo esc_html( $tags[0]['name'] ); ?></a>
      <?php endif; ?>
      <h1 class="k-article-title"><?php echo esc_html( $post->title ); ?></h1>
      <?php if ( $post->subtitle ) : ?>
        <p class="k-article-sub"><?php echo esc_html( $post->subtitle ); ?></p>
      <?php endif; ?>
      <div class="k-byline">
        <div class="k-byline-who">
          <?php echo kounselia_blog_av( $author ); ?>
          <div>
            <div class="k-byline-name"><?php echo esc_html( $author['name'] ); ?></div>
            <div class="k-byline-meta"><?php echo (int) $post->reading_minutes; ?> min read · <?php echo esc_html( date_i18n( 'M j, Y', strtotime( $post->published_at ? $post->published_at : $post->created_at ) ) ); ?></div>
          </div>
        </div>
        <div class="k-share">
          <a href="https://twitter.com/intent/tweet?text=<?php echo $share_text; ?>&url=<?php echo $share_url; ?>" target="_blank" rel="noopener" aria-label="Share on X"><i class="ti ti-brand-x"></i></a>
          <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?php echo $share_url; ?>" target="_blank" rel="noopener" aria-label="Share on LinkedIn"><i class="ti ti-brand-linkedin"></i></a>
          <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo $share_url; ?>" target="_blank" rel="noopener" aria-label="Share on Facebook"><i class="ti ti-brand-facebook"></i></a>
          <a href="https://wa.me/?text=<?php echo $share_text . '%20' . $share_url; ?>" target="_blank" rel="noopener" aria-label="Share on WhatsApp"><i class="ti ti-brand-whatsapp"></i></a>
          <button type="button" class="k-copy-link" data-url="<?php echo esc_attr( $url ); ?>" aria-label="Copy link"><i class="ti ti-link"></i></button>
        </div>
      </div>
    </header>

    <?php if ( $post->cover_image ) : ?>
      <figure class="k-cover">
        <img src="<?php echo esc_url( $post->cover_image ); ?>" alt="<?php echo esc_attr( $post->cover_caption ? $post->cover_caption : $post->title ); ?>">
        <?php if ( $post->cover_caption ) : ?><figcaption><?php echo esc_html( $post->cover_caption ); ?></figcaption><?php endif; ?>
      </figure>
    <?php endif; ?>

    <div class="k-article-body" id="k-article-body">
      <?php echo kounselia_render_content( $post->content ); // Cleaned with kounselia_content_kses() on save. ?>
    </div>

    <footer class="k-article-foot">
      <?php if ( $tags ) : ?>
        <div class="k-tags">
          <?php foreach ( $tags as $t ) : ?>
            <a href="<?php echo esc_url( kounselia_blog_tag_url( $t['slug'] ) ); ?>"><?php echo esc_html( $t['name'] ); ?></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="k-foot-share">
        <span>Found this helpful? Share it with someone who might need it.</span>
        <div class="k-share">
          <a href="https://wa.me/?text=<?php echo $share_text . '%20' . $share_url; ?>" target="_blank" rel="noopener" aria-label="Share on WhatsApp"><i class="ti ti-brand-whatsapp"></i></a>
          <a href="https://twitter.com/intent/tweet?text=<?php echo $share_text; ?>&url=<?php echo $share_url; ?>" target="_blank" rel="noopener" aria-label="Share on X"><i class="ti ti-brand-x"></i></a>
          <button type="button" class="k-copy-link" data-url="<?php echo esc_attr( $url ); ?>" aria-label="Copy link"><i class="ti ti-link"></i></button>
        </div>
      </div>

      <div class="k-author-card">
        <?php echo kounselia_blog_av( $author ); ?>
        <div>
          <div class="lbl">Written by</div>
          <h4><?php echo esc_html( $author['name'] ); ?></h4>
          <p><?php echo esc_html( $author['bio'] ? $author['bio'] : 'Part of the team at Kounselia, a global mental wellness initiative making support accessible to anyone, anywhere.' ); ?></p>
        </div>
      </div>

      <div class="k-post-nl">
        <h3>Never miss a story</h3>
        <p>Get new writing from <?php echo esc_html( $kounselia_settings['title'] ); ?> in your inbox. Gentle, useful, and never spammy.</p>
        <?php echo kounselia_newsletter_form_html( 'post' ); ?>
      </div>

      <?php if ( $adjacent['older'] || $adjacent['newer'] ) : ?>
        <nav class="k-adjacent" aria-label="More stories">
          <?php if ( $adjacent['older'] ) : ?>
            <a href="<?php echo esc_url( kounselia_blog_url( $adjacent['older']->slug ) ); ?>"><span>&larr; Previous</span><?php echo esc_html( $adjacent['older']->title ); ?></a>
          <?php else : ?><div></div><?php endif; ?>
          <?php if ( $adjacent['newer'] ) : ?>
            <a class="next" href="<?php echo esc_url( kounselia_blog_url( $adjacent['newer']->slug ) ); ?>"><span>Next &rarr;</span><?php echo esc_html( $adjacent['newer']->title ); ?></a>
          <?php endif; ?>
        </nav>
      <?php endif; ?>
    </footer>
  </article>

  <?php if ( $related ) : ?>
    <section class="k-more">
      <div class="k-wrap">
        <h2 class="k-more-title">More from <?php echo esc_html( $kounselia_settings['title'] ); ?></h2>
        <div class="k-cards">
          <?php foreach ( $related as $r ) : ?>
            <a class="k-card" href="<?php echo esc_url( kounselia_blog_url( $r->slug ) ); ?>">
              <div class="k-card-img"><?php if ( $r->cover_image ) : ?><img src="<?php echo esc_url( $r->cover_image ); ?>" alt="" loading="lazy"><?php endif; ?></div>
              <div class="k-meta"><?php $ra = kounselia_blog_author( $r ); echo kounselia_blog_av( $ra ); ?><b><?php echo esc_html( $ra['name'] ); ?></b></div>
              <h4><?php echo esc_html( $r->title ); ?></h4>
              <p><?php echo esc_html( kounselia_blog_summary( $r, 24 ) ); ?></p>
              <div class="k-meta"><?php echo esc_html( kounselia_blog_date( $r ) ); ?> <span class="dot"></span> <?php echo (int) $r->reading_minutes; ?> min read</div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
  <?php endif; ?>
</main>

<?php require dirname( __DIR__ ) . '/inc/kounselia-footer.php'; ?>
<script>
(function(){
  var bar = document.getElementById('k-progress');
  var body = document.getElementById('k-article-body');
  function onScroll(){
    if(!bar || !body) return;
    var rect = body.getBoundingClientRect();
    var total = body.offsetHeight - window.innerHeight * 0.6;
    var done = Math.min(1, Math.max(0, -rect.top / Math.max(total, 1)));
    bar.style.width = (done * 100) + '%';
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
  document.querySelectorAll('.k-copy-link').forEach(function(btn){
    btn.addEventListener('click', function(){
      var url = btn.dataset.url;
      var done = function(){ var i = btn.querySelector('i'); i.className = 'ti ti-check'; setTimeout(function(){ i.className = 'ti ti-link'; }, 1600); };
      if (navigator.share && /Mobi/i.test(navigator.userAgent)) { navigator.share({ url: url, title: document.title }).catch(function(){}); return; }
      if (navigator.clipboard) { navigator.clipboard.writeText(url).then(done); } else { window.prompt('Copy this link', url); }
    });
  });
})();
</script>
</body>
</html>
    <?php
    exit;
}

/* -------------------------------------------------------------------------
 * Listing: home, topic, search
 * ---------------------------------------------------------------------- */

$tag_slug = ( isset( $kounselia_segments[0] ) && 'tag' === $kounselia_segments[0] ) ? ( isset( $kounselia_segments[1] ) ? $kounselia_segments[1] : '' ) : '';
if ( isset( $kounselia_segments[0] ) && 'tag' === $kounselia_segments[0] && '' === $tag_slug ) {
    wp_safe_redirect( '/blog/', 302 );
    exit;
}
$search   = isset( $_GET['q'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['q'] ) ) ) : '';
$page_num = isset( $_GET['page'] ) ? max( 1, (int) $_GET['page'] ) : 1;
$all_tags = kounselia_blog_all_tags();
$tag_name = $tag_slug && isset( $all_tags[ $tag_slug ] ) ? $all_tags[ $tag_slug ]['name'] : ucwords( str_replace( '-', ' ', $tag_slug ) );

// The big "featured" story sits above the list on the plain blog home.
// It's worked out on every page so it can be left out of the list
// consistently (otherwise paging would repeat a story).
$feature = null;
if ( ! $tag_slug && '' === $search ) {
    $f       = kounselia_blog_query( array( 'featured_only' => true, 'per_page' => 1 ) );
    $feature = $f['items'] ? $f['items'][0] : null;
    if ( ! $feature ) {
        $f       = kounselia_blog_query( array( 'per_page' => 1 ) );
        $feature = $f['items'] ? $f['items'][0] : null;
    }
}

$found = kounselia_blog_query( array(
    'tag'      => $tag_slug,
    'search'   => $search,
    'page'     => $page_num,
    'per_page' => (int) $kounselia_settings['per_page'],
    'exclude'  => $feature ? array( $feature->id ) : array(),
) );
if ( 1 !== $page_num ) {
    $feature = null; // Only shown above page one.
}
$total_pages = max( 1, (int) ceil( $found['total'] / max( 1, (int) $kounselia_settings['per_page'] ) ) );

if ( $tag_slug && ! $found['total'] ) {
    kounselia_public_not_found( 'topic' );
}

$base_url = $tag_slug ? kounselia_blog_tag_url( $tag_slug ) : '/blog/';
$page_url = function ( $n ) use ( $base_url, $search ) {
    $args = array();
    if ( '' !== $search ) {
        $args['q'] = $search;
    }
    if ( $n > 1 ) {
        $args['page'] = $n;
    }
    return $args ? $base_url . '?' . http_build_query( $args ) : $base_url;
};

$title = $tag_slug ? $tag_name . ' — ' . $kounselia_settings['title'] : ( '' !== $search ? 'Search: ' . $search . ' — ' . $kounselia_settings['title'] : $kounselia_settings['title'] . ' — Kounselia' );
kounselia_public_head( array(
    'title'       => $title,
    'description' => $tag_slug ? 'Stories about ' . $tag_name . ' from ' . $kounselia_settings['title'] . '.' : $kounselia_settings['tagline'],
    'url'         => kounselia_site_url( $page_url( $page_num ) ),
    'noindex'     => '' !== $search,
) );
?>
<body class="k-site">
<?php require dirname( __DIR__ ) . '/inc/kounselia-site-nav.php'; ?>

<main>
  <header class="k-blog-mast">
    <div class="k-eyebrow"><?php echo $tag_slug ? 'Topic' : 'The Journal'; ?></div>
    <h1><?php echo esc_html( $tag_slug ? $tag_name : $kounselia_settings['title'] ); ?></h1>
    <p><?php echo esc_html( $tag_slug ? number_format_i18n( $found['total'] ) . ' ' . ( 1 === $found['total'] ? 'story' : 'stories' ) . ' on ' . strtolower( $tag_name ) . '.' : $kounselia_settings['tagline'] ); ?></p>
    <form class="k-blog-search" action="/blog/" method="get" role="search">
      <i class="ti ti-search"></i>
      <input type="search" name="q" value="<?php echo esc_attr( $search ); ?>" placeholder="Search stories" aria-label="Search stories">
    </form>
  </header>

  <?php if ( $all_tags ) : ?>
    <nav class="k-topics" aria-label="Topics">
      <a class="k-pill<?php echo ! $tag_slug ? ' active' : ''; ?>" href="/blog/">All stories</a>
      <?php foreach ( array_slice( $all_tags, 0, 12 ) as $t ) : ?>
        <a class="k-pill<?php echo $tag_slug === $t['slug'] ? ' active' : ''; ?>" href="<?php echo esc_url( kounselia_blog_tag_url( $t['slug'] ) ); ?>"><?php echo esc_html( $t['name'] ); ?></a>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>

  <div class="k-wrap">
    <?php if ( $feature ) : $fa = kounselia_blog_author( $feature ); ?>
      <a class="k-feature" href="<?php echo esc_url( kounselia_blog_url( $feature->slug ) ); ?>">
        <div class="k-feature-img"><?php if ( $feature->cover_image ) : ?><img src="<?php echo esc_url( $feature->cover_image ); ?>" alt=""><?php endif; ?></div>
        <div class="k-feature-body">
          <div class="k-feature-label"><i class="ti ti-sparkles"></i> <?php echo $feature->featured ? 'Featured story' : 'Latest story'; ?></div>
          <h2><?php echo esc_html( $feature->title ); ?></h2>
          <p><?php echo esc_html( kounselia_blog_summary( $feature, 40 ) ); ?></p>
          <div class="k-meta"><?php echo kounselia_blog_av( $fa ); ?><b><?php echo esc_html( $fa['name'] ); ?></b> <span class="dot"></span> <?php echo esc_html( kounselia_blog_date( $feature ) ); ?> <span class="dot"></span> <?php echo (int) $feature->reading_minutes; ?> min read</div>
        </div>
      </a>
    <?php endif; ?>

    <div class="k-blog-layout">
      <section>
        <?php if ( '' !== $search ) : ?>
          <p class="k-results-note"><?php echo esc_html( number_format_i18n( $found['total'] ) . ' result' . ( 1 === $found['total'] ? '' : 's' ) . ' for “' . $search . '”' ); ?> · <a href="/blog/">Clear search</a></p>
        <?php endif; ?>
        <div class="k-feed-head"><span><?php echo $tag_slug ? 'Latest on ' . esc_html( strtolower( $tag_name ) ) : ( '' !== $search ? 'Results' : 'Latest stories' ); ?></span></div>

        <?php if ( $found['items'] ) : ?>
          <?php foreach ( $found['items'] as $p ) { kounselia_blog_feed_item( $p ); } ?>
        <?php elseif ( ! $feature ) : ?>
          <div class="k-empty">
            <i class="ti ti-feather"></i>
            <h3><?php echo '' !== $search ? 'Nothing matched your search' : 'Our first stories are on their way'; ?></h3>
            <p><?php echo '' !== $search ? 'Try a different word, or browse a topic above.' : 'Subscribe and we will let you know the moment something new is published.'; ?></p>
          </div>
        <?php endif; ?>

        <?php if ( $total_pages > 1 ) : ?>
          <div class="k-pager">
            <?php if ( $page_num > 1 ) : ?><a href="<?php echo esc_url( $page_url( $page_num - 1 ) ); ?>"><i class="ti ti-arrow-left"></i> Newer</a><?php else : ?><span></span><?php endif; ?>
            <?php if ( $page_num < $total_pages ) : ?><a href="<?php echo esc_url( $page_url( $page_num + 1 ) ); ?>">Older <i class="ti ti-arrow-right"></i></a><?php endif; ?>
          </div>
        <?php endif; ?>
      </section>

      <?php kounselia_blog_sidebar( $kounselia_settings, $tag_slug ); ?>
    </div>
  </div>
</main>

<?php require dirname( __DIR__ ) . '/inc/kounselia-footer.php'; ?>
</body>
</html>
