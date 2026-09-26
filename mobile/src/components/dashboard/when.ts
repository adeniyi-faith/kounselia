// Session times, shown in the member's own time zone.
export function sessionWhen(iso: string | null, withYear = false): string {
  if (!iso) return '';
  const d = new Date(iso);
  const day = d.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric', ...(withYear ? { year: 'numeric' } : {}) });
  if (withYear) return day;
  return `${day} — ${d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}`;
}
