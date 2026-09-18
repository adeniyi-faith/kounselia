import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from './App';
import './styles.css';

// window.KOUNSELIA and window.C are printed by inc/kounselia-chat-engine.php
// just before this bundle loads — same contract the old hand-written
// script relied on, so the PHP side needs no changes.
const rootEl = document.getElementById('kounselia-chat-root');
if (rootEl) {
  createRoot(rootEl).render(
    <StrictMode>
      <App config={window.KOUNSELIA} counselors={window.C} />
    </StrictMode>,
  );
}
