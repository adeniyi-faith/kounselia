import type { ChatMessage, Counselor, Translate } from '@kounselia/core';

// Triggers a browser file download, so this stays out of core/ (a
// React Native build would save the transcript a different way).
export function exportChatAsFile(messages: ChatMessage[], counselor: Counselor, t: Translate) {
  if (messages.length === 0) return false;

  let text = `${t('c.export.title')}\n\n`;
  for (const m of messages) {
    const sender = m.sender === 'ai' ? counselor.name : t('c.export.me');
    text += `${sender}:\n${m.text}\n\n`;
  }

  const blob = new Blob([text], { type: 'text/plain' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `Session_Export_${counselor.name}.txt`;
  a.click();
  URL.revokeObjectURL(url);
  return true;
}
