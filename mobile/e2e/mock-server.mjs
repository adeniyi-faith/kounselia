// A stand-in for the Kounselia server, used only by the Android check
// (.github/workflows/android-check.yml). The app on the emulator talks to
// this instead of kounselia.com, so every signed-in screen can be opened
// with made-up data and without a real account.
//
//   node e2e/mock-server.mjs            (listens on port 8787)
import { createServer } from 'node:http';

const PORT = Number(process.env.PORT || 8787);

const iso = (ms) => new Date(Date.now() + ms).toISOString().replace(/\.\d+Z$/, 'Z');
const day = (daysAgo) => new Date(Date.now() - daysAgo * 864e5).toISOString().slice(0, 10);

const counselors = [
  ['serena', 'Madam Serena', 'Emotional Healing', 'heart', 'rose', false],
  ['marcus', 'Marcus', 'Career and Purpose', 'briefcase', 'blue', false],
  ['noa', 'Noa', 'Personal Growth', 'leaf', 'sage', false],
  ['eli', 'Eli', 'Relationships', 'users', 'gold', false],
  ['dr_lena', 'Dr. Lena', 'Trauma and PTSD', 'stethoscope', 'teal', true],
  ['james', 'Dr. James', "Men's Mental Health", 'shield', 'navy', true],
  ['theo', 'Theo', 'Grief and Loss', 'candle', 'plum', false],
  ['priya', 'Priya', 'Burnout and Balance', 'battery-charging', 'sienna', false],
].map(([slug, name, spec, icon, color, voice]) => ({ slug, name, spec, desc: '', icon, color, voice_enabled: voice }));

const moodOptions = [
  ['calm', 'Calm', 'mood-smile', 'sage'],
  ['okay', 'Okay', 'mood-neutral', 'blue'],
  ['anxious', 'Anxious', 'mood-confuzed', 'gold'],
  ['low', 'Low', 'mood-sad', 'plum'],
  ['overwhelmed', 'Overwhelmed', 'cloud-storm', 'sienna'],
].map(([key, label, icon, color]) => ({ key, label, icon, color }));

const posts = [
  { id: 1, slug: 'sleep-mind-races', title: 'How to sleep when your mind races', summary: 'Small changes that make nights easier.', cover: null, author: { name: 'Kounselia Team', avatar: null, is_professional: false, professional_id: null, title: null }, published_utc: iso(-3 * 864e5), reading_minutes: 6, tags: [{ name: 'Sleep', slug: 'sleep' }], url: 'https://kounselia.com/blog/sleep-mind-races', love_count: 12, comment_count: 2 },
  { id: 2, slug: 'grief-waves', title: 'Grief comes in waves', summary: 'What to expect in the first year.', cover: null, author: { name: 'Dr. Amaka Eze', avatar: null, is_professional: true, professional_id: 3, title: 'Clinical Psychologist' }, published_utc: iso(-9 * 864e5), reading_minutes: 8, tags: [{ name: 'Grief', slug: 'grief' }], url: 'https://kounselia.com/blog/grief-waves', love_count: 4, comment_count: 0 },
];

// The conversation under an article (includes/community.php).
let nextCommentId = 100;
let identity = { mode: '', nickname: '', first_name: 'Samson', name: 'Samson' };
const commentsByPost = {
  1: [
    { id: 21, parent_id: null, author: { name: 'Quiet River', initial: 'Q', avatar: null, is_author: false, profile_url: null }, content: 'The tip about morning light really helped me.', status: 'visible', held: false, pinned: true, love_count: 3, loved: false, is_mine: false, created_utc: iso(-7200e3), time_label: '2 hours ago',
      replies: [{ id: 22, parent_id: 21, author: { name: 'Kounselia Team', initial: 'K', avatar: null, is_author: true, profile_url: null }, content: 'So glad to hear it.', status: 'visible', held: false, pinned: false, love_count: 1, loved: false, is_mine: false, created_utc: iso(-3600e3), time_label: '1 hour ago', replies: [] }] },
  ],
  2: [],
};
const viewer = () => ({ signed_in: true, can_comment: !!identity.mode, reason: identity.mode ? null : 'need_identity', message: identity.mode ? null : 'Choose how your name is shown first.', identity, can_moderate: false, max_length: 1500, held_first: false });
const community = (post) => ({ loves_on: true, loved: false, love_count: post.love_count, comments_on: true, comment_count: post.comment_count, follows_on: post.author.is_professional, following: false, followers: 18, book_pro_id: post.author.professional_id, disclaimer: post.author.is_professional ? 'This article shares general information. It is not a substitute for care from someone who knows your situation.' : null });

let todayMood = 'anxious';
let journal = '';
let memory = null;

const account = () => ({
  user: { id: 7, name: 'Samson', email: 'samson@example.com', avatar: null, member_since: 'September 2026' },
  plan: { is_pro: false, state: 'free', title: 'Free', detail: 'Free forever. Upgrade any time for more time with your counselors.' },
  emails: { newsletter: true, blog: true },
  memory,
  links: { privacy: 'https://kounselia.com/page/privacy-policy', mission: 'https://kounselia.com/page/our-mission', safety: 'https://kounselia.com/page/safety-resources', email: 'hello@kounselia.com' },
});

const history = [
  { id: 11, sender: 'user', content: 'Hi', rating: null, sent_at: iso(-60e3) },
  { id: 12, sender: 'bot', content: "Hey Samson, good to meet you. I'm really glad you reached out today.\n\nWhat's been going on in your world lately?", rating: null, sent_at: iso(-30e3) },
];

const slotsLocal = [];
const slotsUtc = [];
for (const d of [1, 2, 3]) {
  for (const h of [9, 10, 14, 15]) {
    const t = new Date();
    t.setDate(t.getDate() + d);
    t.setHours(h, 0, 0, 0);
    slotsLocal.push(`L${d}-${h}`);
    slotsUtc.push(t.toISOString().replace(/\.\d+Z$/, 'Z'));
  }
}

function answer(p) {
  const ok = (data) => ({ success: true, data });
  const err = (message) => ({ success: false, data: { message } });
  switch (p.action) {
    case 'kounselia_app_login':
    case 'kounselia_app_register':
      return ok({ token: 'e2e-token', user: { id: 7, name: 'Samson', email: 'samson@example.com' } });
    case 'kounselia_app_me':
      return ok({ user: { id: 7, name: 'Samson', email: 'samson@example.com' } });
    case 'kounselia_get_counselors':
      return ok({ counselors });
    case 'kounselia_app_home':
      return ok({
        checkin: null,
        care: { next: null, professionals: [{ id: 5, name: 'Dr. Amaka Eze', avatar_url: null }, { id: 6, name: 'Tunde Bello', avatar_url: null }], discount_percent: 0 },
        mood: { options: moodOptions, today: todayMood, week: [6, 5, 4, 3, 2, 1, 0].map((o) => ({ date: day(o), mood: o === 0 ? todayMood : null })) },
        stats: { conversations: 1, messages_this_week: 1, counselors_met: 1 },
        recommended: { slug: 'serena', reason: "Matched to how you said you're feeling today." },
        journal,
      });
    case 'kounselia_save_mood':
      todayMood = p.mood;
      return ok({ mood: todayMood });
    case 'kounselia_save_journal':
      journal = p.content ?? '';
      return ok({});
    case 'kounselia_get_sessions':
      return ok({ sessions: [{ id: 3, counselor_slug: 'eli', message_count: 2, last_at: iso(-3 * 3600e3) }, { id: 2, counselor_slug: 'marcus', message_count: 1, last_at: iso(-2 * 864e5) }] });
    case 'kounselia_get_history':
      return ok({ session_id: 5, messages: p.counselor === 'eli' ? history : [] });
    case 'kounselia_chat':
      return ok({ reply: 'That sounds like a lot to carry.\n\nWhat has been the hardest part?', message_id: 13, session_id: 5, consulted: [] });
    case 'kounselia_app_bookings':
      return ok({
        session_minutes: 50,
        upcoming: [{ id: 42, professional_id: 6, pro_name: 'Tunde Bello', pro_title: 'Counsellor', start_local: 'x', start_utc: iso(3 * 864e5), series_id: 0, joinable: false }],
        past: [{ id: 30, pro_name: 'Dr. Amaka Eze', pro_title: 'Clinical Psychologist', start_utc: iso(-14 * 864e5), review_rating: 5 }],
        professionals: [
          { id: 5, name: 'Dr. Amaka Eze', title: 'Clinical Psychologist', specialty: 'Anxiety', avatar_url: null, rating: 4.9, review_count: 23, price: '₦18,000', full_price: '₦20,000' },
          { id: 6, name: 'Tunde Bello', title: 'Counsellor', specialty: 'Relationships', avatar_url: null, rating: 0, review_count: 0, price: '₦12,000', full_price: null },
        ],
      });
    case 'kounselia_get_professional_slots':
      return ok({ slots: slotsLocal, slots_utc: slotsUtc, session_minutes: 50 });
    case 'kounselia_get_booking_messages':
      return ok({ messages: [] });
    case 'kounselia_app_blog':
      return ok({
        posts: p.from === 'professionals' ? posts.filter((x) => x.author.is_professional) : p.from === 'following' ? [] : posts,
        has_more: false,
        ...(p.page === '1' ? { title: 'The Kounselia Journal', tagline: 'Honest writing on feelings, relationships, work and healing.', tags: [{ name: 'Sleep', slug: 'sleep' }, { name: 'Grief', slug: 'grief' }], filters: { professionals: 'From our professionals', following: true } } : {}),
      });
    case 'kounselia_app_blog_post': {
      const post = posts.find((x) => x.slug === p.slug);
      if (!post) return err("That article isn't available any more.");
      return ok({
        ...post,
        subtitle: post.summary,
        cover_caption: null,
        author: { ...post.author, bio: 'Writes about rest and recovery.' },
        html: '<p>Most of us know the feeling of lying awake while the day replays itself.</p><h2>Start with light</h2><p>Morning light sets your body clock. <a href="https://example.org/study">Read the study</a>.</p><blockquote>Rest is not a reward.</blockquote><ul><li>Same wake time</li><li>No phone in bed</li></ul>',
        related: posts.filter((x) => x.slug !== post.slug),
        community: community(post),
      });
    }
    case 'kounselia_comments':
      return ok({ comments: commentsByPost[p.post_id] ?? [], has_more: false, total: (commentsByPost[p.post_id] ?? []).length, enabled: true, viewer: viewer() });
    case 'kounselia_community_identity':
      identity = p.mode === 'nickname' ? { ...identity, mode: 'nickname', nickname: p.nickname, name: p.nickname } : { ...identity, mode: 'first_name', name: identity.first_name };
      return ok(identity);
    case 'kounselia_comment_add': {
      const c = { id: nextCommentId++, parent_id: p.parent_id ? Number(p.parent_id) : null, author: { name: identity.name, initial: identity.name.charAt(0), avatar: null, is_author: false, profile_url: null }, content: p.content, status: 'visible', held: false, pinned: false, love_count: 0, loved: false, is_mine: true, created_utc: iso(0), time_label: '0 min ago', replies: [] };
      const list = (commentsByPost[p.post_id] ??= []);
      if (c.parent_id) list.find((x) => x.id === c.parent_id)?.replies.push(c);
      else list.unshift(c);
      return ok({ comment: c, held: false, safety: false, support_url: null, message: 'Posted.', count: list.length });
    }
    case 'kounselia_post_love':
      return ok({ loved: p.love === '1', count: p.love === '1' ? 13 : 12 });
    case 'kounselia_comment_love':
      return ok({ loved: p.love === '1', count: p.love === '1' ? 4 : 3 });
    case 'kounselia_follow':
      return ok({ following: p.follow === '1', followers: p.follow === '1' ? 19 : 18 });
    case 'kounselia_comment_report':
      return ok({ message: 'Thank you. Our team will take a look.' });
    case 'kounselia_get_journal_entries':
      return ok({ entries: [{ date: day(0), content: journal || 'Today so far.', is_today: true }, { date: day(1), content: 'Talked to Serena about work. Felt lighter afterwards.', is_today: false }], has_more: false });
    case 'kounselia_app_account':
      return ok(account());
    case 'kounselia_import_memory':
      memory = { identity: 'A nurse in Lagos', career: 'Night shifts at a hospital', goals: ['Sleep better'], values: ['Family'], habits: [], triggers: ['Crowds'] };
      return ok({ summary: {} });
    case 'kounselia_voice_token':
      return err("Voice isn't available right now.");
    default:
      return ok({});
  }
}

createServer((req, res) => {
  let body = '';
  req.on('data', (chunk) => (body += chunk));
  req.on('end', () => {
    const params = Object.fromEntries(new URLSearchParams(body));
    const reply = answer(params);
    console.log(`${new Date().toISOString()} ${params.action ?? req.url} -> ${reply.success ? 'ok' : 'error'}`);
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(reply));
  });
}).listen(PORT, '0.0.0.0', () => console.log(`Mock Kounselia server on port ${PORT}`));
