// The member dashboard for the mobile app: mood, journal, recent
// conversations, and sessions with professionals (booking, paying,
// joining, messaging, rescheduling, cancelling, rating).
// Server side: includes/app-dashboard.php plus the existing mood,
// journal, bookings, booking-series and reviews actions.
import { failureMessage } from './appAuth';
import { postAction } from './http';
import type { KounseliaConfig } from './types';

// Every call here resolves to one of these, never throws: `offline` for a
// dropped connection, `error` with the server's own explanation otherwise.
export type Result<T> = { ok: true; data: T } | { ok: false; offline?: boolean; signedOut?: boolean; message: string };


export async function callAction<T>(config: KounseliaConfig, action: string, params: Record<string, string | number | undefined> = {}): Promise<Result<T>> {
  try {
    const json = await postAction(config, action, params);
    if (json?.success) return { ok: true, data: (json.data ?? {}) as T };
    return {
      ok: false,
      signedOut: !!json?.data?.signed_out,
      message: json?.data?.message || 'Something went wrong. Please try again.',
    };
  } catch (error) {
    // `offline` means "no real answer came back" — a dropped connection or
    // an error page from the web host — so screens retry rather than
    // treating it as the server's decision.
    return { ok: false, offline: true, message: failureMessage(error) };
  }
}

const call = callAction;

// ---- Home -----------------------------------------------------------------

export interface MoodOption {
  key: string;
  label: string;
  icon: string; // Tabler icon name, no "ti-"
  color: string; // counselor colour name, no "ic-"
}

export interface CheckIn {
  id: number;
  event_text: string; // what they mentioned, e.g. "Your job interview"
  counselor_slug: string; // who asks
}

export interface CareTeam {
  // Their next session with a professional, if one is booked.
  next: {
    id: number;
    pro_name: string;
    pro_title: string;
    start_utc: string | null;
    joinable: boolean;
    more_booked: number;
  } | null;
  // With nothing booked: up to three professionals to show.
  professionals: { name: string; avatar_url: string | null }[];
  discount_percent: number; // Pro members' discount on sessions
}

export interface HomeData {
  checkin: CheckIn | null;
  growth: GrowthPlanSummary | null; // their running growth plan, if any
  care: CareTeam;
  mood: { options: MoodOption[]; today: string | null; week: { date: string; mood: string | null }[] };
  stats: { conversations: number; messages_this_week: number; counselors_met: number };
  recommended: { slug: string; reason: string };
  journal: string;
}

export const fetchHome = (config: KounseliaConfig) => call<HomeData>(config, 'kounselia_app_home');
export const saveMood = (config: KounseliaConfig, mood: string) => call<{ mood: string }>(config, 'kounselia_save_mood', { mood });
export const saveJournal = (config: KounseliaConfig, content: string) => call<unknown>(config, 'kounselia_save_journal', { content });

// Opening a check-in: the counselor's first line ("Hi Ada — before we
// start, you mentioned your job interview. How did it go?"). Marks it done.
export const fetchCheckinQuestion = (config: KounseliaConfig, checkinId: number) =>
  call<{ question: string }>(config, 'kounselia_get_checkin', { checkin_id: checkinId });

export const dismissCheckin = (config: KounseliaConfig, checkinId: number) =>
  call<unknown>(config, 'kounselia_dismiss_checkin', { checkin_id: checkinId });

// ---- Growth plans (Personal Development) -----------------------------------
// A 30 day plan of one small task a day. Server side: includes/growth-plans.php.

export interface GrowthDay {
  day: number;
  title: string;
  task: string;
  minutes: number;
  done: boolean;
  state: 'past' | 'today' | 'upcoming';
}

export interface GrowthPlanSummary {
  id: number;
  area: string;
  area_label: string;
  icon: string; // Tabler icon name, no "ti-"
  title: string;
  summary: string;
  status: string;
  start_date: string;
  current_day: number;
  total_days: number;
  done_count: number;
  streak: number;
  today: GrowthDay | null;
  counselor_slug: string; // who to talk to about the plan
}

export interface GrowthPlan extends GrowthPlanSummary {
  days: GrowthDay[];
}

export interface GrowthQuestion {
  key: string;
  type: 'text' | 'choice';
  required: boolean;
  label: string;
  hint: string;
  choices?: { key: string; label: string }[];
}

export interface GrowthArea {
  key: string;
  label: string;
  icon: string;
  blurb: string;
  questions: GrowthQuestion[];
}

export interface GrowthOverview {
  plan: GrowthPlan | null;
  previous: { id: number; title: string; area_label: string; status: string; start_date: string; done_count: number; total_days: number }[];
  areas: GrowthArea[];
  // limit 0 = unlimited (remaining is then null)
  allowance: { limit: number; used: number; remaining: number | null };
  is_pro: boolean;
}

export const fetchGrowth = (config: KounseliaConfig) => call<GrowthOverview>(config, 'kounselia_growth_get');

// The AI takes a little while to write the plan; the default timeout already allows for it.
export const createGrowthPlan = (config: KounseliaConfig, area: string, answers: Record<string, string>) =>
  call<GrowthOverview>(config, 'kounselia_growth_create', { area, answers: JSON.stringify(answers) });

export const markGrowthDay = (config: KounseliaConfig, planId: number, day: number, done: boolean) =>
  call<{ plan: GrowthPlan | null }>(config, 'kounselia_growth_mark_day', { plan_id: planId, day, done: done ? 1 : 0 });

export const endGrowthPlan = (config: KounseliaConfig, planId: number) =>
  call<GrowthOverview>(config, 'kounselia_growth_end', { plan_id: planId });

// ---- Conversations ----------------------------------------------------------

export interface SessionSummary {
  id: number;
  counselor_slug: string;
  message_count: number;
  last_at: string | null; // UTC
}

export const fetchSessions = (config: KounseliaConfig) => call<{ sessions: SessionSummary[] }>(config, 'kounselia_get_sessions');

// ---- Sessions with professionals ------------------------------------------

export interface UpcomingBooking {
  id: number;
  professional_id: number;
  pro_name: string;
  pro_title: string;
  start_local: string; // site time, what reschedule/cancel expect
  start_utc: string | null;
  series_id: number; // 0 unless it's part of a weekly series
  joinable: boolean;
}

export interface PastBooking {
  id: number;
  pro_name: string;
  pro_title: string;
  start_utc: string | null;
  review_rating: number | null;
}

export interface Professional {
  id: number;
  name: string;
  title: string;
  specialty: string;
  // A few sentences about them, in their own words, if they've written one.
  bio: string | null;
  avatar_url: string | null;
  rating: number;
  review_count: number;
  price: string | null;
  full_price: string | null;
  // The professional's free-session offer for this member, e.g. "Your next
  // session is free". null when they have nothing free left.
  free_label: string | null;
  // "Zoom", "Google Meet", "Microsoft Teams" or "Whereby" when their sessions
  // happen there instead of Kounselia's own video room (joined from Kounselia).
  video_provider: string | null;
}

export interface BookingsData {
  upcoming: UpcomingBooking[];
  past: PastBooking[];
  professionals: Professional[];
  session_minutes: number;
}

export const fetchBookings = (config: KounseliaConfig) => call<BookingsData>(config, 'kounselia_app_bookings');

export interface Slots {
  slots: string[]; // site time — send one of these back when booking
  slots_utc: (string | null)[];
  session_minutes: number;
  // Set when today is one of the professional's working days but every
  // slot in it has already been ruled out by the booking lead time —
  // otherwise today would just silently disappear from the day picker.
  today_note: string | null;
}

export const fetchSlots = (config: KounseliaConfig, professionalId: number, rescheduleBookingId?: number) =>
  call<Slots>(config, 'kounselia_get_professional_slots', {
    professional_id: professionalId,
    reschedule_booking_id: rescheduleBookingId,
  });

// What clients said about a professional: the star average, how many
// ratings, and the latest written reviews (anonymous: no names).
export interface ProfessionalReviews {
  average: number; // 0 when nobody has rated them yet
  count: number;
  reviews: { rating: number; comment: string; date_utc: string | null }[];
}

export const fetchProfessionalReviews = (config: KounseliaConfig, professionalId: number) =>
  call<ProfessionalReviews>(config, 'kounselia_app_professional_reviews', { professional_id: professionalId });

// Reserves the slot and returns the Paystack page to pay on. The booking
// is only confirmed once Paystack tells the server the payment went through.
// A free session (the professional's offer) is confirmed at once instead:
// `free` is true and there is no payment page.
export type CreatedBooking =
  | { free?: false; authorization_url: string; booking_id: number }
  | { free: true; booking_id: number; message: string };

export const createBooking = (config: KounseliaConfig, professionalId: number, slot: string, note: string, weekly: boolean) =>
  call<CreatedBooking>(config, 'kounselia_create_booking', {
    professional_id: professionalId,
    scheduled_start: slot,
    note,
    make_recurring: weekly ? 1 : undefined,
  });

// "confirmed" once Paystack has told the server the payment went through;
// "pending_payment" until then.
export const fetchBookingStatus = (config: KounseliaConfig, bookingId: number) =>
  call<{ status: string }>(config, 'kounselia_get_booking_status', { booking_id: bookingId });

export const rescheduleBooking = (config: KounseliaConfig, bookingId: number, slot: string) =>
  call<{ message: string }>(config, 'kounselia_reschedule_booking', { booking_id: bookingId, scheduled_start: slot });

export const cancelBooking = (config: KounseliaConfig, bookingId: number, reason = '') =>
  call<{ message: string }>(config, 'kounselia_cancel_booking', { booking_id: bookingId, reason });

export const cancelSeries = (config: KounseliaConfig, seriesId: number, reason = '') =>
  call<{ message: string }>(config, 'kounselia_cancel_series', { series_id: seriesId, reason });

// The way into a booked session, only for the two people on it and only
// at session time. `external` is true when the professional holds their
// sessions on their own Zoom / Meet / Teams / Whereby link.
export const fetchBookingRoom = (config: KounseliaConfig, bookingId: number) =>
  call<{ url: string; external: boolean; provider: string }>(config, 'kounselia_get_booking_room', { booking_id: bookingId });

export const submitReview = (config: KounseliaConfig, bookingId: number, rating: number, comment: string) =>
  call<{ message: string }>(config, 'kounselia_submit_review', { booking_id: bookingId, rating, comment });

export interface BookingMessage {
  id: number;
  content: string;
  sent_at: string | null; // UTC
  sender_name: string;
  is_mine: boolean;
}

export const fetchBookingMessages = (config: KounseliaConfig, bookingId: number) =>
  call<{ messages: BookingMessage[] }>(config, 'kounselia_get_booking_messages', { booking_id: bookingId });

export const sendBookingMessage = (config: KounseliaConfig, bookingId: number, content: string) =>
  call<{ message_id: number }>(config, 'kounselia_send_booking_message', { booking_id: bookingId, content });
