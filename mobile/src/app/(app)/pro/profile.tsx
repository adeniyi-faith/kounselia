import {
  deleteProDocument,
  fetchProDocumentLink,
  saveProProfile,
  saveVideoSetting,
  setPublicProfile,
  updateName,
  uploadAvatar,
  uploadProDocument,
  type DocumentType,
  type ProDashboard,
} from '@kounselia/core';
import * as Haptics from 'expo-haptics';
import { Image } from 'expo-image';
import { ImageManipulator, SaveFormat } from 'expo-image-manipulator';
import * as ImagePicker from 'expo-image-picker';
import * as Linking from 'expo-linking';
import { router } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, KeyboardAvoidingView, Platform, Pressable, RefreshControl, ScrollView, Switch, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { withoutLocking } from '@/appLockSetting';
import { useBrowser } from '@/browser/BrowserProvider';
import { Button } from '@/components/Button';
import { Toast, useToast } from '@/components/chat/Toast';
import { sessionWhen } from '@/components/dashboard/when';
import { showDialog } from '@/components/Dialog';
import { FormMessage } from '@/components/FormMessage';
import { ListSkeleton } from '@/components/Skeleton';
import { TablerIcon } from '@/components/TablerIcon';
import { TextField } from '@/components/TextField';
import { ChoiceSheet, Panel, SelectField } from '@/components/pro/ui';
import { pickDocumentFile, pickDocumentPhoto, type PickResult } from '@/professional/documents';
import { usePro } from '@/professional/ProDashboard';
import { useSession } from '@/session';
import { fonts, makeStyles, radius, shadows, useColors } from '@/theme';

const DOC_TYPES: { value: DocumentType; label: string }[] = [
  { value: 'certificate', label: 'Certificate' },
  { value: 'id', label: 'Government ID' },
  { value: 'license', label: 'License / credential' },
  { value: 'other', label: 'Other' },
];

// Profile and rate (the website's pro dashboard "Profile & Rate"): photo,
// what clients see and the rate, free sessions, where video sessions
// happen, documents, reviews, being listed publicly, and the account.
export default function ProProfile() {
  const styles = useStyles();
  const colors = useColors();
  const { data, failed, reload } = usePro();
  const { setViewMode, signOut } = useSession();
  const [refreshing, setRefreshing] = useState(false);
  const toast = useToast();

  async function refresh() {
    setRefreshing(true);
    await reload();
    setRefreshing(false);
  }

  function confirmSignOut() {
    showDialog({
      title: 'Sign out?',
      message: 'You can sign back in any time with your email and password.',
      icon: 'logout',
      buttons: [
        { text: 'Cancel', style: 'cancel' },
        { text: 'Sign out', style: 'destructive', onPress: () => signOut() },
      ],
    });
  }

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView
          contentContainerStyle={styles.content}
          keyboardShouldPersistTaps="handled"
          keyboardDismissMode="interactive"
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.accentText} />}
        >
          <Text style={styles.title} accessibilityRole="header">
            Profile and rate
          </Text>
          <Text style={styles.sub}>This is what clients see once you’re verified</Text>
          {!data ? (
            failed ? (
              <View style={styles.center}>
                <Text style={styles.notice}>We couldn’t load your profile. Please check your internet connection.</Text>
                <Button title="Try again" variant="ghost" onPress={refresh} busy={refreshing} />
              </View>
            ) : (
              <View style={{ marginTop: 16 }}>
                <ListSkeleton rows={4} />
              </View>
            )
          ) : (
            <>
              <Photo data={data} toast={toast.show} />
              <ProfileForm key={data.application.id + data.application.title + data.application.rate_amount} data={data} toast={toast.show} reload={reload} />
              {data.video.shown && <VideoSetting key={data.video.mode + data.video.link} video={data.video} toast={toast.show} reload={reload} />}
              <Documents data={data} toast={toast.show} reload={reload} />
              <Reviews data={data} />
              {data.public_profile.available && <PublicToggle key={String(data.public_profile.on)} data={data} />}
              <Panel title="Account">
                <AccountRow icon="settings" label="Account settings" sub="Password, app lock, notifications, appearance" onPress={() => router.push('/account')} />
                <AccountRow icon="switch-horizontal" label="Switch to client view" sub="Use Kounselia for your own wellbeing" onPress={() => setViewMode('client')} />
                <AccountRow icon="logout" label="Sign out" onPress={confirmSignOut} last />
              </Panel>
            </>
          )}
        </ScrollView>
      </KeyboardAvoidingView>
      <Toast note={toast.note} />
    </SafeAreaView>
  );
}

function AccountRow({ icon, label, sub, onPress, last }: { icon: string; label: string; sub?: string; onPress: () => void; last?: boolean }) {
  const styles = useStyles();
  const colors = useColors();
  return (
    <Pressable onPress={onPress} accessibilityRole="button" style={[styles.accountRow, !last && styles.accountRowBorder]}>
      <TablerIcon name={icon} size={19} color={colors.accentText} />
      <View style={{ flex: 1 }}>
        <Text style={styles.accountLabel}>{label}</Text>
        {sub ? <Text style={styles.accountSub}>{sub}</Text> : null}
      </View>
      <TablerIcon name="chevron-right" size={18} color={colors.text3} />
    </Pressable>
  );
}

// ---- Photo --------------------------------------------------------------------

function Photo({ data, toast }: { data: ProDashboard; toast: (t: string) => void }) {
  const styles = useStyles();
  const { config } = useSession();
  const [avatar, setAvatar] = useState(data.user.avatar);
  const [busy, setBusy] = useState(false);

  async function change() {
    const pick = await withoutLocking(() => ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], allowsEditing: true, aspect: [1, 1], quality: 1 }));
    if (pick.canceled || !pick.assets[0]) return;
    setBusy(true);
    try {
      const image = await ImageManipulator.manipulate(pick.assets[0].uri).resize({ width: 512 }).renderAsync();
      const saved = await image.saveAsync({ compress: 0.82, format: SaveFormat.JPEG, base64: true });
      const res = await uploadAvatar(config, saved.base64 ?? '', 'image/jpeg');
      if (!res.ok) {
        toast(res.message);
        return;
      }
      setAvatar(res.data.avatar);
      Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => undefined);
      toast('Photo updated');
    } catch {
      toast("Couldn't use that photo. Please try another one.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <View style={styles.photoCard}>
      <Pressable onPress={change} disabled={busy} accessibilityRole="button" accessibilityLabel="Change profile photo" style={styles.avatarWrap}>
        <View style={styles.avatar}>
          {avatar ? (
            <Image source={{ uri: avatar }} style={styles.avatarImg} contentFit="cover" transition={200} />
          ) : (
            <Text style={styles.avatarText}>{data.user.name.slice(0, 1).toUpperCase()}</Text>
          )}
          {busy && (
            <View style={styles.avatarBusy}>
              <ActivityIndicator color="#fff" />
            </View>
          )}
        </View>
        <View style={styles.cameraBadge}>
          <TablerIcon name="camera" size={14} color="#fff" />
        </View>
      </Pressable>
      <Text style={styles.photoName}>{data.user.name}</Text>
      <Text style={styles.photoNote}>Clients see this photo on your public profile and everywhere else you appear on Kounselia.</Text>
    </View>
  );
}

// ---- Profile and rate -------------------------------------------------------------

function ProfileForm({ data, toast, reload }: { data: ProDashboard; toast: (t: string) => void; reload: () => Promise<void> }) {
  const styles = useStyles();
  const { config, user, updateUser } = useSession();
  const app = data.application;
  const [name, setName] = useState(data.user.name);
  const [title, setTitle] = useState(app.title);
  const [specialty, setSpecialty] = useState(app.specialty);
  const [years, setYears] = useState(app.years_experience ? String(app.years_experience) : '');
  const [bio, setBio] = useState(app.bio);
  const [rate, setRate] = useState(app.rate_amount ? String(app.rate_amount) : '');
  const [free, setFree] = useState(app.free_sessions_per_client);
  const [choosingFree, setChoosingFree] = useState(false);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ tone: 'error' | 'success'; text: string } | null>(null);

  async function save() {
    setBusy(true);
    setMessage(null);
    const res = await saveProProfile(config, {
      title: title.trim(),
      specialty: specialty.trim(),
      years_experience: years.trim(),
      bio: bio.trim(),
      rate_amount: rate.trim(),
      free_sessions_per_client: free,
    });
    if (!res.ok) {
      setBusy(false);
      setMessage({ tone: 'error', text: res.message });
      return;
    }
    if (name.trim() && name.trim() !== data.user.name) {
      const named = await updateName(config, name.trim());
      if (named.ok) {
        if (user) updateUser({ name: named.data.name || name.trim() });
      } else {
        setBusy(false);
        setMessage({ tone: 'error', text: named.message });
        return;
      }
    }
    setBusy(false);
    Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => undefined);
    setMessage({ tone: 'success', text: res.data.message || 'Profile updated.' });
    toast('Profile updated');
    reload();
  }

  const freeLabel = data.free_options.find((o) => o.value === free)?.label ?? null;
  return (
    <Panel>
      {app.license_number ? (
        <View style={styles.readonly}>
          <Text style={styles.readonlyText}>
            Verified against license/registration <Text style={{ fontFamily: fonts.semibold }}>{app.license_number}</Text>. To change your credentials, contact
            support. That needs re-verification.
          </Text>
        </View>
      ) : null}
      <TextField label="Full name" value={name} onChangeText={setName} placeholder="Your full name, as clients should see it" autoComplete="name" />
      <TextField label="Professional title" value={title} onChangeText={setTitle} placeholder="e.g. Licensed Clinical Psychologist" />
      <Text style={styles.fieldHint}>Shown next to your name everywhere clients see you: your role or qualification, not just “Dr”.</Text>
      <TextField label="Specialty" value={specialty} onChangeText={setSpecialty} placeholder="e.g. Anxiety, trauma, relationships" />
      <TextField label="Years of experience" value={years} onChangeText={(t) => setYears(t.replace(/\D/g, '').slice(0, 2))} keyboardType="number-pad" placeholder="e.g. 8" />
      <TextField label="Bio" value={bio} onChangeText={setBio} multiline textAlignVertical="top" style={styles.bio} placeholder="A few sentences clients will see on your profile." />
      <TextField label="Your rate per session (₦)" value={rate} onChangeText={(t) => setRate(t.replace(/[^\d.]/g, ''))} keyboardType="decimal-pad" placeholder="e.g. 15000" />
      {data.rate_usd_hint ? <Text style={styles.fieldHint}>{data.rate_usd_hint}</Text> : null}
      {data.free_options.length > 0 && (
        <>
          <SelectField label="Free sessions" value={freeLabel} onPress={() => setChoosingFree(true)} />
          <Text style={styles.fieldHint}>
            A free first session is a gentle way for someone to see if you’re the right fit. Each client gets the free sessions once; after that they pay your rate.
            Choose “every session” if you offer your time pro bono (your rate isn’t needed then).
          </Text>
          <ChoiceSheet
            visible={choosingFree}
            title="Free sessions"
            choices={data.free_options}
            selected={free}
            onPick={setFree}
            onClose={() => setChoosingFree(false)}
          />
        </>
      )}
      {message ? <FormMessage tone={message.tone} text={message.text} /> : null}
      <Button title="Save changes" onPress={save} busy={busy} />
    </Panel>
  );
}

// ---- Video calls --------------------------------------------------------------------

function VideoSetting({ video, toast, reload }: { video: ProDashboard['video']; toast: (t: string) => void; reload: () => Promise<void> }) {
  const styles = useStyles();
  const colors = useColors();
  const { config } = useSession();
  const [mode, setMode] = useState(video.mode);
  const [link, setLink] = useState(video.link);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ tone: 'error' | 'success'; text: string } | null>(null);

  async function save() {
    setBusy(true);
    setMessage(null);
    const res = await saveVideoSetting(config, mode, link.trim());
    setBusy(false);
    if (!res.ok) {
      setMessage({ tone: 'error', text: res.message });
      return;
    }
    setMessage({ tone: 'success', text: res.data.message });
    toast('Video setting saved');
    reload();
  }

  if (!video.allowed) {
    return (
      <Panel title="Video calls" note="Where your sessions happen">
        <Text style={styles.body}>
          Your sessions use Kounselia’s private video room: nothing to install, and it opens right from the app. Prefer to use your own Zoom, Google Meet, Teams or
          Whereby link? Email hello@kounselia.com and we’ll switch it on for you.
        </Text>
      </Panel>
    );
  }
  const options: { key: 'kounselia' | 'own'; label: string }[] = [
    { key: 'kounselia', label: 'Kounselia’s private video room (recommended)' },
    { key: 'own', label: 'My own meeting link' },
  ];
  return (
    <Panel title="Video calls" note="Where your sessions happen">
      {options.map((o) => {
        const on = mode === o.key;
        return (
          <Pressable key={o.key} onPress={() => setMode(o.key)} accessibilityRole="radio" accessibilityState={{ checked: on }} style={styles.radioRow}>
            <View style={[styles.radio, on && { borderColor: colors.accent }]}>{on && <View style={styles.radioDot} />}</View>
            <Text style={styles.radioText}>{o.label}</Text>
          </Pressable>
        );
      })}
      {mode === 'own' && (
        <>
          <TextField label="Meeting link" value={link} onChangeText={setLink} placeholder="https://zoom.us/j/… or https://meet.google.com/…" keyboardType="url" autoCapitalize="none" autoCorrect={false} />
          <Text style={styles.fieldHint}>
            Zoom, Google Meet, Microsoft Teams or Whereby. Clients only get it by pressing Join on Kounselia at session time; it’s never emailed. Please turn on a
            waiting room or passcode, or set a separate link for each session (from Bookings).
          </Text>
        </>
      )}
      <Text style={styles.fieldHint}>Bookings, payments and messages always stay on Kounselia. Kounselia’s safety checks only cover what’s said on Kounselia.</Text>
      {message ? <FormMessage tone={message.tone} text={message.text} /> : null}
      <Button title="Save video setting" onPress={save} busy={busy} />
    </Panel>
  );
}

// ---- Documents --------------------------------------------------------------------------

function Documents({ data, toast, reload }: { data: ProDashboard; toast: (t: string) => void; reload: () => Promise<void> }) {
  const styles = useStyles();
  const colors = useColors();
  const { config } = useSession();
  const [docType, setDocType] = useState<DocumentType>('certificate');
  const [choosingType, setChoosingType] = useState(false);
  const [busy, setBusy] = useState(false);
  const [opening, setOpening] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function add(pick: () => Promise<PickResult>) {
    setError(null);
    // Let the question box finish closing first: a phone won't open its
    // file picker over another pop-up.
    await new Promise((resolve) => setTimeout(resolve, 350));
    const picked = await pick();
    if (!picked) return;
    if (!picked.ok) {
      setError(picked.message);
      return;
    }
    setBusy(true);
    const res = await uploadProDocument(config, docType, picked.doc);
    setBusy(false);
    if (!res.ok) {
      setError(res.message);
      return;
    }
    Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => undefined);
    toast('Document added');
    reload();
  }

  function choose() {
    showDialog({
      title: 'Add a document',
      message: 'PDF, JPG, or PNG, up to 8MB.',
      icon: 'file-text',
      buttons: [
        { text: 'Choose a file', onPress: () => add(pickDocumentFile) },
        { text: 'Choose a photo', onPress: () => add(pickDocumentPhoto) },
        { text: 'Cancel', style: 'cancel' },
      ],
    });
  }

  async function view(id: number) {
    setOpening(id);
    const res = await fetchProDocumentLink(config, id);
    setOpening(null);
    if (!res.ok) {
      toast(res.message);
      return;
    }
    // The phone's own viewer handles PDFs, which the in-app browser can't on Android.
    Linking.openURL(res.data.url).catch(() => toast("Couldn't open that document."));
  }

  function remove(id: number, name: string) {
    showDialog({
      title: 'Remove this document?',
      message: name,
      icon: 'trash',
      buttons: [
        { text: 'Keep it', style: 'cancel' },
        {
          text: 'Remove',
          style: 'destructive',
          onPress: async () => {
            const res = await deleteProDocument(config, id);
            toast(res.ok ? res.data.message || 'Document removed.' : res.message);
            reload();
          },
        },
      ],
    });
  }

  return (
    <Panel title="Documents" note="Up to 10 files. Add a certificate or a second ID any time.">
      {data.documents.length === 0 ? <Text style={styles.body}>No documents on file yet.</Text> : null}
      {data.documents.map((d) => (
        <View key={d.id} style={styles.doc}>
          <TablerIcon name="file-text" size={18} color={colors.accentText} />
          <View style={{ flex: 1, minWidth: 0 }}>
            <Text style={styles.docName} numberOfLines={1}>
              {d.name}
            </Text>
            <Text style={styles.docType}>{d.type_label}</Text>
          </View>
          <Pressable onPress={() => view(d.id)} accessibilityRole="button" accessibilityLabel={`View ${d.name}`} hitSlop={8}>
            {opening === d.id ? <ActivityIndicator size="small" color={colors.accentText} /> : <Text style={styles.docView}>View</Text>}
          </Pressable>
          <Pressable onPress={() => remove(d.id, d.name)} accessibilityRole="button" accessibilityLabel={`Remove ${d.name}`} hitSlop={8}>
            <TablerIcon name="trash" size={18} color={colors.text3} />
          </Pressable>
        </View>
      ))}
      <View style={{ height: 12 }} />
      <SelectField label="Document type" value={DOC_TYPES.find((t) => t.value === docType)?.label ?? null} onPress={() => setChoosingType(true)} />
      {error ? <FormMessage tone="error" text={error} /> : null}
      <Button title="Add document" onPress={choose} busy={busy} disabled={data.documents.length >= 10} />
      <ChoiceSheet visible={choosingType} title="Document type" choices={DOC_TYPES} selected={docType} onPick={setDocType} onClose={() => setChoosingType(false)} />
    </Panel>
  );
}

// ---- Reviews -----------------------------------------------------------------------------

function Reviews({ data }: { data: ProDashboard }) {
  const styles = useStyles();
  const { rating, reviews } = data;
  return (
    <Panel
      title="Client reviews"
      note={
        rating.count > 0
          ? `★ ${rating.average.toFixed(1)} average across ${rating.count} review${rating.count === 1 ? '' : 's'}`
          : 'Clients can rate a session once it’s happened. Reviews will show up here, and your average rating appears on your public profile.'
      }
    >
      {reviews.map((r, i) => (
        <View key={i} style={styles.review}>
          <Text style={styles.reviewStars} accessibilityLabel={`${r.rating} out of 5`}>
            {'★'.repeat(r.rating)}
            {'☆'.repeat(5 - r.rating)} <Text style={styles.reviewName}>{r.client_name}</Text>
          </Text>
          {r.comment ? <Text style={styles.body}>{r.comment}</Text> : null}
          <Text style={styles.docType}>{sessionWhen(r.date_utc, true)}</Text>
        </View>
      ))}
    </Panel>
  );
}

// ---- Public listing ------------------------------------------------------------------------

function PublicToggle({ data }: { data: ProDashboard }) {
  const styles = useStyles();
  const colors = useColors();
  const { config } = useSession();
  const { openInApp } = useBrowser();
  const [on, setOn] = useState(data.public_profile.on);
  const [message, setMessage] = useState<{ tone: 'error' | 'success'; text: string } | null>(null);

  async function toggle(next: boolean) {
    setOn(next);
    const res = await setPublicProfile(config, next);
    if (!res.ok) setOn(!next);
    setMessage({ tone: res.ok ? 'success' : 'error', text: res.ok ? res.data.message : res.message });
  }

  return (
    <Panel>
      <View style={styles.toggleRow}>
        <View style={{ flex: 1 }}>
          <Text style={styles.toggleTitle}>Show my profile on the public website</Text>
          <Text style={styles.toggleText}>
            Your name, photo, title, specialty, experience, bio, rate and reviews appear in the public directory at kounselia.com/professionals once you’re verified,
            so new clients can find you. Your licence number is never shown, and reviews never show your clients’ names. Turn this off any time; signed-in members can
            still book you.
          </Text>
        </View>
        <Switch value={on} onValueChange={toggle} trackColor={{ true: colors.accent, false: colors.surface3 }} accessibilityLabel="Show my profile on the public website" />
      </View>
      {data.public_profile.url ? (
        <Pressable onPress={() => openInApp(data.public_profile.url!, { title: 'Your public profile', web: true })} accessibilityRole="link" style={{ marginTop: 10 }}>
          <Text style={styles.link}>View my public profile →</Text>
        </Pressable>
      ) : null}
      {message ? (
        <View style={{ marginTop: 12 }}>
          <FormMessage tone={message.tone} text={message.text} />
        </View>
      ) : null}
    </Panel>
  );
}

const useStyles = makeStyles((colors) => ({
  safe: { flex: 1, backgroundColor: colors.bg },
  content: { padding: 16, paddingBottom: 48 },
  title: { fontFamily: fonts.serifMedium, fontSize: 28, color: colors.text, marginTop: 8 },
  sub: { fontFamily: fonts.regular, fontSize: 13, color: colors.text3, marginTop: 2 },
  center: { marginTop: 24, gap: 16 },
  notice: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 22, color: colors.text2, textAlign: 'center' },
  body: { fontFamily: fonts.regular, fontSize: 14, lineHeight: 21, color: colors.text2 },
  photoCard: { alignItems: 'center', backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderRadius: radius.r, padding: 22, marginTop: 16, ...shadows.soft },
  avatarWrap: { width: 104, height: 104, marginBottom: 14 },
  avatar: { width: 104, height: 104, borderRadius: 52, backgroundColor: colors.accentLight, alignItems: 'center', justifyContent: 'center', overflow: 'hidden' },
  avatarImg: { width: '100%', height: '100%' },
  avatarText: { fontFamily: fonts.semibold, fontSize: 38, color: colors.accentText },
  avatarBusy: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, backgroundColor: 'rgba(0,0,0,0.35)', alignItems: 'center', justifyContent: 'center' },
  cameraBadge: {
    position: 'absolute',
    right: 0,
    bottom: 0,
    width: 32,
    height: 32,
    borderRadius: 16,
    backgroundColor: colors.accent,
    borderWidth: 3,
    borderColor: colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
  },
  photoName: { fontFamily: fonts.semibold, fontSize: 15, color: colors.text },
  photoNote: { fontFamily: fonts.regular, fontSize: 12.5, lineHeight: 18, color: colors.text3, textAlign: 'center', marginTop: 4 },
  readonly: { padding: 12, borderRadius: 10, backgroundColor: colors.surface2, marginBottom: 18 },
  readonlyText: { fontFamily: fonts.regular, fontSize: 12.5, lineHeight: 18, color: colors.text3 },
  fieldHint: { fontFamily: fonts.regular, fontSize: 12.5, lineHeight: 18, color: colors.text3, marginTop: -10, marginBottom: 18 },
  bio: { minHeight: 110 },
  radioRow: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 10 },
  radio: { width: 22, height: 22, borderRadius: 11, borderWidth: 2, borderColor: colors.border, alignItems: 'center', justifyContent: 'center' },
  radioDot: { width: 10, height: 10, borderRadius: 5, backgroundColor: colors.accent },
  radioText: { flex: 1, fontFamily: fonts.regular, fontSize: 14.5, color: colors.text },
  doc: { flexDirection: 'row', alignItems: 'center', gap: 12, padding: 12, borderRadius: 12, backgroundColor: colors.bg, borderWidth: 1, borderColor: colors.border, marginBottom: 8 },
  docName: { fontFamily: fonts.semibold, fontSize: 13.5, color: colors.text },
  docType: { fontFamily: fonts.regular, fontSize: 12, color: colors.text3, marginTop: 2 },
  docView: { fontFamily: fonts.semibold, fontSize: 13, color: colors.accentText },
  review: { paddingVertical: 12, borderTopWidth: 1, borderTopColor: colors.border, gap: 4 },
  reviewStars: { fontSize: 14, color: colors.gold, letterSpacing: 1 },
  reviewName: { fontFamily: fonts.semibold, fontSize: 13.5, color: colors.text, letterSpacing: 0 },
  toggleRow: { flexDirection: 'row', gap: 14, alignItems: 'flex-start' },
  toggleTitle: { fontFamily: fonts.semibold, fontSize: 15, color: colors.text, marginBottom: 4 },
  toggleText: { fontFamily: fonts.regular, fontSize: 12.5, lineHeight: 18, color: colors.text3 },
  link: { fontFamily: fonts.medium, fontSize: 14, color: colors.accentText },
  accountRow: { flexDirection: 'row', alignItems: 'center', gap: 14, paddingVertical: 14 },
  accountRowBorder: { borderBottomWidth: 1, borderBottomColor: colors.border },
  accountLabel: { fontFamily: fonts.medium, fontSize: 15, color: colors.text },
  accountSub: { fontFamily: fonts.regular, fontSize: 12.5, color: colors.text3, marginTop: 2 },
}));
