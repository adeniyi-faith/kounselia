<?php
/**
 * Styles for the content side of the site: editable pages (/page/),
 * the blog (/blog/), newsletter screens (/newsletter/) and the pieces
 * the shared footer adds (newsletter box, real links). Loaded after
 * kounselia-styles.php and reuses its color tokens. index.php loads it
 * too, for the footer.
 *
 * Reading typography follows long-form publishing conventions (Medium
 * and friends): a ~680px measure, a 20px serif body with generous line
 * height, and sans-serif UI around it.
 */
?>
<style>
/* ---------- Page shell ---------- */
body.k-site{height:auto;min-height:100%;background:var(--bg)}
html:has(body.k-site){height:auto}
body.k-site .land-nav{position:sticky}
.k-nav-inner{display:flex;align-items:center;justify-content:space-between;gap:16px}
.k-nav-links{display:flex;gap:4px;align-items:center;flex:1;justify-content:center}
.k-nav-links a{font-size:14px;color:var(--text2);text-decoration:none;padding:8px 14px;border-radius:50px;transition:all .2s ease;white-space:nowrap}
.k-nav-links a:hover{color:var(--accent);background:var(--surface2)}
.k-nav-links a.active{color:var(--accent);font-weight:500;background:var(--accent-light)}
a.k-nav-btn{text-decoration:none;display:inline-flex;align-items:center;white-space:nowrap}
@media (max-width:760px){ .k-hide-sm{display:none!important} .k-nav-links{justify-content:flex-end} }
@media (max-width:520px){ .k-hide-xs{display:none!important} .k-nav-links a{padding:8px 10px} .k-nav-links a:first-child{display:none} }

.k-eyebrow{font-size:11.5px;font-weight:600;letter-spacing:2.5px;text-transform:uppercase;color:var(--gold)}
.k-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;background:linear-gradient(135deg,var(--accent) 0%,var(--accent2) 100%);color:#fff!important;text-decoration:none!important;padding:14px 28px;border-radius:50px;font-family:'Outfit',sans-serif;font-size:15px;font-weight:500;box-shadow:var(--shadow-btn);transition:transform .25s ease,box-shadow .25s ease;border:none;cursor:pointer}
.k-btn:hover{transform:translateY(-2px);box-shadow:0 8px 22px rgba(30,58,95,.3)}
.k-btn.outline{background:var(--surface);color:var(--accent)!important;border:1px solid var(--border);box-shadow:none}
.k-btn.outline:hover{border-color:var(--accent)}
.k-center{text-align:center}

/* ---------- Editable pages ---------- */
.k-page-hero{position:relative;overflow:hidden;padding:72px 24px 56px;text-align:center;background:
  radial-gradient(900px 380px at 15% -10%, rgba(176,125,58,.10), transparent 60%),
  radial-gradient(700px 360px at 90% 0%, rgba(30,58,95,.08), transparent 60%)}
@media (min-width:768px){ .k-page-hero{padding:110px 24px 72px} }
.k-page-hero .k-eyebrow{margin-bottom:18px;display:inline-flex;align-items:center;gap:8px}
.k-page-hero .k-eyebrow::before,.k-page-hero .k-eyebrow::after{content:'';width:24px;height:1px;background:currentColor;opacity:.5}
.k-page-title{font-family:'Cormorant Garamond',serif;font-weight:400;font-size:44px;line-height:1.08;color:var(--text);max-width:860px;margin:0 auto 20px;letter-spacing:-.01em}
@media (min-width:768px){ .k-page-title{font-size:68px} }
.k-page-sub{font-size:17px;line-height:1.7;color:var(--text2);max-width:620px;margin:0 auto;font-weight:300}
@media (min-width:768px){ .k-page-sub{font-size:19px} }
.k-page-hero-img{max-width:1040px;margin:-8px auto 0;padding:0 20px}
.k-page-hero-img img{width:100%;max-height:520px;object-fit:cover;border-radius:28px;box-shadow:var(--shadow-md);display:block}
.k-page-body{max-width:720px;margin:0 auto;padding:48px 22px 80px}

/* Rich-text content shared by pages and the email-safe editor classes */
.k-prose{font-size:17px;line-height:1.8;color:var(--text2);font-weight:300}
.k-prose > *:first-child{margin-top:0}
.k-prose p{margin:0 0 1.35em}
.k-prose .k-lead,.k-prose p.k-lead{font-size:21px;line-height:1.65;color:var(--text);font-weight:300}
.k-prose h2{font-family:'Cormorant Garamond',serif;font-weight:500;font-size:34px;line-height:1.2;color:var(--accent);margin:1.8em 0 .6em}
.k-prose h3{font-family:'Outfit',sans-serif;font-weight:600;font-size:19px;color:var(--text);margin:1.6em 0 .5em}
.k-prose h4{font-weight:600;font-size:16px;color:var(--text);margin:1.4em 0 .4em}
.k-prose strong{color:var(--text);font-weight:500}
.k-prose a{color:var(--rose);text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:3px}
.k-prose a:hover{color:var(--accent)}
.k-prose ul,.k-prose ol{margin:0 0 1.4em;padding-left:1.3em}
.k-prose li{margin:0 0 .6em;padding-left:.2em}
.k-prose ul li::marker{color:var(--gold)}
.k-prose blockquote{margin:2em 0;padding:4px 0 4px 26px;border-left:3px solid var(--gold);font-family:'Cormorant Garamond',serif;font-size:28px;line-height:1.4;color:var(--accent);font-style:italic}
.k-prose blockquote p{margin:0}
.k-prose img{max-width:100%;height:auto;border-radius:18px;display:block;margin:2em auto}
.k-prose figure{margin:2em 0}
.k-prose figcaption{text-align:center;font-size:13px;color:var(--text3);margin-top:8px}
.k-prose hr{border:none;text-align:center;margin:2.6em 0;height:auto}
.k-prose hr::before{content:'\2022 \2022 \2022';letter-spacing:1em;color:var(--text3);font-size:18px}
.k-prose table{width:100%;border-collapse:collapse;margin:0 0 1.6em;font-size:15px}
.k-prose th,.k-prose td{border-bottom:1px solid var(--border);padding:10px 8px;text-align:left}
.k-prose th{font-weight:600;color:var(--text)}
.k-prose iframe{width:100%;aspect-ratio:16/9;height:auto;border:0;border-radius:18px;margin:1.6em 0}
.k-prose pre{background:var(--surface2);padding:16px;border-radius:12px;overflow:auto;font-size:14px;margin:0 0 1.4em}
.k-prose code{background:var(--surface2);padding:2px 6px;border-radius:6px;font-size:.9em}
.k-prose .k-btn{text-decoration:none}
.k-callout{background:var(--gold-light);border:1px solid rgba(176,125,58,.18);border-radius:20px;padding:22px 24px;margin:1.8em 0;color:var(--text)}
.k-callout p:last-child{margin-bottom:0}

/* Content blocks */
.k-block-counselors{display:grid;grid-template-columns:1fr;gap:14px;margin:2em 0}
@media (min-width:640px){ .k-block-counselors{grid-template-columns:1fr 1fr} }
.k-counselor{display:flex;flex-direction:column;gap:6px;background:var(--surface);border:1px solid var(--border);border-radius:22px;padding:22px;text-decoration:none!important;color:inherit;transition:transform .25s ease,box-shadow .25s ease}
.k-counselor:hover{transform:translateY(-3px);box-shadow:var(--shadow-hover)}
.k-counselor-av{width:44px;height:44px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:22px;margin-bottom:6px}
.k-counselor-spec{font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:var(--text3);font-weight:600}
.k-counselor-name{font-family:'Cormorant Garamond',serif;font-size:26px;color:var(--text);line-height:1.1}
.k-counselor-desc{font-size:14.5px;line-height:1.6;color:var(--text2)}
.k-counselor-cta{margin-top:auto;padding-top:8px;font-size:14px;color:var(--accent);font-weight:500;display:flex;align-items:center;gap:6px}
.k-block-plans{display:grid;grid-template-columns:1fr;gap:16px;margin:2em 0}
@media (min-width:640px){ .k-block-plans{grid-template-columns:repeat(auto-fit,minmax(240px,1fr))} }
.k-plan{position:relative;background:var(--surface);border:1px solid var(--border);border-radius:24px;padding:28px 26px;display:flex;flex-direction:column}
.k-plan.popular{border-color:var(--gold);box-shadow:0 12px 32px rgba(176,125,58,.14)}
.k-plan-badge{position:absolute;top:-12px;left:24px;background:var(--gold);color:#fff;font-size:11px;font-weight:600;letter-spacing:1px;text-transform:uppercase;padding:5px 12px;border-radius:50px}
.k-plan-name{font-size:14px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;color:var(--text3)}
.k-plan-price{font-family:'Cormorant Garamond',serif;font-size:44px;color:var(--accent);margin:8px 0 16px;line-height:1}
.k-plan-price span{font-family:'Outfit',sans-serif;font-size:14px;color:var(--text3)}
.k-prose .k-plan ul{list-style:none;padding:0;margin:0 0 24px;flex:1}
.k-prose .k-plan li{padding-left:26px;position:relative;font-size:15px}
.k-prose .k-plan li::before{content:'\2713';position:absolute;left:0;color:var(--sage);font-weight:600}
.k-block-newsletter{background:var(--accent);color:#fff;border-radius:26px;padding:30px 28px;margin:2.2em 0;display:grid;gap:18px}
.k-block-newsletter h3{font-family:'Cormorant Garamond',serif;font-size:30px;font-weight:400;color:#fff;margin:0}
.k-block-newsletter p{color:rgba(255,255,255,.75);margin:6px 0 0;font-size:15px}
.k-block-posts{display:grid;grid-template-columns:1fr;gap:14px;margin:1.6em 0}
@media (min-width:640px){ .k-block-posts{grid-template-columns:repeat(3,1fr)} }
.k-mini-post{display:flex;flex-direction:column;gap:6px;text-decoration:none!important;color:inherit}
.k-prose .k-mini-post img{margin:0 0 6px;aspect-ratio:16/10;object-fit:cover;width:100%;border-radius:14px}
.k-mini-title{font-weight:500;color:var(--text);line-height:1.35;font-size:15.5px}
.k-mini-meta{font-size:12.5px;color:var(--text3)}

/* Newsletter form (footer, sidebar, blocks) */
.k-nl-form{display:flex;flex-wrap:wrap;gap:10px;align-items:stretch;position:relative}
.k-nl-form input{flex:1 1 180px;min-width:0;padding:14px 18px;border-radius:50px;border:1px solid var(--border);background:var(--surface);font-family:'Outfit',sans-serif;font-size:15px;color:var(--text);outline:none;transition:border-color .2s,box-shadow .2s}
.k-nl-form input:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-light)}
.k-nl-form input[name=name]{flex:1 1 130px}
.k-nl-form button{padding:14px 26px;border-radius:50px;border:none;background:var(--gold);color:#fff;font-family:'Outfit',sans-serif;font-size:15px;font-weight:500;cursor:pointer;transition:transform .2s,filter .2s}
.k-nl-form button:hover{transform:translateY(-1px);filter:brightness(1.05)}
.k-nl-form button:disabled{opacity:.6;cursor:wait}
.k-nl-form .k-hp{position:absolute!important;left:-9999px!important;width:1px;height:1px;opacity:0}
.k-nl-msg{flex-basis:100%;font-size:13.5px;min-height:0}
.k-nl-msg:empty{display:none}
.k-nl-msg.ok{color:var(--sage)}
.k-nl-msg.error{color:var(--rose)}
.k-nl-form.done input,.k-nl-form.done button{display:none}
.footer .k-nl-form input,.k-block-newsletter .k-nl-form input{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.18);color:#fff}
.footer .k-nl-form input::placeholder,.k-block-newsletter .k-nl-form input::placeholder{color:rgba(255,255,255,.5)}
.footer .k-nl-form input:focus,.k-block-newsletter .k-nl-form input:focus{box-shadow:0 0 0 3px rgba(255,255,255,.12);border-color:rgba(255,255,255,.4)}
.footer .k-nl-msg.ok,.k-block-newsletter .k-nl-msg.ok{color:#BFE3C8}
.footer .k-nl-msg.error,.k-block-newsletter .k-nl-msg.error{color:#F4C2D0}

/* Footer additions */
.k-footer-nl{display:grid;gap:18px;padding:0 0 40px;margin-bottom:44px;border-bottom:1px solid rgba(255,255,255,.1)}
@media (min-width:900px){ .k-footer-nl{grid-template-columns:1fr 1.25fr;align-items:center;gap:48px} }
.k-footer-nl-title{font-family:'Cormorant Garamond',serif;font-size:30px;color:#fff;line-height:1.2;margin-bottom:6px}
.k-footer-nl p{font-size:14px;color:rgba(255,255,255,.6);line-height:1.7;font-weight:300;max-width:440px}
.k-footer-top{display:flex;flex-wrap:wrap;gap:48px;justify-content:space-between;margin-bottom:48px}
.k-footer-brand{flex:1;min-width:260px}
.k-footer-grid{display:flex!important;flex-wrap:wrap;gap:40px 64px}
a.footer-link{text-decoration:none;display:inline-block}
a.social-btn{text-decoration:none}

/* ---------- Blog: index ---------- */
.k-blog-mast{text-align:center;padding:64px 22px 28px}
@media (min-width:768px){ .k-blog-mast{padding:88px 22px 36px} }
.k-blog-mast h1{font-family:'Cormorant Garamond',serif;font-weight:400;font-size:48px;line-height:1.05;color:var(--text);margin:14px 0 14px;letter-spacing:-.01em}
@media (min-width:768px){ .k-blog-mast h1{font-size:72px} }
.k-blog-mast p{font-size:17px;color:var(--text2);max-width:560px;margin:0 auto;line-height:1.7;font-weight:300}
.k-blog-search{max-width:420px;margin:26px auto 0;position:relative}
.k-blog-search input{width:100%;padding:13px 18px 13px 46px;border-radius:50px;border:1px solid var(--border);background:var(--surface);font-family:'Outfit',sans-serif;font-size:15px;outline:none;color:var(--text)}
.k-blog-search input:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-light)}
.k-blog-search i{position:absolute;left:18px;top:50%;transform:translateY(-50%);color:var(--text3);font-size:18px}

.k-topics{display:flex;gap:8px;overflow-x:auto;padding:4px 22px 18px;max-width:1140px;margin:0 auto;scrollbar-width:none;justify-content:flex-start}
.k-topics::-webkit-scrollbar{display:none}
@media (min-width:900px){ .k-topics{justify-content:center;flex-wrap:wrap} }
.k-pill{flex-shrink:0;padding:8px 16px;border-radius:50px;background:var(--surface);border:1px solid var(--border);color:var(--text2);font-size:13.5px;text-decoration:none;transition:all .2s;white-space:nowrap}
.k-pill:hover{border-color:var(--accent);color:var(--accent)}
.k-pill.active{background:var(--accent);border-color:var(--accent);color:#fff}
.k-pill small{opacity:.6;margin-left:4px}

.k-wrap{max-width:1140px;margin:0 auto;padding:0 22px}
.k-feature{display:grid;grid-template-columns:1fr;gap:26px;align-items:center;background:var(--surface);border:1px solid var(--border);border-radius:30px;padding:16px;margin:18px 0 48px;text-decoration:none;color:inherit;transition:box-shadow .3s ease,transform .3s ease}
.k-feature:hover{box-shadow:var(--shadow-hover);transform:translateY(-2px)}
@media (min-width:860px){ .k-feature{grid-template-columns:1.15fr 1fr;padding:18px;gap:40px} }
.k-feature-img{aspect-ratio:16/10;border-radius:22px;overflow:hidden;background:linear-gradient(135deg,var(--accent-light),var(--gold-light))}
.k-feature-img img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .6s ease}
.k-feature:hover .k-feature-img img{transform:scale(1.03)}
.k-feature-body{padding:6px 10px 14px}
@media (min-width:860px){ .k-feature-body{padding:10px 26px 10px 0} }
.k-feature-label{display:inline-flex;align-items:center;gap:6px;font-size:11.5px;font-weight:600;letter-spacing:2px;text-transform:uppercase;color:var(--gold);margin-bottom:14px}
.k-feature h2{font-family:'Cormorant Garamond',serif;font-weight:500;font-size:34px;line-height:1.12;color:var(--text);margin-bottom:14px}
@media (min-width:860px){ .k-feature h2{font-size:44px} }
.k-feature p{font-size:16.5px;line-height:1.7;color:var(--text2);margin-bottom:20px;font-weight:300}

.k-blog-layout{display:grid;grid-template-columns:1fr;gap:56px;padding-bottom:90px}
@media (min-width:1000px){ .k-blog-layout{grid-template-columns:minmax(0,1fr) 320px;gap:72px} }
.k-feed-head{font-size:13px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;color:var(--text3);padding-bottom:14px;border-bottom:1px solid var(--border);margin-bottom:6px;display:flex;justify-content:space-between;align-items:center}
.k-feed-head a{color:var(--rose);text-decoration:none;text-transform:none;letter-spacing:0;font-weight:500}
.k-feed-item{display:grid;grid-template-columns:minmax(0,1fr) 112px;gap:22px;padding:28px 0;border-bottom:1px solid var(--border);text-decoration:none;color:inherit}
@media (min-width:640px){ .k-feed-item{grid-template-columns:minmax(0,1fr) 180px;gap:40px} }
.k-feed-item.no-thumb{grid-template-columns:1fr}
.k-feed-item:hover h3{color:var(--accent)}
.k-meta{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text3);flex-wrap:wrap}
.k-meta b{color:var(--text);font-weight:500}
.k-meta .dot::before{content:'\00B7'}
.k-av{width:24px;height:24px;border-radius:50%;background:var(--accent-light);color:var(--accent);display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;overflow:hidden;flex-shrink:0}
.k-av img{width:100%;height:100%;object-fit:cover}
.k-feed-item h3{font-family:'Outfit',sans-serif;font-weight:600;font-size:19px;line-height:1.3;color:var(--text);margin:10px 0 6px;transition:color .2s;letter-spacing:-.005em}
@media (min-width:640px){ .k-feed-item h3{font-size:22px} }
.k-feed-item p{font-family:'Source Serif 4',Georgia,serif;font-size:15.5px;line-height:1.55;color:var(--text2);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.k-feed-foot{display:flex;gap:10px;align-items:center;margin-top:14px;font-size:12.5px;color:var(--text3)}
.k-tag-chip{background:var(--surface2);color:var(--text2);padding:4px 11px;border-radius:50px;font-size:12px}
.k-feed-thumb{aspect-ratio:1/1;border-radius:14px;overflow:hidden;background:var(--surface2);align-self:center}
@media (min-width:640px){ .k-feed-thumb{aspect-ratio:4/3} }
.k-feed-thumb img{width:100%;height:100%;object-fit:cover;display:block}
.k-empty{padding:60px 20px;text-align:center;color:var(--text3);background:var(--surface);border:1px dashed var(--border);border-radius:24px;margin-top:24px}
.k-empty i{font-size:34px;color:var(--gold);display:block;margin-bottom:10px}
.k-empty h3{font-family:'Cormorant Garamond',serif;font-size:28px;color:var(--text);font-weight:500;margin-bottom:6px}

.k-sidebar{display:flex;flex-direction:column;gap:34px}
@media (min-width:1000px){ .k-sidebar{position:sticky;top:100px;align-self:start} }
.k-side-title{font-size:13px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;color:var(--text3);margin-bottom:16px}
.k-popular{list-style:none;counter-reset:pop;display:flex;flex-direction:column;gap:18px;padding:0;margin:0}
.k-popular li{counter-increment:pop;display:grid;grid-template-columns:34px 1fr;gap:6px}
.k-popular li::before{content:counter(pop,decimal-leading-zero);font-family:'Cormorant Garamond',serif;font-size:28px;line-height:1;color:var(--border)}
.k-popular a{text-decoration:none;color:var(--text);font-weight:500;font-size:15px;line-height:1.35}
.k-popular a:hover{color:var(--accent)}
.k-popular .k-meta{margin-top:4px;font-size:12px}
.k-side-topics{display:flex;flex-wrap:wrap;gap:8px}
.k-side-card{background:var(--accent);color:#fff;border-radius:24px;padding:26px 22px}
.k-side-card h4{font-family:'Cormorant Garamond',serif;font-size:26px;font-weight:400;line-height:1.2;margin-bottom:8px}
.k-side-card p{font-size:14px;color:rgba(255,255,255,.72);line-height:1.6;margin-bottom:16px}
.k-side-card .k-nl-form input{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.18);color:#fff;flex-basis:100%}
.k-side-card .k-nl-form input::placeholder{color:rgba(255,255,255,.5)}
.k-side-card .k-nl-form button{flex-basis:100%}
.k-side-card .k-nl-msg.ok{color:#BFE3C8}
.k-side-card .k-nl-msg.error{color:#F4C2D0}
.k-side-links{display:flex;gap:16px;font-size:13px}
.k-side-links a{color:var(--text3);text-decoration:none;display:inline-flex;align-items:center;gap:5px}
.k-side-links a:hover{color:var(--accent)}
.k-pager{display:flex;justify-content:space-between;gap:12px;padding-top:34px}
.k-pager a{display:inline-flex;align-items:center;gap:6px;padding:11px 20px;border-radius:50px;border:1px solid var(--border);background:var(--surface);text-decoration:none;color:var(--text);font-size:14px}
.k-pager a:hover{border-color:var(--accent);color:var(--accent)}
.k-results-note{font-size:15px;color:var(--text2);margin:6px 0 10px}

/* ---------- Blog: single post ---------- */
.k-progress{position:fixed;top:0;left:0;height:3px;width:0;background:linear-gradient(90deg,var(--gold),var(--rose));z-index:100;transition:width .1s linear}
.k-article{padding:56px 22px 20px}
@media (min-width:768px){ .k-article{padding:76px 22px 20px} }
.k-article-head{max-width:720px;margin:0 auto}
.k-article-tag{font-size:12px;font-weight:600;letter-spacing:2px;text-transform:uppercase;color:var(--gold);text-decoration:none}
.k-article-title{font-family:'Source Serif 4',Georgia,serif;font-weight:600;font-size:34px;line-height:1.18;color:#1b1a16;letter-spacing:-.018em;margin:14px 0 12px}
@media (min-width:768px){ .k-article-title{font-size:46px} }
.k-article-sub{font-family:'Outfit',sans-serif;font-size:19px;line-height:1.5;color:var(--text2);font-weight:300;margin-bottom:26px}
@media (min-width:768px){ .k-article-sub{font-size:22px} }
.k-byline{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px 0;border-top:1px solid var(--border);border-bottom:1px solid var(--border);flex-wrap:wrap}
.k-byline-who{display:flex;align-items:center;gap:12px}
.k-byline .k-av{width:44px;height:44px;font-size:16px}
.k-byline-name{font-weight:500;color:var(--text);font-size:15px}
.k-byline-meta{font-size:13.5px;color:var(--text3);margin-top:2px}
.k-share{display:flex;gap:4px}
.k-share a,.k-share button{width:38px;height:38px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;color:var(--text3);background:none;border:none;cursor:pointer;font-size:19px;text-decoration:none;transition:all .2s}
.k-share a:hover,.k-share button:hover{color:var(--accent);background:var(--surface2)}
.k-cover{max-width:1000px;margin:36px auto 0}
.k-cover img{width:100%;max-height:600px;object-fit:cover;border-radius:22px;display:block}
.k-cover figcaption{text-align:center;font-size:13px;color:var(--text3);margin-top:10px}

.k-article-body{max-width:680px;margin:40px auto 0;font-family:'Source Serif 4',Georgia,serif;font-size:19px;line-height:1.75;color:#262520;font-weight:400}
@media (min-width:768px){ .k-article-body{font-size:20.5px;line-height:1.72} }
.k-article-body p{margin:0 0 1.55em}
.k-article-body > p:first-of-type::first-letter{font-size:3.4em;float:left;line-height:.9;margin:.08em .1em 0 0;font-weight:600;color:var(--accent)}
.k-article-body h2{font-family:'Outfit',sans-serif;font-weight:600;font-size:26px;line-height:1.3;color:#1b1a16;margin:1.9em 0 .5em;letter-spacing:-.01em}
.k-article-body h3{font-family:'Outfit',sans-serif;font-weight:600;font-size:21px;color:#1b1a16;margin:1.6em 0 .4em}
.k-article-body a{color:inherit;text-decoration:underline;text-decoration-color:var(--gold);text-underline-offset:3px}
.k-article-body strong{font-weight:600}
.k-article-body ul,.k-article-body ol{margin:0 0 1.55em;padding-left:1.4em}
.k-article-body li{margin-bottom:.55em;padding-left:.3em}
.k-article-body blockquote{margin:1.8em 0;padding-left:24px;border-left:3px solid #1b1a16;font-style:italic;color:#262520}
.k-article-body blockquote p{margin:0}
.k-article-body img{max-width:100%;height:auto;display:block;margin:2em auto;border-radius:14px}
.k-article-body figcaption{text-align:center;font-family:'Outfit',sans-serif;font-size:13.5px;color:var(--text3);margin-top:-1.2em;margin-bottom:2em}
.k-article-body hr{border:none;margin:2.5em 0;text-align:center;height:auto}
.k-article-body hr::before{content:'\2022 \2022 \2022';letter-spacing:1em;color:#262520;font-size:20px}
.k-article-body iframe{width:100%;aspect-ratio:16/9;height:auto;border:0;border-radius:14px;margin:1.6em 0}
.k-article-body pre{font-size:15px;background:var(--surface2);padding:18px;border-radius:12px;overflow:auto;margin:0 0 1.5em}
.k-article-body code{font-size:.85em;background:var(--surface2);padding:2px 6px;border-radius:5px}
.k-article-body table{width:100%;border-collapse:collapse;margin:0 0 1.6em;font-family:'Outfit',sans-serif;font-size:15px}
.k-article-body th,.k-article-body td{border-bottom:1px solid var(--border);padding:10px 8px;text-align:left}
.k-article-body .k-callout{font-family:'Outfit',sans-serif;font-size:17px}
.k-article-body .k-lead{font-size:1.15em;color:#1b1a16}
.k-article-body .k-btn{font-family:'Outfit',sans-serif;text-decoration:none}

.k-article-foot{max-width:680px;margin:48px auto 0}
.k-tags{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:28px}
.k-tags a{padding:8px 16px;border-radius:50px;background:var(--surface2);color:var(--text);font-size:14px;text-decoration:none;transition:background .2s}
.k-tags a:hover{background:var(--surface3)}
.k-foot-share{display:flex;justify-content:space-between;align-items:center;padding:14px 0;border-top:1px solid var(--border);border-bottom:1px solid var(--border);margin-bottom:40px;font-size:14px;color:var(--text3)}
.k-author-card{display:flex;gap:18px;align-items:flex-start;padding:0 0 40px;border-bottom:1px solid var(--border)}
.k-author-card .k-av{width:64px;height:64px;font-size:24px}
.k-author-card .lbl{font-size:12px;letter-spacing:1.5px;text-transform:uppercase;color:var(--text3);font-weight:600}
.k-author-card h4{font-size:20px;font-weight:500;color:var(--text);margin:4px 0 6px}
.k-author-card p{font-size:15px;color:var(--text2);line-height:1.6}
.k-post-nl{background:var(--accent);border-radius:28px;padding:34px 30px;margin:44px 0;color:#fff;position:relative;overflow:hidden}
.k-post-nl::after{content:'';position:absolute;right:-60px;top:-60px;width:220px;height:220px;border-radius:50%;background:radial-gradient(circle,rgba(176,125,58,.35),transparent 70%)}
.k-post-nl h3{font-family:'Cormorant Garamond',serif;font-size:32px;font-weight:400;line-height:1.15;margin-bottom:8px;position:relative;z-index:1}
.k-post-nl p{color:rgba(255,255,255,.72);font-size:15px;margin-bottom:18px;position:relative;z-index:1}
.k-post-nl .k-nl-form{position:relative;z-index:1}
.k-post-nl .k-nl-form input{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.18);color:#fff}
.k-post-nl .k-nl-form input::placeholder{color:rgba(255,255,255,.5)}
.k-post-nl .k-nl-msg.ok{color:#BFE3C8}
.k-post-nl .k-nl-msg.error{color:#F4C2D0}
.k-adjacent{display:grid;grid-template-columns:1fr;gap:12px;margin-bottom:10px}
@media (min-width:640px){ .k-adjacent{grid-template-columns:1fr 1fr} }
.k-adjacent a{display:block;padding:18px 20px;border:1px solid var(--border);border-radius:18px;text-decoration:none;color:var(--text);background:var(--surface);transition:border-color .2s}
.k-adjacent a:hover{border-color:var(--accent)}
.k-adjacent span{display:block;font-size:12px;letter-spacing:1.5px;text-transform:uppercase;color:var(--text3);margin-bottom:6px;font-weight:600}
.k-adjacent .next{text-align:right}

.k-more{background:var(--surface);border-top:1px solid var(--border);padding:64px 0 80px;margin-top:64px}
.k-more-title{font-family:'Cormorant Garamond',serif;font-size:36px;font-weight:400;color:var(--text);margin-bottom:28px}
.k-cards{display:grid;grid-template-columns:1fr;gap:30px}
@media (min-width:720px){ .k-cards{grid-template-columns:repeat(3,1fr)} }
.k-card{text-decoration:none;color:inherit;display:flex;flex-direction:column;gap:10px}
.k-card-img{aspect-ratio:16/10;border-radius:18px;overflow:hidden;background:linear-gradient(135deg,var(--accent-light),var(--gold-light))}
.k-card-img img{width:100%;height:100%;object-fit:cover;transition:transform .5s ease}
.k-card:hover .k-card-img img{transform:scale(1.04)}
.k-card h4{font-size:19px;font-weight:600;line-height:1.3;color:var(--text)}
.k-card:hover h4{color:var(--accent)}
.k-card p{font-family:'Source Serif 4',Georgia,serif;font-size:15px;color:var(--text2);line-height:1.55;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}

.k-draft-bar{background:var(--gold);color:#fff;text-align:center;font-size:13.5px;padding:10px 16px}
.k-draft-bar a{color:#fff;font-weight:600}

/* ---------- CTA band, 404, newsletter screens ---------- */
.k-cta-band{background:radial-gradient(700px 300px at 50% 0%,rgba(176,125,58,.16),transparent 70%),var(--gold-light);border-top:1px solid rgba(176,125,58,.15);color:var(--text);text-align:center;padding:76px 22px}
.k-cta-band h2{font-family:'Cormorant Garamond',serif;font-weight:400;font-size:40px;line-height:1.15;margin-bottom:12px;color:var(--text)}
@media (min-width:768px){ .k-cta-band h2{font-size:52px} }
.k-cta-band h2 em{color:var(--gold)}
.k-cta-band p{color:var(--text2);font-size:16px;max-width:520px;margin:0 auto 26px;line-height:1.7}
.k-cta-band .k-btn.ghost{background:var(--surface);color:var(--accent)!important;border:1px solid var(--border);box-shadow:none}
.k-cta-actions{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
.k-notfound,.k-card-center{max-width:560px;margin:0 auto;padding:110px 22px 120px;text-align:center}
.k-notfound h1,.k-card-center h1{font-family:'Cormorant Garamond',serif;font-weight:400;font-size:44px;line-height:1.15;color:var(--text);margin:14px 0 14px}
.k-notfound p,.k-card-center p{font-size:16.5px;color:var(--text2);line-height:1.7;margin-bottom:26px}
.k-notfound-links{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
.k-card-center .k-panel{background:var(--surface);border:1px solid var(--border);border-radius:26px;padding:28px;text-align:left;box-shadow:var(--shadow-sm);margin-bottom:18px}
.k-check{display:flex;gap:14px;align-items:flex-start;padding:14px 0;border-bottom:1px solid var(--border);cursor:pointer}
.k-check:last-of-type{border-bottom:none}
.k-check input{width:20px;height:20px;accent-color:var(--accent);margin-top:2px;flex-shrink:0}
.k-check b{display:block;color:var(--text);font-weight:500;font-size:15.5px}
.k-check span{font-size:14px;color:var(--text3)}
.k-link-btn{background:none;border:none;color:var(--rose);font-family:'Outfit',sans-serif;font-size:14px;cursor:pointer;text-decoration:underline;margin-top:14px}
.k-status{display:inline-flex;align-items:center;justify-content:center;width:64px;height:64px;border-radius:50%;background:var(--sage-light);color:var(--sage);font-size:30px}
.k-status.warn{background:var(--rose-light);color:var(--rose)}
</style>
