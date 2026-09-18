import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from './App';
import './tokens.css';
import './styles.css';
import type { CounselorMap } from './core/types';

// Dev-only preview so the chat screen can be looked at with `npm run dev`
// without a real WordPress site running behind it. Not part of the
// production build (see vite.config.ts — its only entry is main.tsx).
const counselors: CounselorMap = {
  serena: {
    name: 'Serena',
    spec: 'Emotional Healing',
    av: 'ic-rose',
    icon: 'ti-heart',
    greeting:
      'Hello. I am really glad you are here.\n\nThis is your space. There is no agenda, no clock, and nothing you say here will be judged.\n\nWhat has been sitting with you lately?',
  },
};

window.KOUNSELIA = { ajaxUrl: '/__dev-mock-ajax', nonce: 'dev', loggedIn: false };
window.C = counselors;
if (!window.location.hash) window.location.hash = '#serena';

// Fake the WordPress admin-ajax.php endpoint so the composer is testable
// end to end. Swap this out for a real backend URL if you want to test
// against the live site's PHP instead.
const realFetch = window.fetch.bind(window);
window.fetch = async (input, init) => {
  const url = typeof input === 'string' ? input : (input as Request).url;
  if (url === window.KOUNSELIA.ajaxUrl && init?.body) {
    const params = new URLSearchParams(init.body as string);
    if (params.get('action') === 'kounselia_chat') {
      await new Promise((r) => setTimeout(r, 700));
      return new Response(
        JSON.stringify({
          success: true,
          data: { reply: `(dev mock reply) You said: "${params.get('message')}"`, message_id: 1, session_id: 1 },
        }),
      );
    }
    return new Response(JSON.stringify({ success: false, data: {} }));
  }
  return realFetch(input, init);
};

const rootEl = document.getElementById('kounselia-chat-root')!;
createRoot(rootEl).render(
  <StrictMode>
    <App config={window.KOUNSELIA} counselors={window.C} />
  </StrictMode>,
);
