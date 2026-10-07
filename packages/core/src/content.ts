// Reading and Settings for the mobile app: the blog ("The Kounselia
// Journal"), past private journal entries, and the member's account
// (profile, photo, password, email preferences, memory profile).
// Server side: includes/app-content.php plus the website's own account,
// newsletter and memory actions.
import { callAction } from './dashboard';
import { deviceLanguage } from './i18n';
import type { AppUser, KounseliaConfig } from './types';

// ---- Blog -----------------------------------------------------------------

export interface BlogTag {
  name: string;
  slug: string;
}

export interface BlogCard {
  id: number;
  slug: string;
  title: string;
  summary: string;
  cover: string | null;
  author: BlogAuthor;
  published_utc: string | null;
  reading_minutes: number;
  tags: BlogTag[];
  url: string; // the article on the website, for sharing
  love_count: number;
  comment_count: number;
}

export interface BlogAuthor {
  name: string;
  avatar: string | null;
  // Set when a verified professional wrote it (not the Kounselia team).
  is_professional: boolean;
  professional_id: number | null;
  title: string | null; // e.g. "Clinical Psychologist"
}

// Which article the list is narrowed to, besides topic and search.
export type BlogFrom = '' | 'professionals' | 'following';

export interface BlogPage {
  posts: BlogCard[];
  has_more: boolean;
  // First page only.
  title?: string;
  tagline?: string;
  tags?: BlogTag[];
  // First page only: the extra filters worth offering. `professionals` is
  // the label to show, or null when no professional has written yet.
  filters?: { professionals: string | null; following: boolean };
}

// What a reader can do around an article (includes/app-content.php).
export interface PostCommunity {
  loves_on: boolean;
  loved: boolean;
  love_count: number;
  comments_on: boolean;
  comment_count: number;
  follows_on: boolean;
  following: boolean;
  followers: number;
  book_pro_id: number | null; // the author can be booked from the article
  disclaimer: string | null; // the care note shown under professionals' articles
}

export interface BlogPost extends BlogCard {
  subtitle: string | null;
  cover_caption: string | null;
  author: BlogAuthor & { bio: string };
  html: string; // the article body, cleaned by the server
  related: BlogCard[];
  community: PostCommunity;
}

export const fetchBlog = (config: KounseliaConfig, options: { page?: number; tag?: string; q?: string; from?: BlogFrom } = {}) =>
  callAction<BlogPage>(config, 'kounselia_app_blog', {
    page: options.page ?? 1,
    tag: options.tag || undefined,
    q: options.q || undefined,
    from: options.from || undefined,
  });

export const fetchBlogPost = (config: KounseliaConfig, slug: string) => callAction<BlogPost>(config, 'kounselia_app_blog_post', { slug });

// ---- Community: follow, love, comments (includes/community.php) --------------

export const setFollowing = (config: KounseliaConfig, professionalId: number, follow: boolean) =>
  callAction<{ following: boolean; followers: number }>(config, 'kounselia_follow', { professional_id: professionalId, follow: follow ? 1 : 0 });

export const setPostLove = (config: KounseliaConfig, postId: number, love: boolean) =>
  callAction<{ loved: boolean; count: number }>(config, 'kounselia_post_love', { post_id: postId, love: love ? 1 : 0 });

export const setCommentLove = (config: KounseliaConfig, commentId: number, love: boolean) =>
  callAction<{ loved: boolean; count: number }>(config, 'kounselia_comment_love', { comment_id: commentId, love: love ? 1 : 0 });

export interface CommentAuthor {
  name: string; // a first name or chosen nickname, never a full name
  initial: string;
  avatar: string | null; // only ever the article's author (a professional)
  is_author: boolean;
  profile_url: string | null;
}

export interface Comment {
  id: number;
  parent_id: number | null;
  author: CommentAuthor;
  content: string;
  status: 'visible' | 'pending' | 'hidden' | 'removed';
  held: boolean; // waiting for review; only its writer sees it
  pinned: boolean;
  love_count: number;
  loved: boolean;
  is_mine: boolean;
  created_utc: string | null;
  time_label: string;
  replies: Comment[];
}

export interface CommunityIdentity {
  mode: '' | 'first_name' | 'nickname'; // '' until they choose
  nickname: string;
  first_name: string;
  name: string;
}

export interface CommentViewer {
  signed_in: boolean;
  can_comment: boolean;
  // Why not, when they can't: 'need_identity' means choose a name first.
  reason: string | null;
  message: string | null;
  identity: CommunityIdentity | null;
  can_moderate: boolean; // the article's author (pin/hide) or an editor
  max_length: number;
  held_first: boolean; // comments wait for a moderator before showing
}

export interface CommentsPage {
  comments: Comment[];
  has_more: boolean;
  total: number;
  enabled: boolean;
  viewer: CommentViewer;
}

export const fetchComments = (config: KounseliaConfig, postId: number, page = 1) =>
  callAction<CommentsPage>(config, 'kounselia_comments', { post_id: postId, page });

export interface PostedComment {
  comment: Comment;
  held: boolean;
  // The crisis check matched: show `message` and a way to get help now.
  safety: boolean;
  support_url: string | null;
  message: string;
  count: number;
}

export const addComment = (config: KounseliaConfig, postId: number, content: string, parentId?: number) =>
  callAction<PostedComment>(config, 'kounselia_comment_add', { post_id: postId, content, parent_id: parentId || undefined });

// Both send back the article's new comment count (null if it's gone).
export const deleteComment = (config: KounseliaConfig, commentId: number) =>
  callAction<{ message: string; count: number | null }>(config, 'kounselia_comment_delete', { comment_id: commentId });

export type ModerateAction = 'hide' | 'pin' | 'unpin';

export const moderateComment = (config: KounseliaConfig, commentId: number, act: ModerateAction) =>
  callAction<{ message: string; count: number | null }>(config, 'kounselia_comment_moderate', { comment_id: commentId, act });

export const REPORT_REASONS: { key: string; label: string }[] = [
  { key: 'unkind', label: 'Unkind or bullying' },
  { key: 'harmful', label: 'Harmful or dangerous advice' },
  { key: 'spam', label: 'Spam or advertising' },
  { key: 'private_info', label: "Shares someone's private information" },
  { key: 'other', label: 'Something else' },
];

export const reportComment = (config: KounseliaConfig, commentId: number, reason: string, note = '') =>
  callAction<{ message: string }>(config, 'kounselia_comment_report', { comment_id: commentId, reason, note });

export const saveCommunityIdentity = (config: KounseliaConfig, mode: 'first_name' | 'nickname', nickname = '') =>
  callAction<CommunityIdentity>(config, 'kounselia_community_identity', { mode, nickname });

// ---- Private journal --------------------------------------------------------

export interface JournalEntry {
  date: string; // YYYY-MM-DD, the member's day as the site counts days
  content: string;
  is_today: boolean;
}

export const fetchJournalEntries = (config: KounseliaConfig, page = 1) =>
  callAction<{ entries: JournalEntry[]; has_more: boolean }>(config, 'kounselia_get_journal_entries', { page });

// ---- Account (Settings) -------------------------------------------------------

export interface MemoryProfile {
  identity: string;
  career: string;
  goals: string[];
  values: string[];
  habits: string[];
  triggers: string[];
}

export interface Account {
  user: AppUser & { avatar: string | null; member_since: string };
  plan: { is_pro: boolean; state: string; title: string; detail: string };
  emails: { newsletter: boolean; blog: boolean };
  memory: MemoryProfile | null;
  language: string; // 'en', 'fr', … the language the member chose (their phone's until they do)
  // Website pages; only the ones that exist are included.
  links: { privacy?: string; terms?: string; mission?: string; safety?: string; email: string };
}

export const fetchAccount = (config: KounseliaConfig) => callAction<Account>(config, 'kounselia_app_account', { language: deviceLanguage() });

// Saves the member's language. Their counselors, growth plans and reminders
// then write in it; screens follow as they are translated.
export const setLanguage = (config: KounseliaConfig, language: string) =>
  callAction<{ language: string; rtl: boolean; message: string }>(config, 'kounselia_set_language', { language });

// ---- Plan (upgrade / manage subscription) ----------------------------------

export interface Plan {
  id: string;
  name: string;
  price: string; // already formatted in the viewer's currency, e.g. "$4.99"
  interval: 'month' | 'year';
  features: string[];
  is_popular: boolean;
}

export const fetchPlans = (config: KounseliaConfig) => callAction<{ plans: Plan[] }>(config, 'kounselia_app_plans');

// Starts Paystack checkout for a plan and returns the page to pay on. Always
// asks for the redirect page (never the pop-up), since a pop-up needs
// Paystack's own script running on a page we don't control here.
export const startSubscriptionPayment = (config: KounseliaConfig, planId: string) =>
  callAction<{ authorization_url: string }>(config, 'kounselia_init_subscription_payment', { plan_id: planId, redirect: 1 });

// Stops auto-renew; access continues until the period already paid for ends.
export const cancelSubscription = (config: KounseliaConfig) => callAction<{ message: string }>(config, 'kounselia_cancel_subscription');

// Turns auto-renew back on, using the card already saved.
export const resumeSubscription = (config: KounseliaConfig) => callAction<{ message: string }>(config, 'kounselia_resume_subscription');

export const updateName = (config: KounseliaConfig, name: string) => callAction<{ name: string }>(config, 'kounselia_update_profile', { name });

export const changePassword = (config: KounseliaConfig, currentPassword: string, newPassword: string) =>
  callAction<{ message: string }>(config, 'kounselia_update_password', { current_password: currentPassword, new_password: newPassword });

// Permanently deletes the member's account and everything private in it
// (includes/account-deletion.php). Asks for the password again. Can be
// refused with an explanation, e.g. while a session with a professional
// is still booked.
export const deleteAccount = (config: KounseliaConfig, password: string) =>
  callAction<{ message: string }>(config, 'kounselia_delete_account', { password });

export const uploadAvatar = (config: KounseliaConfig, imageBase64: string, mimeType: string) =>
  callAction<{ avatar: string | null }>(config, 'kounselia_app_upload_avatar', { image_b64: imageBase64, mime_type: mimeType });

export const saveEmailPrefs = (config: KounseliaConfig, prefs: { newsletter: boolean; blog: boolean }) =>
  callAction<{ message: string }>(config, 'kounselia_save_email_prefs', { newsletter: prefs.newsletter ? '1' : '0', blog: prefs.blog ? '1' : '0' });

// Lists are sent comma-separated, as the website's edit form does.
export const saveMemory = (config: KounseliaConfig, memory: MemoryProfile) =>
  callAction<unknown>(config, 'kounselia_edit_memory', {
    identity: memory.identity,
    career: memory.career,
    goals: memory.goals.join(', '),
    values: memory.values.join(', '),
    habits: memory.habits.join(', '),
    triggers: memory.triggers.join(', '),
  });

export const deleteMemory = (config: KounseliaConfig) => callAction<unknown>(config, 'kounselia_delete_memory');

// Builds the memory profile from what another AI (ChatGPT, Gemini) knows
// about them. Takes a while: the server asks an AI to read it.
export const importMemory = (config: KounseliaConfig, text: string) => callAction<unknown>(config, 'kounselia_import_memory', { memory_text: text });
