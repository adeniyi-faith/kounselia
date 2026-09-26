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
}

export interface AppUser {
  id: number;
  name: string;
  email: string;
}
