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
  type Translate,
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
import { useLanguage } from '@/language';
import { useSession } from '@/session';
import { fonts, makeStyles, radius, shadows, useColors } from '@/theme';

const DOC_TYPES: { value: DocumentType; label: string }[] = [ // label: a translation key
  { value: 'certificate', label: 'm.pro.doc_certificate' },
  { value: 'id', label: 'm.pro.doc_id' },
  { value: 'license', label: 'm.pro.doc_license' },
  { value: 'other', label: 'm.pro.doc_other' },
];

// Profile and rate (the website's pro dashboard "Profile & Rate"): photo,
// what clients see and the rate, free sessions, where video sessions
// happen, documents, reviews, being listed publicly, and the account.
export default function ProProfile() {
  const styles = useStyles();
  const { t } = useLanguage();
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
      title: t('m.pro.sign_out_q'),
      message: t('m.pro.sign_out_body'),
      icon: 'logout',
      buttons: [
        { text: t('m.pro.cancel'), style: 'cancel' },
        { text: t('m.pro.sign_out'), style: 'destructive', onPress: () => signOut() },
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
            {t('m.pro.profile_title')}
          </Text>
          <Text style={styles.sub}>{t('m.pro.profile_sub')}</Text>
          {!data ? (
            failed ? (
              <View style={styles.center}>
                <Text style={styles.notice}>{t('m.pro.profile_load_failed')}</Text>
                <Button title={t('m.pro.try_again')} variant="ghost" onPress={refresh} busy={refreshing} />
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
              <Panel title={t('m.settings.g_account')}>
                <AccountRow icon="settings" label={t('m.settings.account_settings')} sub={t('m.pro.account_settings_sub')} onPress={() => router.push('/account')} />
                <AccountRow icon="switch-horizontal" label={t('m.pro.switch_client')} sub={t('m.pro.switch_client_sub')} onPress={() => setViewMode('client')} />
                <AccountRow icon="logout" label={t('m.pro.sign_out')} onPress={confirmSignOut} last />
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
  const { t } = useLanguage();
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
      toast(t('m.pro.photo_updated'));
    } catch {
      toast(t('m.pro.doc_photo_unusable'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <View style={styles.photoCard}>
      <Pressable onPress={change} disabled={busy} accessibilityRole="button" accessibilityLabel={t('m.pro.change_photo')} style={styles.avatarWrap}>
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
      <Text style={styles.photoNote}>{t('m.pro.photo_note')}</Text>
    </View>
  );
}

// ---- Profile and rate -------------------------------------------------------------

function ProfileForm({ data, toast, reload }: { data: ProDashboard; toast: (t: string) => void; reload: () => Promise<void> }) {
  const styles = useStyles();
  const { t } = useLanguage();
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
    setMessage({ tone: 'success', text: res.data.message || t('m.pro.profile_updated') });
    toast(t('m.pro.profile_updated'));
    reload();
  }

  const freeLabel = data.free_options.find((o) => o.value === free)?.label ?? null;
  return (
    <Panel>
      {app.license_number ? (
        <View style={styles.readonly}>
          <Text style={styles.readonlyText}>
            {t('m.pro.license_verified_before')} <Text style={{ fontFamily: fonts.semibold }}>{app.license_number}</Text>. {t('m.pro.license_verified_after')}
          </Text>
        </View>
      ) : null}
      <TextField label={t('m.pro.apply_full_name')} value={name} onChangeText={setName} placeholder={t('m.pro.full_name_hint')} autoComplete="name" />
      <TextField label={t('m.pro.f_title')} value={title} onChangeText={setTitle} placeholder={t('m.pro.f_title_hint')} />
      <Text style={styles.fieldHint}>{t('m.pro.title_hint_long')}</Text>
      <TextField label={t('m.pro.f_specialty')} value={specialty} onChangeText={setSpecialty} placeholder={t('m.pro.f_specialty_hint')} />
      <TextField label={t('m.pro.f_years')} value={years} onChangeText={(v) => setYears(v.replace(/\D/g, '').slice(0, 2))} keyboardType="number-pad" placeholder={t('m.pro.f_years_hint')} />
      <TextField label={t('m.pro.bio')} value={bio} onChangeText={setBio} multiline textAlignVertical="top" style={styles.bio} placeholder={t('m.pro.f_bio_hint')} />
      <TextField label={t('m.pro.f_rate')} value={rate} onChangeText={(v) => setRate(v.replace(/[^\d.]/g, ''))} keyboardType="decimal-pad" placeholder={t('m.pro.f_rate_hint')} />
      {data.rate_usd_hint ? <Text style={styles.fieldHint}>{data.rate_usd_hint}</Text> : null}
      {data.free_options.length > 0 && (
        <>
          <SelectField label={t('m.pro.free_sessions')} value={freeLabel} onPress={() => setChoosingFree(true)} />
          <Text style={styles.fieldHint}>{t('m.pro.free_sessions_hint')}</Text>
          <ChoiceSheet
            visible={choosingFree}
            title={t('m.pro.free_sessions')}
            choices={data.free_options}
            selected={free}
            onPick={setFree}
            onClose={() => setChoosingFree(false)}
          />
        </>
      )}
      {message ? <FormMessage tone={message.tone} text={message.text} /> : null}
      <Button title={t('m.pro.save_changes')} onPress={save} busy={busy} />
    </Panel>
  );
}

// ---- Video calls --------------------------------------------------------------------

function VideoSetting({ video, toast, reload }: { video: ProDashboard['video']; toast: (t: string) => void; reload: () => Promise<void> }) {
  const styles = useStyles();
  const { t } = useLanguage();
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
    toast(t('m.pro.video_saved'));
    reload();
  }

  if (!video.allowed) {
    return (
      <Panel title={t('m.pro.video_calls')} note={t('m.pro.video_calls_note')}>
        <Text style={styles.body}>{t('m.pro.video_kounselia_only')}</Text>
      </Panel>
    );
  }
  const options: { key: 'kounselia' | 'own'; label: string }[] = [
    { key: 'kounselia', label: t('m.pro.video_kounselia') },
    { key: 'own', label: t('m.pro.video_own') },
  ];
  return (
    <Panel title={t('m.pro.video_calls')} note={t('m.pro.video_calls_note')}>
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
          <TextField label={t('m.pro.meeting_link')} value={link} onChangeText={setLink} placeholder="https://zoom.us/j/… / https://meet.google.com/…" keyboardType="url" autoCapitalize="none" autoCorrect={false} />
          <Text style={styles.fieldHint}>{t('m.pro.video_own_hint')}</Text>
        </>
      )}
      <Text style={styles.fieldHint}>{t('m.pro.video_safety_note')}</Text>
      {message ? <FormMessage tone={message.tone} text={message.text} /> : null}
      <Button title={t('m.pro.save_video')} onPress={save} busy={busy} />
    </Panel>
  );
}

// ---- Documents --------------------------------------------------------------------------

function Documents({ data, toast, reload }: { data: ProDashboard; toast: (t: string) => void; reload: () => Promise<void> }) {
  const styles = useStyles();
  const { t } = useLanguage();
  const colors = useColors();
  const { config } = useSession();
  const [docType, setDocType] = useState<DocumentType>('certificate');
  const [choosingType, setChoosingType] = useState(false);
  const [busy, setBusy] = useState(false);
  const [opening, setOpening] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function add(pick: (t: Translate) => Promise<PickResult>) {
    setError(null);
    // Let the question box finish closing first: a phone won't open its
    // file picker over another pop-up.
    await new Promise((resolve) => setTimeout(resolve, 350));
    const picked = await pick(t);
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
    toast(t('m.pro.doc_added'));
    reload();
  }

  function choose() {
    showDialog({
      title: t('m.pro.doc_add_title'),
      message: t('m.pro.doc_add_formats'),
      icon: 'file-text',
      buttons: [
        { text: t('m.pro.doc_choose_file'), onPress: () => add(pickDocumentFile) },
        { text: t('m.pro.doc_choose_photo'), onPress: () => add(pickDocumentPhoto) },
        { text: t('m.pro.cancel'), style: 'cancel' },
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
    Linking.openURL(res.data.url).catch(() => toast(t('m.pro.doc_open_failed')));
  }

  function remove(id: number, name: string) {
    showDialog({
      title: t('m.pro.doc_remove_q'),
      message: name,
      icon: 'trash',
      buttons: [
        { text: t('m.pro.keep_it'), style: 'cancel' },
        {
          text: t('m.pro.remove'),
          style: 'destructive',
          onPress: async () => {
            const res = await deleteProDocument(config, id);
            toast(res.ok ? res.data.message || t('m.pro.doc_removed') : res.message);
            reload();
          },
        },
      ],
    });
  }

  return (
    <Panel title={t('m.pro.documents')} note={t('m.pro.documents_note')}>
      {data.documents.length === 0 ? <Text style={styles.body}>{t('m.pro.no_documents')}</Text> : null}
      {data.documents.map((d) => (
        <View key={d.id} style={styles.doc}>
          <TablerIcon name="file-text" size={18} color={colors.accentText} />
          <View style={{ flex: 1, minWidth: 0 }}>
            <Text style={styles.docName} numberOfLines={1}>
              {d.name}
            </Text>
            <Text style={styles.docType}>{d.type_label}</Text>
          </View>
          <Pressable onPress={() => view(d.id)} accessibilityRole="button" accessibilityLabel={t('m.pro.doc_view_named', { name: d.name })} hitSlop={8}>
            {opening === d.id ? <ActivityIndicator size="small" color={colors.accentText} /> : <Text style={styles.docView}>{t('m.pro.view')}</Text>}
          </Pressable>
          <Pressable onPress={() => remove(d.id, d.name)} accessibilityRole="button" accessibilityLabel={t('m.pro.doc_remove_named', { name: d.name })} hitSlop={8}>
            <TablerIcon name="trash" size={18} color={colors.text3} />
          </Pressable>
        </View>
      ))}
      <View style={{ height: 12 }} />
      <SelectField label={t('m.pro.doc_type')} value={t(DOC_TYPES.find((d) => d.value === docType)?.label ?? 'm.pro.doc_other')} onPress={() => setChoosingType(true)} />
      {error ? <FormMessage tone="error" text={error} /> : null}
      <Button title={t('m.pro.doc_add')} onPress={choose} busy={busy} disabled={data.documents.length >= 10} />
      <ChoiceSheet visible={choosingType} title={t('m.pro.doc_type')} choices={DOC_TYPES.map((d) => ({ value: d.value, label: t(d.label) }))} selected={docType} onPick={setDocType} onClose={() => setChoosingType(false)} />
    </Panel>
  );
}

// ---- Reviews -----------------------------------------------------------------------------

function Reviews({ data }: { data: ProDashboard }) {
  const styles = useStyles();
  const { language, t } = useLanguage();
  const { rating, reviews } = data;
  return (
    <Panel
      title={t('m.pro.client_reviews')}
      note={
        rating.count > 0
          ? t(rating.count === 1 ? 'm.pro.reviews_average_one' : 'm.pro.reviews_average', { average: rating.average.toFixed(1), count: rating.count })
          : t('m.pro.reviews_empty')
      }
    >
      {reviews.map((r, i) => (
        <View key={i} style={styles.review}>
          <Text style={styles.reviewStars} accessibilityLabel={t('m.pro.stars_of_5', { n: r.rating })}>
            {'★'.repeat(r.rating)}
            {'☆'.repeat(5 - r.rating)} <Text style={styles.reviewName}>{r.client_name}</Text>
          </Text>
          {r.comment ? <Text style={styles.body}>{r.comment}</Text> : null}
          <Text style={styles.docType}>{sessionWhen(r.date_utc, true, language)}</Text>
        </View>
      ))}
    </Panel>
  );
}

// ---- Public listing ------------------------------------------------------------------------

function PublicToggle({ data }: { data: ProDashboard }) {
  const styles = useStyles();
  const { t } = useLanguage();
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
          <Text style={styles.toggleTitle}>{t('m.pro.public_title')}</Text>
          <Text style={styles.toggleText}>{t('m.pro.public_body')}</Text>
        </View>
        <Switch value={on} onValueChange={toggle} trackColor={{ true: colors.accent, false: colors.surface3 }} accessibilityLabel={t('m.pro.public_title')} />
      </View>
      {data.public_profile.url ? (
        <Pressable onPress={() => openInApp(data.public_profile.url!, { title: t('m.pro.public_profile'), web: true })} accessibilityRole="link" style={{ marginTop: 10 }}>
          <Text style={styles.link}>{t('m.pro.public_view')} →</Text>
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
