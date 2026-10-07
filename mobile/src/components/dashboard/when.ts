import { makeT } from '@kounselia/core';

// Session times, shown in the member's own time zone, in the member's language.
export function sessionWhen(iso: string | null, withYear = false, language?: string): string {
  if (!iso) return '';
  const d = new Date(iso);
  const day = d.toLocaleDateString(language ?? [], { weekday: 'short', month: 'short', day: 'numeric', ...(withYear ? { year: 'numeric' } : {}) });
  if (withYear) return day;
  const time = d.toLocaleTimeString(language ?? [], { hour: 'numeric', minute: '2-digit' });
  return makeT(language ?? 'en')('m.when.at', { day, time });
}
