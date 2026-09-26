// "14:05" for today, "Mon 14:05" within the last week, "12 Sep, 14:05" before that.
export function formatTime(ts: number): string {
  const d = new Date(ts);
  const time = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  const now = new Date();
  if (d.toDateString() === now.toDateString()) return time;
  const days = (now.getTime() - ts) / 86400000;
  if (days < 6) return `${d.toLocaleDateString([], { weekday: 'short' })} ${time}`;
  return `${d.toLocaleDateString([], { day: 'numeric', month: 'short' })}, ${time}`;
}
