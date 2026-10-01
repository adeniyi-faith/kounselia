// The professional's side of the mobile app: applying, their home (the
// website's pro-dashboard.php), profile and rate, documents, weekly
// availability, booked sessions, articles and earnings.
// Server side: includes/app-professional.php for reading and applying,
// and the website's own actions for every change.
import { failureMessage } from './appAuth';
import { callAction } from './dashboard';
import { postAction } from './http';
import type { AppUser, KounseliaConfig, ProfessionalStatus } from './types';

const call = callAction;

// ---- Applying ----------------------------------------------------------------

// A document picked on the phone, read as base64.
export interface PickedDocument {
  name: string;
  base64: string;
}

export interface ApplicationDetails {
  title: string;
  license_number: string;
  specialty: string;
  years_experience: string;
  bio: string;
  rate_amount: string;
}

export type ApplyResult =
  // `token` comes back only when the account was created in the same step.
  | { success: true; message: string; user: AppUser; token?: string }
  | { success: false; message: string };

/**
 * Sends an application, as the website's apply form does. Signed out,
 * `account` creates their account in the same step and the result carries
 * the new sign-in; signed in (a member who wants to also see clients), it
 * is left out.
 */
export async function applyAsProfessional(
  config: KounseliaConfig,
  details: ApplicationDetails,
  licenseDoc: PickedDocument,
  idDoc: PickedDocument | null,
  account?: { name: string; email: string; password: string; deviceName?: string },
): Promise<ApplyResult> {
  try {
    const json = await postAction(
      config,
      'kounselia_app_apply_professional',
      {
        ...details,
        license_doc_b64: licenseDoc.base64,
        license_doc_name: licenseDoc.name,
        id_doc_b64: idDoc?.base64,
        id_doc_name: idDoc?.name,
        name: account?.name,
        email: account?.email,
        password: account?.password,
        device_name: account?.deviceName,
      },
      // Documents can be several megabytes on a slow connection.
      180000,
    );
    if (json?.success && json.data?.user) {
      return { success: true, message: json.data.message, user: json.data.user, token: json.data.token };
    }
    return { success: false, message: json?.data?.message || 'Something went wrong. Please try again.' };
  } catch (error) {
    return { success: false, message: failureMessage(error) };
  }
}

// ---- Their home ----------------------------------------------------------------

export interface ProBooking {
  id: number;
  client_name: string;
  client_note: string | null;
  start_local: string; // site time, what reschedule expects
  start_utc: string | null;
  // When Join appears and disappears (10 minutes before, 15 after the end).
  join_opens_utc: string | null;
  join_closes_utc: string | null;
  joinable: boolean;
  series_id: number; // 0 unless it's part of a weekly series
  is_free: boolean;
  // "Zoom", "Google Meet"... when this session isn't in Kounselia's own room.
  video_provider: string | null;
  // A different link set just for this session, or ''.
  video_link: string;
}

export interface ProArticle {
  id: number;
  title: string;
  slug: string;
  cover: string | null;
  state: string; // draft, pending, changes, rejected, removed, live, live_pending, live_draft, scheduled
  state_label: string;
  live: boolean;
  in_review: boolean;
  date_utc: string | null;
  views: number;
  loves: number;
  comments: number;
  review_note: string | null; // the editor's note, when there is one to act on
  url: string;
}

export interface Payout {
  id: number;
  amount: number;
  status: string;
  status_label: string;
  date_utc: string | null;
  failure_reason: string | null;
}

export interface ProDashboard {
  user: { name: string; first_name: string; avatar: string | null };
  application: {
    id: number;
    status: ProfessionalStatus;
    title: string;
    specialty: string;
    years_experience: number | null;
    bio: string;
    rate_amount: number | null;
    free_sessions_per_client: number;
    all_free: boolean; // every session free (pro bono)
    license_number: string | null;
    rejection_reason: string | null;
    suspended_reason: string | null;
  };
  rating: { average: number; count: number };
  reviews: { rating: number; comment: string; client_name: string; date_utc: string | null }[];
  free_options: { value: number; label: string }[];
  rate_usd_hint: string | null;
  public_profile: { available: boolean; on: boolean; url: string | null };
  video: { shown: boolean; allowed: boolean; mode: 'kounselia' | 'own'; link: string };
  documents: { id: number; name: string; type_label: string }[];
  // Days without a window aren't listed. day: 0 = Sunday … 6 = Saturday.
  availability: { day: number; start: string; end: string }[];
  bookings: ProBooking[];
  session_minutes: number;
  articles: {
    enabled: boolean;
    show_tab: boolean;
    access: { allowed: boolean; mode: string; message: string };
    followers: number;
    totals: { views: number; loves: number; comments: number };
    items: ProArticle[];
  };
  earnings: {
    available: number;
    total_earned: number;
    paid_out: number;
    commission_percent: number;
    account: { account_name: string; bank_name: string; last4: string } | null;
    history: Payout[];
  };
}

export const fetchProDashboard = (config: KounseliaConfig) => call<ProDashboard>(config, 'kounselia_app_pro_dashboard');

// ---- Profile & rate ---------------------------------------------------------------

export interface ProProfileChanges {
  title: string;
  specialty: string;
  years_experience: string;
  bio: string;
  rate_amount: string;
  free_sessions_per_client: number;
}

export const saveProProfile = (config: KounseliaConfig, changes: ProProfileChanges) =>
  call<{ message: string }>(config, 'kounselia_update_professional_profile', { ...changes });

export const setPublicProfile = (config: KounseliaConfig, show: boolean) =>
  call<{ message: string }>(config, 'kounselia_set_public_profile', { show: show ? '1' : '0' });

// Kounselia's own video room, or their own Zoom / Meet / Teams / Whereby link.
export const saveVideoSetting = (config: KounseliaConfig, mode: 'kounselia' | 'own', link: string) =>
  call<{ message: string }>(config, 'kounselia_pro_video_settings', { mode, link });

// A different link for one session; '' goes back to their usual setting.
export const setSessionVideoLink = (config: KounseliaConfig, bookingId: number, link: string) =>
  call<{ message: string }>(config, 'kounselia_pro_booking_video_link', { booking_id: bookingId, link });

// ---- Documents ---------------------------------------------------------------------

export type DocumentType = 'certificate' | 'id' | 'license' | 'other';

export const uploadProDocument = (config: KounseliaConfig, docType: DocumentType, doc: PickedDocument) =>
  call<{ message: string; id: number }>(config, 'kounselia_app_upload_professional_document', {
    doc_type: docType,
    document_b64: doc.base64,
    document_name: doc.name,
  });

export const deleteProDocument = (config: KounseliaConfig, docId: number) =>
  call<{ message: string }>(config, 'kounselia_delete_professional_document', { doc_id: docId });

// A link good for one viewing in the next two minutes.
export const fetchProDocumentLink = (config: KounseliaConfig, docId: number) =>
  call<{ url: string }>(config, 'kounselia_app_professional_document_link', { doc_id: docId });

// ---- Availability -------------------------------------------------------------------

export const saveAvailability = (config: KounseliaConfig, rules: { day: number; start: string; end: string }[]) =>
  call<{ message: string }>(config, 'kounselia_save_availability', { rules: JSON.stringify(rules) });

// ---- Articles -----------------------------------------------------------------------

// Takes it back from review, or unpublishes it (back to drafts).
export const withdrawArticle = (config: KounseliaConfig, id: number) =>
  call<{ message: string }>(config, 'kounselia_pro_article_withdraw', { id });

export const deleteArticle = (config: KounseliaConfig, id: number) =>
  call<{ message: string }>(config, 'kounselia_pro_article_delete', { id });

// ---- Earnings -----------------------------------------------------------------------

export const fetchPayoutBanks = (config: KounseliaConfig) =>
  call<{ banks: { code: string; name: string }[] }>(config, 'kounselia_get_payout_banks');

// Paystack checks the account and the name on it before it's saved.
export const savePayoutAccount = (config: KounseliaConfig, bankCode: string, bankName: string, accountNumber: string) =>
  call<{ message: string; account_name: string }>(config, 'kounselia_save_payout_account', {
    bank_code: bankCode,
    bank_name: bankName,
    account_number: accountNumber,
  });

// Sends the whole available balance.
export const requestPayout = (config: KounseliaConfig) => call<{ message: string }>(config, 'kounselia_request_payout');

// ---- Notifications (the bell) ----------------------------------------------------------

export interface AppNotification {
  id: number;
  title: string;
  body: string;
  url: string;
  created_at: string; // site time
  unread: boolean;
}

// Opening the list marks everything in it read, as on the website.
export const fetchNotifications = (config: KounseliaConfig) =>
  call<{ notifications: AppNotification[] }>(config, 'kounselia_get_notifications');

export const fetchUnreadNotificationCount = (config: KounseliaConfig) =>
  call<{ count: number }>(config, 'kounselia_get_unread_notification_count');
