// Reading and Settings for the mobile app: the blog ("The Kounselia
// Journal"), past private journal entries, and the member's account
// (profile, photo, password, email preferences, memory profile).
// Server side: includes/app-content.php plus the website's own account,
// newsletter and memory actions.
import { callAction } from './dashboard';
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
  author: { name: string; avatar: string | null };
  published_utc: string | null;
  reading_minutes: number;
  tags: BlogTag[];
  url: string; // the article on the website, for sharing
}

export interface BlogPage {
  posts: BlogCard[];
  has_more: boolean;
  // First page only.
  title?: string;
  tagline?: string;
  tags?: BlogTag[];
}

export interface BlogPost extends BlogCard {
  subtitle: string | null;
  cover_caption: string | null;
  author: { name: string; avatar: string | null; bio: string };
  html: string; // the article body, cleaned by the server
  related: BlogCard[];
}

export const fetchBlog = (config: KounseliaConfig, options: { page?: number; tag?: string; q?: string } = {}) =>
  callAction<BlogPage>(config, 'kounselia_app_blog', { page: options.page ?? 1, tag: options.tag || undefined, q: options.q || undefined });

export const fetchBlogPost = (config: KounseliaConfig, slug: string) => callAction<BlogPost>(config, 'kounselia_app_blog_post', { slug });

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
  // Website pages; only the ones that exist are included.
  links: { privacy?: string; terms?: string; mission?: string; safety?: string; email: string };
}

export const fetchAccount = (config: KounseliaConfig) => callAction<Account>(config, 'kounselia_app_account');

export const updateName = (config: KounseliaConfig, name: string) => callAction<{ name: string }>(config, 'kounselia_update_profile', { name });

export const changePassword = (config: KounseliaConfig, currentPassword: string, newPassword: string) =>
  callAction<{ message: string }>(config, 'kounselia_update_password', { current_password: currentPassword, new_password: newPassword });

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
