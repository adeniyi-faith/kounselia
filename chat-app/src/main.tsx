import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from './App';
import './styles.css';

// window.KOUNSELIA and window.C are printed by inc/kounselia-chat-engine.php
// just before this bundle loads — same contract the old hand-written
// script relied on, so the PHP side needs no changes for that part.
function mount() {
  const rootEl = document.getElementById('kounselia-chat-root');
  if (!rootEl) return;
  createRoot(rootEl).render(
    <StrictMode>
      <App config={window.KOUNSELIA} counselors={window.C} />
    </StrictMode>,
  );
}

// window.kounseliaMountChat lets a page do its own setup (e.g. talk.php
// swapping in a personalized greeting fetched over AJAX) before the chat
// UI reads window.C and renders. A page opts into that by setting
// window.__kounseliaDeferMount = true in a <script> BEFORE this bundle
// loads, then calling window.kounseliaMountChat() itself once ready.
// Any page that doesn't set the flag gets the old plug-and-play behavior:
// mounts immediately, no extra wiring required.
window.kounseliaMountChat = mount;
if (!window.__kounseliaDeferMount) mount();
