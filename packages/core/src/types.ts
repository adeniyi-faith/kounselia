// Shapes shared by the web chat and the React Native app.
// Nothing in this file talks to the DOM or the browser.

export interface Counselor {
  name: string;
  spec: string;
  av: string; // avatar color class, e.g. "ic-rose"
  icon: string; // tabler icon name, e.g. "ti-heart"
  greeting?: string;
  voice?: boolean;
  voice_enabled?: boolean | number;
}

export type CounselorMap = Record<string, Counselor>;

// A counselor as the kounselia_get_counselors action sends it (used by
// the mobile app; the website reads window.C instead).
export interface CounselorSummary {
  slug: string;
  name: string;
  spec: string;
  desc: string;
  icon: string; // Tabler icon name without the "ti-" prefix, e.g. "heart"
  color: string; // colour name without the "ic-" prefix, e.g. "rose"
  voice_enabled: boolean;
}

export interface ChatMessage {
  id: string; // local id, stable for React list rendering
  sender: 'user' | 'ai';
  text: string;
  messageId?: number; // server-assigned id, needed for playback/feedback
  consulted?: string[]; // other counselors consulted for this reply, if any
  rating?: 'up' | 'down' | null; // the member's saved thumbs up/down, if any
  createdAt: number;
}

export interface SendMessageResult {
  reply?: string;
  messageId?: number;
  consulted?: string[];
  sessionId?: number;
  limitReached?: boolean;
  messagesRemaining?: number;
  dailyLimit?: boolean;
  errorMessage?: string;
  networkError?: boolean;
}

export interface HistoryMessage {
  sender: 'user' | 'bot';
  content: string;
  id?: number;
  rating?: 'up' | 'down' | null;
  sent_at?: string; // UTC, ISO 8601
}

export interface KounseliaConfig {
  ajaxUrl: string;
  // Website only: the security code the page is printed with. The mobile
  // app leaves this out and signs requests with `authToken` instead.
  nonce?: string;
  loggedIn: boolean;
  // 'app' marks requests as coming from the mobile app (see http.ts).
  client?: 'web' | 'app';
  authToken?: string | null;
  // App only: called when the server says the saved sign-in no longer
  // works (password changed elsewhere, signed out by an admin, …).
  onSignedOut?: () => void;
}

export interface AppUser {
  id: number;
  name: string;
  email: string;
}
