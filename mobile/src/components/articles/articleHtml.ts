// One article as a small web page, styled like the website's blog post:
// topic, title, byline, cover picture, the body, the author's note and
// "Keep reading". The body is HTML written in the website's editor and
// cleaned by the server before it was saved.
import type { BlogPost, Translate } from '@kounselia/core';
import type { Palette } from '@/theme';
import { articleDate } from './ArticleCard';

function esc(text: string | null | undefined) {
  return String(text ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);
}

export function articleHtml(post: BlogPost, colors: Palette, dark: boolean, t: Translate, language: string) {
  const initial = esc(post.author.name.charAt(0).toUpperCase());
  const avatar = post.author.avatar ? `<img src="${esc(post.author.avatar)}" alt="">` : initial;
  const topic = post.tags[0]?.name;

  const related = post.related
    .map(
      (r) => `<a class="rel" href="${esc(r.url)}">
        ${r.cover ? `<img src="${esc(r.cover)}" alt="">` : '<span class="rel-ph"></span>'}
        <span><b>${esc(r.title)}</b><small>${esc(t('m.b.card.min_read', { minutes: r.reading_minutes }))}</small></span>
      </a>`,
    )
    .join('');

  return `<!doctype html>
<html><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="${dark ? 'dark' : 'light'}">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500&family=Outfit:wght@400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/dist/tabler-icons.min.css" rel="stylesheet">
<style>
  *{box-sizing:border-box}
  html{-webkit-text-size-adjust:100%}
  body{margin:0;padding:6px 20px 48px;background:${colors.bg};color:${colors.text};font:400 17px/1.72 Outfit,-apple-system,Roboto,sans-serif;overflow-wrap:break-word}
  .topic{font-weight:600;font-size:11px;letter-spacing:.1em;color:${colors.gold};text-transform:uppercase;margin:4px 0 10px}
  h1{font:500 34px/1.12 'Cormorant Garamond',Georgia,serif;margin:0 0 10px}
  .sub{font-size:17px;line-height:1.55;color:${colors.text2};margin:0 0 18px}
  .by{display:flex;align-items:center;gap:10px;font-size:13px;color:${colors.text3};margin-bottom:22px}
  .by b{display:block;color:${colors.text};font-weight:500;font-size:14px}
  .av{width:38px;height:38px;border-radius:50%;background:${colors.accentLight};color:${colors.accentText};display:flex;align-items:center;justify-content:center;font-weight:600;overflow:hidden;flex:none}
  .av img{width:100%;height:100%;object-fit:cover}
  figure{margin:0 -20px 26px}
  figure img{width:100%;display:block}
  figcaption{font-size:12px;color:${colors.text3};padding:8px 20px 0}
  .body img{max-width:100%;height:auto;border-radius:16px}
  .body iframe,.body video{max-width:100%;border-radius:16px}
  .body h2,.body h3{font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;line-height:1.2;margin:1.6em 0 .5em}
  .body h2{font-size:28px}.body h3{font-size:23px}
  .body p{margin:0 0 1.1em}
  .body a{color:${colors.accentText};text-underline-offset:3px}
  .body blockquote{margin:1.4em 0;padding:4px 0 4px 18px;border-left:3px solid ${colors.gold};font-family:'Cormorant Garamond',Georgia,serif;font-size:22px;line-height:1.4;color:${colors.text2}}
  .body ul,.body ol{padding-left:1.3em}
  .body li{margin:.35em 0}
  .body hr{border:0;border-top:1px solid ${colors.border};margin:2em 0}
  .body table{width:100%;border-collapse:collapse;font-size:15px}
  .body td,.body th{border:1px solid ${colors.border};padding:8px}
  .bio{display:flex;gap:12px;background:${colors.surface};border:1px solid ${colors.border};border-radius:20px;padding:16px;margin-top:32px;font-size:14px;line-height:1.55;color:${colors.text2}}
  .bio b{display:block;color:${colors.text};font-weight:500;margin-bottom:2px}
  .more{font:500 24px/1.2 'Cormorant Garamond',Georgia,serif;margin:36px 0 12px}
  .rel{display:flex;gap:12px;align-items:center;text-decoration:none;color:${colors.text};background:${colors.surface};border:1px solid ${colors.border};border-radius:18px;padding:10px;margin-bottom:10px}
  .rel img,.rel-ph{width:76px;height:58px;border-radius:12px;object-fit:cover;flex:none;background:${colors.goldLight}}
  .rel b{display:block;font-weight:500;font-size:15px;line-height:1.35}
  .rel small{color:${colors.text3};font-size:12px}
  .tick{color:${colors.sage};font-size:.9em;margin-left:4px;vertical-align:-1px}
  .care{background:${colors.sageLight};color:${colors.text};border-radius:18px;padding:14px 16px;margin-top:28px;font-size:14px;line-height:1.6}
</style>
</head><body>
  ${topic ? `<div class="topic">${esc(topic)}</div>` : ''}
  <h1>${esc(post.title)}</h1>
  ${post.subtitle ? `<p class="sub">${esc(post.subtitle)}</p>` : ''}
  <div class="by"><span class="av">${avatar}</span><span><b>${esc(post.author.name)}${post.author.is_professional ? `<i class="ti ti-discount-check-filled tick" aria-label="${esc(t('m.b.html.verified'))}"></i>` : ''}</b>${post.author.title ? `${esc(post.author.title)} · ` : ''}${esc(articleDate(post.published_utc, language))} · ${esc(t('m.b.card.min_read', { minutes: post.reading_minutes }))}</span></div>
  ${post.cover ? `<figure><img src="${esc(post.cover)}" alt="">${post.cover_caption ? `<figcaption>${esc(post.cover_caption)}</figcaption>` : ''}</figure>` : ''}
  <div class="body">${post.html}</div>
  ${post.community?.disclaimer ? `<div class="care">${esc(post.community.disclaimer)}</div>` : ''}
  ${post.author.bio ? `<div class="bio"><span class="av">${avatar}</span><span><b>${esc(post.author.name)}</b>${esc(post.author.bio)}</span></div>` : ''}
  ${related ? `<div class="more">${esc(t('m.b.html.keep_reading'))}</div>${related}` : ''}
</body></html>`;
}
