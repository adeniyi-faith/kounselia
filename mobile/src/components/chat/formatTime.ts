// "14:05" for today, "Mon 14:05" within the last week, "12 Sep, 14:05" before that.
export function formatTime(ts: number, language?: string): string {
  const locale = language ?? [];
  const d = new Date(ts);
  const time = d.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' });
  const now = new Date();
  if (d.toDateString() === now.toDateString()) return time;
  const days = (now.getTime() - ts) / 86400000;
  if (days < 6) return `${d.toLocaleDateString(locale, { weekday: 'short' })} ${time}`;
  return `${d.toLocaleDateString(locale, { day: 'numeric', month: 'short' })}, ${time}`;
}
