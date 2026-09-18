// Shapes shared by the web chat and, later, the React Native app.
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
}

export interface HistoryMessage {
  sender: 'user' | 'ai';
  content: string;
  id?: number;
}

export interface KounseliaConfig {
  ajaxUrl: string;
  nonce: string;
  loggedIn: boolean;
}

declare global {
  interface Window {
    KOUNSELIA: KounseliaConfig;
    C: CounselorMap;
    kounseliaMountChat?: () => void;
    __kounseliaDeferMount?: boolean;
  }
}
