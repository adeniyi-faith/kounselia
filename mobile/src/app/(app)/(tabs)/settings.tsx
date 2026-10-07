// Settings: everything the website's "Profile and settings" tab has, laid
// out like a phone's settings — your profile and photo, your plan, account
// details, privacy and security (app lock, notifications), what your
// counselors remember, emails, reading, help and about, and deleting the
// account.
import {
  changePassword,
  fetchAccount,
  LANGUAGES,
  saveEmailPrefs,
  setLanguage,
  updateName,
  uploadAvatar,
  type Account,
} from '@kounselia/core';
import Constants from 'expo-constants';
import * as Haptics from 'expo-haptics';
import { Image } from 'expo-image';
import { ImageManipulator, SaveFormat } from 'expo-image-manipulator';
import * as ImagePicker from 'expo-image-picker';
import { router, useFocusEffect } from 'expo-router';
import { useCallback, useState, type ReactNode } from 'react';
import { ActivityIndicator, Linking, Pressable, RefreshControl, ScrollView, Switch, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { confirmOwner, LOCK_AFTER_CHOICES, unlockMethodName, withoutLocking } from '@/appLockSetting';
import { useBrowser } from '@/browser/BrowserProvider';
import { useAppLock } from '@/components/AppLock';
import { Button } from '@/components/Button';
import { Toast, useToast } from '@/components/chat/Toast';
import { FormMessage } from '@/components/FormMessage';
import { openSafetyResources } from '@/components/openSafety';
import { ScreenHeader } from '@/components/ScreenHeader';
import { Sheet } from '@/components/Sheet';
import { TablerIcon } from '@/components/TablerIcon';
import { TextField } from '@/components/TextField';
import { pushState, turnOffPush, turnOnPush, type PushState } from '@/notifications';
import { useLanguage, useT } from '@/language';
import { useSession } from '@/session';
import { type Appearance, counselorColors, fonts, makeStyles, radius, shadows, useColors, useTheme } from '@/theme';
import { showDialog } from '@/components/Dialog';

const APPEARANCES: { key: Appearance; label: string; icon: string }[] = [
  { key: 'light', label: 'm.settings.light', icon: 'sun' },
  { key: 'dark', label: 'm.settings.dark', icon: 'moon' },
  { key: 'system', label: 'm.settings.device', icon: 'device-mobile' },
];

// The "lock after" choices, by how many seconds they wait (words from the app's own list).
const LOCK_AFTER_KEYS: Record<number, { label: string; a11y: string }> = {
  0: { label: 'm.settings.lock_now', a11y: 'm.settings.lock_a11y_now' },
  60: { label: 'm.settings.lock_1', a11y: 'm.settings.lock_a11y_1' },
  300: { label: 'm.settings.lock_5', a11y: 'm.settings.lock_a11y_5' },
};

// `inStack`: opened on top of a professional's own tabs (app/(app)/account.tsx)
// rather than as the client side's Settings tab, so it gets a back button.
export default function Settings({ inStack = false }: { inStack?: boolean }) {
  const styles = useStyles();
  const colors = useColors();
  const { user, config, signOut, updateUser, deleteAccount, setViewMode } = useSession();
  const { language, t, setLanguage: setAppLanguage } = useLanguage();
  const { appearance, setAppearance, scheme } = useTheme();
  const appearanceNote = appearance === 'system' ? t(scheme === 'dark' ? 'm.settings.matches_dark' : 'm.settings.matches_light') : undefined;
  const { openInApp } = useBrowser();
  const toast = useToast();
  const [account, setAccount] = useState<Account | null>(null);
  const [refreshing, setRefreshing] = useState(false);
  const [photoBusy, setPhotoBusy] = useState(false);
  const [sheet, setSheet] = useState<'name' | 'password' | 'delete' | null>(null);
  const lock = useAppLock();
  const [unlockWith, setUnlockWith] = useState<string | null>(null);
  const [push, setPush] = useState<PushState>('unavailable');

  const load = useCallback(async () => {
    const res = await fetchAccount(config);
    if (res.ok && res.data.user) setAccount(res.data);
  }, [config]);

  // What the phone allows can change in its own Settings while we're away.
  const loadPhone = useCallback(async () => {
    const [method, state] = await Promise.all([unlockMethodName(), pushState()]);
    setUnlockWith(method);
    setPush(state);
  }, []);

  // Fresh every time Settings is opened (e.g. after editing memory).
  useFocusEffect(
    useCallback(() => {
      load();
      loadPhone();
    }, [load, loadPhone]),
  );

  async function refresh() {
    setRefreshing(true);
    await load();
    setRefreshing(false);
  }

  async function changePhoto() {
    const pick = await withoutLocking(() => ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], allowsEditing: true, aspect: [1, 1], quality: 1 }));
    if (pick.canceled || !pick.assets[0]) return;
    setPhotoBusy(true);
    try {
      // A small square is all a profile photo needs, and it uploads fast.
      const ctx = ImageManipulator.manipulate(pick.assets[0].uri).resize({ width: 512 });
      const image = await ctx.renderAsync();
      const saved = await image.saveAsync({ compress: 0.82, format: SaveFormat.JPEG, base64: true });
      const res = await uploadAvatar(config, saved.base64 ?? '', 'image/jpeg');
      if (!res.ok) {
        toast.show(res.message);
        return;
      }
      setAccount((a) => (a ? { ...a, user: { ...a.user, avatar: res.data.avatar } } : a));
      Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => undefined);
      toast.show(t('m.settings.photo_updated'));
    } catch {
      toast.show(t('m.settings.photo_failed'));
    } finally {
      setPhotoBusy(false);
    }
  }

  async function toggleEmail(key: 'newsletter' | 'blog', on: boolean) {
    if (!account) return;
    const before = account.emails;
    const next = { ...before, [key]: on };
    setAccount({ ...account, emails: next });
    const res = await saveEmailPrefs(config, next);
    if (!res.ok) {
      setAccount((a) => (a ? { ...a, emails: before } : a));
      toast.show(res.message);
    }
  }

  async function toggleLock(on: boolean) {
    if (on) {
      const method = await unlockMethodName();
      if (!method) {
        showDialog({
          title: t('m.settings.lock_needed_title'),
          message: t('m.settings.lock_needed_body'),
          icon: 'lock',
        });
        return;
      }
      const res = await confirmOwner(t('m.settings.lock_turn_on'));
      if (!res.ok) return;
      await lock.setAfter(0);
      Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => undefined);
      toast.show(t('m.settings.lock_is_on'));
    } else {
      const res = await confirmOwner(t('m.settings.lock_turn_off'));
      if (!res.ok) return;
      await lock.setAfter(null);
      toast.show(t('m.settings.lock_is_off'));
    }
  }

  // Language: counselors, growth plans and reminders write in it; screens follow as they are translated.
  function chooseLanguage() {
    showDialog({
      title: t('lang.choose'),
      message: t('lang.note'),
      icon: 'message-language',
      buttons: [
        ...LANGUAGES.map((l) => ({
          text: l.name,
          onPress: async () => {
            const res = await setLanguage(config, l.code);
            if (!res.ok) {
              toast.show(res.message);
              return;
            }
            setAppLanguage(res.data.language);
            setAccount((a) => (a ? { ...a, language: res.data.language } : a));
            toast.show(res.data.message);
          },
        })),
        { text: t('lang.cancel'), style: 'cancel' as const },
      ],
    });
  }

  function notificationsBlocked() {
    showDialog({
      title: t('m.settings.notif_off_title'),
      message: t('m.settings.notif_off_body'),
      icon: 'bell-off',
      buttons: [
        { text: t('m.common.not_now'), style: 'cancel' },
        { text: t('m.settings.open_settings'), onPress: () => Linking.openSettings().catch(() => undefined) },
      ],
    });
  }

  async function togglePush(on: boolean) {
    if (on) {
      setPush('on');
      const next = await withoutLocking(() => turnOnPush(config));
      setPush(next);
      if (next === 'blocked') notificationsBlocked();
    } else {
      setPush('off');
      await turnOffPush(config);
    }
  }

  function confirmSignOut() {
    showDialog({
      title: t('m.common.sign_out_q'),
      message: t('m.settings.signout_body'),
      icon: 'logout',
      buttons: [
        { text: t('lang.cancel'), style: 'cancel' },
        { text: t('m.common.sign_out'), style: 'destructive', onPress: () => signOut() },
      ],
    });
  }

  const name = account?.user.name || user?.name || t('m.settings.your_account');
  const email = account?.user.email || user?.email || '';
  const initial = (name || email || '?').trim().charAt(0).toUpperCase();
  const plan = account?.plan;
  const links = account?.links;
  const memory = account?.memory;

  return (
    <SafeAreaView style={styles.safe} edges={inStack ? ['top', 'left', 'right', 'bottom'] : ['top', 'left', 'right']}>
      {inStack && <ScreenHeader title={t('m.settings.account_settings')} />}
      <ScrollView
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.accentText} />}
      >
        {!inStack && (
          <Text style={styles.title} accessibilityRole="header">
            {t('m.tabs.settings')}
          </Text>
        )}

        {/* Profile */}
        <View style={styles.profile}>
          <Pressable
            onPress={changePhoto}
            disabled={photoBusy}
            accessibilityRole="button"
            accessibilityLabel={t('m.settings.change_photo_a11y')}
            style={styles.avatarWrap}
          >
            <View style={styles.avatar}>
              {account?.user.avatar ? (
                <Image source={{ uri: account.user.avatar }} style={styles.avatarImg} contentFit="cover" transition={200} />
              ) : (
                <Text style={styles.avatarText}>{initial}</Text>
              )}
              {photoBusy && (
                <View style={styles.avatarBusy}>
                  <ActivityIndicator color="#fff" />
                </View>
              )}
            </View>
            <View style={styles.cameraBadge}>
              <TablerIcon name="camera" size={14} color="#fff" />
            </View>
          </Pressable>
          <Text style={styles.name} numberOfLines={1}>
            {name}
          </Text>
          {email ? (
            <Text style={styles.email} numberOfLines={1}>
              {email}
            </Text>
          ) : null}
          <View style={styles.badges}>
            {plan && (
              <View style={[styles.pill, plan.is_pro ? styles.pillPro : styles.pillFree]}>
                <TablerIcon name={plan.is_pro ? 'sparkles' : 'leaf'} size={13} color={plan.is_pro ? colors.gold : colors.sage} />
                <Text style={[styles.pillText, { color: plan.is_pro ? colors.gold : colors.sage }]}>{plan.is_pro ? t('m.settings.pro_member') : t('m.settings.free_plan')}</Text>
              </View>
            )}
            {account?.user.member_since ? <Text style={styles.since}>{t('m.settings.member_since', { date: account.user.member_since })}</Text> : null}
          </View>
        </View>

        {/* Urgent help, always near the top */}
        <Pressable
          onPress={openSafetyResources}
          accessibilityRole="link"
          accessibilityHint={t('m.settings.help_hint')}
          style={({ pressed }) => [styles.help, pressed && { opacity: 0.85 }]}
        >
          <View style={styles.helpIcon}>
            <TablerIcon name="lifebuoy" size={20} color={colors.rose} />
          </View>
          <View style={{ flex: 1 }}>
            <Text style={styles.helpTitle}>{t('m.settings.get_help')}</Text>
            <Text style={styles.helpText}>{t('m.settings.get_help_text')}</Text>
          </View>
          <TablerIcon name="chevron-right" size={18} color={colors.text3} />
        </Pressable>

        {plan && (
          <Group title={t('m.settings.g_plan')}>
            <Row
              icon={plan.is_pro ? 'sparkles' : 'leaf'}
              tint={plan.is_pro ? 'gold' : 'sage'}
              label={plan.title}
              sub={plan.detail}
              onPress={() => router.push('/plan')}
              last
            />
          </Group>
        )}

        <Group title={t('m.settings.g_appearance')} note={appearanceNote}>
          <View style={styles.segment} accessibilityRole="radiogroup">
            {APPEARANCES.map((a) => {
              const on = appearance === a.key;
              return (
                <Pressable
                  key={a.key}
                  onPress={() => {
                    Haptics.selectionAsync().catch(() => undefined);
                    setAppearance(a.key);
                  }}
                  accessibilityRole="radio"
                  accessibilityState={{ checked: on }}
                  aria-checked={on}
                  accessibilityLabel={t(a.label)}
                  style={[styles.segmentItem, on && styles.segmentOn]}
                >
                  <TablerIcon name={a.icon} size={20} color={on ? colors.accentText : colors.text3} />
                  <Text style={[styles.segmentText, on && styles.segmentTextOn]}>{t(a.label)}</Text>
                </Pressable>
              );
            })}
          </View>
        </Group>

        {/* For professionals: back to their own home. For everyone else:
            the website's "Join as a professional" (apply.php). Left out on
            top of a professional's own tabs, which already are that home. */}
        {!inStack && (
          <Group title={t('m.settings.g_pro')}>
            {user?.professional ? (
              <Row
                icon="switch-horizontal"
                tint="gold"
                label={t('m.settings.pro_switch')}
                sub={t('m.settings.pro_switch_sub')}
                onPress={() => setViewMode('professional')}
                last
              />
            ) : (
              <Row
                icon="stethoscope"
                tint="gold"
                label={t('m.settings.pro_join')}
                sub={t('m.settings.pro_join_sub')}
                onPress={() => router.push('/apply')}
                last
              />
            )}
          </Group>
        )}

        <Group title={t('m.settings.g_account')}>
          <Row icon="user" label={t('m.settings.name')} value={name} onPress={() => setSheet('name')} />
          <Row icon="mail" label={t('m.auth.email')} value={email} />
          <Row icon="camera" label={t('m.settings.profile_photo')} value={account?.user.avatar ? t('m.settings.change') : t('m.settings.add')} onPress={changePhoto} />
          <Row icon="message-language" label={t('lang.setting')} value={LANGUAGES.find((l) => l.code === language)?.name ?? 'English'} onPress={chooseLanguage} />
          <Row icon="key" label={t('m.auth.password')} value={t('m.settings.change')} onPress={() => setSheet('password')} last />
        </Group>

        <Group title={t('m.settings.g_privacy')} note={lock.after !== null && lock.after !== undefined ? t('m.settings.privacy_note') : undefined}>
          <Row
            icon="lock"
            tint="plum"
            label={t('m.settings.app_lock')}
            sub={unlockWith ? t('m.settings.ask_for_method', { method: unlockWith }) : t('m.settings.ask_for_default')}
            right={
              <Switch
                value={lock.after !== null && lock.after !== undefined}
                disabled={lock.after === undefined}
                onValueChange={toggleLock}
                trackColor={{ true: colors.accent, false: colors.surface3 }}
                accessibilityLabel={t('m.settings.app_lock')}
              />
            }
            last={push === 'unavailable' && (lock.after === null || lock.after === undefined)}
          />
          {lock.after !== null && lock.after !== undefined ? (
            <View style={[styles.segment, push !== 'unavailable' && styles.rowBorder]} accessibilityRole="radiogroup" accessibilityLabel={t('m.settings.when_to_lock')}>
              {LOCK_AFTER_CHOICES.map((c) => {
                const on = lock.after === c.value;
                return (
                  <Pressable
                    key={c.value}
                    onPress={() => {
                      Haptics.selectionAsync().catch(() => undefined);
                      lock.setAfter(c.value);
                    }}
                    accessibilityRole="radio"
                    accessibilityState={{ checked: on }}
                    aria-checked={on}
                    accessibilityLabel={LOCK_AFTER_KEYS[c.value] ? t(LOCK_AFTER_KEYS[c.value].a11y) : c.label}
                    style={[styles.segmentItem, styles.segmentItemSmall, on && styles.segmentOn]}
                  >
                    <Text style={[styles.segmentText, on && styles.segmentTextOn]}>{LOCK_AFTER_KEYS[c.value] ? t(LOCK_AFTER_KEYS[c.value].label) : c.label}</Text>
                  </Pressable>
                );
              })}
            </View>
          ) : null}
          {push === 'blocked' ? (
            <Row icon="bell-off" label={t('m.settings.notifications')} sub={t('m.settings.notif_blocked_sub')} onPress={notificationsBlocked} last />
          ) : push !== 'unavailable' ? (
            <Row
              icon="bell"
              label={t('m.settings.notifications')}
              sub={t('m.settings.notif_sub')}
              right={
                <Switch
                  value={push === 'on'}
                  onValueChange={togglePush}
                  trackColor={{ true: colors.accent, false: colors.surface3 }}
                  accessibilityLabel={t('m.settings.notifications')}
                />
              }
              last
            />
          ) : null}
        </Group>

        <Group title={t('m.settings.g_counselors')} note={t('m.settings.counselors_note')}>
          <Row
            icon="brain"
            tint="plum"
            label={t('m.settings.memory')}
            sub={memory ? t('m.settings.memory_sub_set') : t('m.settings.memory_sub_unset')}
            onPress={() => router.push('/memory')}
            last
          />
        </Group>

        <Group title={t('m.settings.g_reading')}>
          <Row icon="notebook" tint="sage" label={t('m.settings.journal')} sub={t('m.settings.journal_sub')} onPress={() => router.push('/journal')} />
          <Row icon="news" tint="gold" label={t('m.settings.articles')} sub={t('m.settings.articles_sub')} onPress={() => router.push('/articles')} last />
        </Group>

        <Group title={t('m.settings.g_emails')} note={t('m.settings.emails_note')}>
          <Row
            icon="mail"
            label={t('m.settings.newsletter')}
            sub={t('m.settings.newsletter_sub')}
            right={
              <Switch
                value={!!account?.emails.newsletter}
                disabled={!account}
                onValueChange={(on) => toggleEmail('newsletter', on)}
                trackColor={{ true: colors.accent, false: colors.surface3 }}
                accessibilityLabel={t('m.settings.newsletter_a11y')}
              />
            }
          />
          <Row
            icon="feather"
            label={t('m.settings.new_articles')}
            sub={t('m.settings.new_articles_sub')}
            right={
              <Switch
                value={!!account?.emails.blog}
                disabled={!account}
                onValueChange={(on) => toggleEmail('blog', on)}
                trackColor={{ true: colors.accent, false: colors.surface3 }}
                accessibilityLabel={t('m.settings.new_articles_a11y')}
              />
            }
            last
          />
        </Group>

        <Group title={t('m.settings.g_help')}>
          <Row icon="lifebuoy" tint="rose" label={t('m.settings.safety')} onPress={openSafetyResources} />
          <Row
            icon="message-circle"
            label={t('m.settings.contact')}
            value={links?.email}
            onPress={() => Linking.openURL(`mailto:${links?.email ?? 'hello@kounselia.com'}`).catch(() => undefined)}
          />
          {links?.mission ? <Row icon="heart-handshake" label={t('m.settings.mission')} onPress={() => openInApp(links.mission!)} /> : null}
          {links?.privacy ? <Row icon="shield-lock" label={t('m.settings.privacy_policy')} onPress={() => openInApp(links.privacy!)} /> : null}
          {links?.terms ? <Row icon="file-text" label={t('m.settings.terms')} onPress={() => openInApp(links.terms!)} /> : null}
          <Row icon="info-circle" label={t('m.settings.app_version')} value={Constants.expoConfig?.version ?? ''} last />
        </Group>

        <Group title={t('m.settings.g_data')}>
          <Row
            icon="trash"
            tint="rose"
            label={t('m.settings.delete_account')}
            sub={t('m.settings.delete_sub')}
            onPress={() => setSheet('delete')}
            last
          />
        </Group>

        <Button title={t('m.common.sign_out')} variant="ghost" onPress={confirmSignOut} style={styles.signOut} />
        <Text style={styles.footer}>{t('m.settings.footer')}</Text>
      </ScrollView>

      <NameSheet
        visible={sheet === 'name'}
        current={name}
        onClose={() => setSheet(null)}
        onSave={async (next) => {
          const res = await updateName(config, next);
          if (!res.ok) return res.message;
          updateUser({ name: res.data.name });
          setAccount((a) => (a ? { ...a, user: { ...a.user, name: res.data.name } } : a));
          setSheet(null);
          toast.show(t('m.settings.name_updated'));
          return null;
        }}
      />
      <PasswordSheet
        visible={sheet === 'password'}
        onClose={() => setSheet(null)}
        onSave={async (current, next) => {
          const res = await changePassword(config, current, next);
          if (!res.ok) return res.message;
          setSheet(null);
          Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => undefined);
          toast.show(t('m.settings.password_changed'));
          return null;
        }}
      />
      <DeleteAccountSheet
        visible={sheet === 'delete'}
        onClose={() => setSheet(null)}
        onDelete={async (password) => {
          const problem = await deleteAccount(password);
          if (problem) return problem;
          // Signed out already: the welcome screen is showing underneath.
          showDialog({
            title: t('m.settings.deleted_title'),
            message: t('m.settings.deleted_body'),
            icon: 'heart',
          });
          return null;
        }}
      />
      <Toast note={toast.note} />
    </SafeAreaView>
  );
}

// ---- Building blocks ---------------------------------------------------------

function Group({ title, note, children }: { title: string; note?: string; children: ReactNode }) {
  const styles = useStyles();
  return (
    <View style={styles.group}>
      <Text style={styles.groupTitle} accessibilityRole="header">
        {title.toUpperCase()}
      </Text>
      <View style={styles.groupCard}>{children}</View>
      {note ? <Text style={styles.groupNote}>{note}</Text> : null}
    </View>
  );
}

type Tint = 'blue' | 'sage' | 'gold' | 'rose' | 'plum';

function Row({
  icon,
  label,
  sub,
  value,
  right,
  onPress,
  tint = 'blue',
  last,
}: {
  icon: string;
  label: string;
  sub?: string;
  value?: string;
  right?: ReactNode;
  onPress?: () => void;
  tint?: Tint;
  last?: boolean;
}) {
  const styles = useStyles();
  const colors = useColors();
  const t = counselorColors(tint, colors);
  const body = (
    <>
      <View style={[styles.tile, { backgroundColor: t.bg }]}>
        <TablerIcon name={icon} size={18} color={t.fg} />
      </View>
      <View style={styles.rowMain}>
        <Text style={styles.rowLabel}>{label}</Text>
        {sub ? <Text style={styles.rowSub}>{sub}</Text> : null}
      </View>
      {value ? (
        <Text style={styles.rowValue} numberOfLines={1}>
          {value}
        </Text>
      ) : null}
      {right}
      {onPress && !right ? <TablerIcon name="chevron-right" size={18} color={colors.text3} /> : null}
    </>
  );
  if (!onPress) return <View style={[styles.row, !last && styles.rowBorder]}>{body}</View>;
  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      style={({ pressed }) => [styles.row, !last && styles.rowBorder, pressed && { backgroundColor: colors.surface2 }]}
    >
      {body}
    </Pressable>
  );
}

function NameSheet({
  visible,
  current,
  onClose,
  onSave,
}: {
  visible: boolean;
  current: string;
  onClose: () => void;
  onSave: (name: string) => Promise<string | null>;
}) {
  const styles = useStyles();
  const t = useT();
  const [name, setName] = useState(current);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [wasVisible, setWasVisible] = useState(visible);
  // Start from their current name each time it opens.
  if (visible !== wasVisible) {
    setWasVisible(visible);
    if (visible) {
      setName(current);
      setError('');
    }
  }

  async function save() {
    if (!name.trim()) {
      setError(t('m.settings.enter_name'));
      return;
    }
    setBusy(true);
    const problem = await onSave(name.trim());
    setBusy(false);
    if (problem) setError(problem);
  }

  return (
    <Sheet visible={visible} title={t('m.auth.your_name')} onClose={onClose}>
      <Text style={styles.sheetText}>{t('m.settings.name_sheet_text')}</Text>
      <TextField label={t('m.settings.full_name')} value={name} onChangeText={setName} autoFocus autoCapitalize="words" autoComplete="name" returnKeyType="done" onSubmitEditing={save} />
      {error ? <FormMessage tone="error" text={error} /> : null}
      <Button title={t('m.common.save')} onPress={save} busy={busy} style={styles.sheetButton} />
    </Sheet>
  );
}

function PasswordSheet({
  visible,
  onClose,
  onSave,
}: {
  visible: boolean;
  onClose: () => void;
  onSave: (current: string, next: string) => Promise<string | null>;
}) {
  const styles = useStyles();
  const t = useT();
  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [confirm, setConfirm] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [wasVisible, setWasVisible] = useState(visible);
  if (visible !== wasVisible) {
    setWasVisible(visible);
    if (visible) {
      setCurrent('');
      setNext('');
      setConfirm('');
      setError('');
    }
  }

  async function save() {
    if (!current || !next) return setError(t('m.settings.fill_both'));
    if (next.length < 8) return setError(t('m.settings.new_pw_short'));
    if (next !== confirm) return setError(t('m.settings.pw_mismatch'));
    setBusy(true);
    const problem = await onSave(current, next);
    setBusy(false);
    if (problem) setError(problem);
  }

  return (
    <Sheet visible={visible} title={t('m.settings.change_password')} onClose={onClose}>
      <Text style={styles.sheetText}>{t('m.settings.pw_sheet_text')}</Text>
      <TextField label={t('m.settings.current_pw')} password value={current} onChangeText={setCurrent} autoComplete="current-password" autoFocus />
      <TextField label={t('m.settings.new_pw')} password value={next} onChangeText={setNext} autoComplete="new-password" placeholder={t('m.auth.pw_placeholder')} />
      <TextField label={t('m.settings.confirm_pw')} password value={confirm} onChangeText={setConfirm} autoComplete="new-password" onSubmitEditing={save} />
      {error ? <FormMessage tone="error" text={error} /> : null}
      <Button title={t('m.settings.update_pw')} onPress={save} busy={busy} style={styles.sheetButton} />
    </Sheet>
  );
}

function DeleteAccountSheet({
  visible,
  onClose,
  onDelete,
}: {
  visible: boolean;
  onClose: () => void;
  onDelete: (password: string) => Promise<string | null>;
}) {
  const styles = useStyles();
  const colors = useColors();
  const t = useT();
  const [password, setPassword] = useState('');
  const [sure, setSure] = useState(false);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [wasVisible, setWasVisible] = useState(visible);
  if (visible !== wasVisible) {
    setWasVisible(visible);
    if (visible) {
      setPassword('');
      setSure(false);
      setError('');
    }
  }

  async function remove() {
    if (!password) return setError(t('m.settings.enter_pw'));
    if (!sure) return setError(t('m.settings.confirm_understand'));
    setBusy(true);
    const problem = await onDelete(password);
    setBusy(false);
    if (problem) setError(problem);
  }

  return (
    <Sheet visible={visible} title={t('m.settings.delete_your_account')} onClose={onClose}>
      <Text style={styles.sheetText}>{t('m.settings.delete_text1')}</Text>
      <Text style={styles.sheetText}>{t('m.settings.delete_text2')}</Text>
      <TextField label={t('m.settings.your_password')} password value={password} onChangeText={setPassword} autoComplete="current-password" />
      <View style={styles.sureRow}>
        <Switch
          value={sure}
          onValueChange={setSure}
          trackColor={{ true: colors.rose, false: colors.surface3 }}
          accessibilityLabel={t('m.settings.understand')}
        />
        <Text style={styles.sureText}>{t('m.settings.understand')}</Text>
      </View>
      {error ? <FormMessage tone="error" text={error} /> : null}
      <Button title={t('m.settings.delete_my')} variant="danger" onPress={remove} busy={busy} style={styles.sheetButton} />
    </Sheet>
  );
}

const useStyles = makeStyles((colors) => ({
  safe: { flex: 1, backgroundColor: colors.bg },
  content: { padding: 16, paddingBottom: 48 },
  title: { fontFamily: fonts.serifMedium, fontSize: 30, color: colors.text, marginTop: 16, marginBottom: 16, marginLeft: 4 },
  profile: {
    alignItems: 'center',
    backgroundColor: colors.surface,
    borderRadius: radius.r,
    borderWidth: 1,
    borderColor: colors.border,
    paddingVertical: 24,
    paddingHorizontal: 20,
    ...shadows.soft,
  },
  avatarWrap: { marginBottom: 12 },
  avatar: {
    width: 88,
    height: 88,
    borderRadius: 44,
    backgroundColor: colors.accentLight,
    alignItems: 'center',
    justifyContent: 'center',
    overflow: 'hidden',
  },
  avatarImg: { width: 88, height: 88 },
  avatarText: { fontFamily: fonts.semibold, fontSize: 32, color: colors.accentText },
  avatarBusy: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, backgroundColor: colors.backdrop, alignItems: 'center', justifyContent: 'center' },
  cameraBadge: {
    position: 'absolute',
    right: -2,
    bottom: -2,
    width: 30,
    height: 30,
    borderRadius: 15,
    backgroundColor: colors.accent,
    borderWidth: 3,
    borderColor: colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
  },
  name: { fontFamily: fonts.serifMedium, fontSize: 26, color: colors.text },
  email: { fontFamily: fonts.regular, fontSize: 14, color: colors.text2, marginTop: 2 },
  badges: { alignItems: 'center', gap: 6, marginTop: 12 },
  pill: { flexDirection: 'row', alignItems: 'center', gap: 5, paddingVertical: 5, paddingHorizontal: 12, borderRadius: radius.pill },
  pillPro: { backgroundColor: colors.goldLight },
  pillFree: { backgroundColor: colors.sageLight },
  pillText: { fontFamily: fonts.semibold, fontSize: 12 },
  since: { fontFamily: fonts.regular, fontSize: 12, color: colors.text3 },
  help: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 14,
    marginTop: 16,
    padding: 16,
    borderRadius: radius.r,
    backgroundColor: colors.roseLight,
    borderWidth: 1,
    borderColor: colors.roseBorder,
  },
  helpIcon: { width: 40, height: 40, borderRadius: 12, backgroundColor: colors.surface, alignItems: 'center', justifyContent: 'center' },
  helpTitle: { fontFamily: fonts.semibold, fontSize: 15, color: colors.rose },
  helpText: { fontFamily: fonts.regular, fontSize: 13, lineHeight: 18, color: colors.text2, marginTop: 2 },
  group: { marginTop: 26 },
  groupTitle: { fontFamily: fonts.semibold, fontSize: 12, letterSpacing: 1, color: colors.text3, marginBottom: 8, marginLeft: 6 },
  groupCard: {
    backgroundColor: colors.surface,
    borderRadius: radius.sm + 4,
    borderWidth: 1,
    borderColor: colors.border,
    overflow: 'hidden',
    ...shadows.soft,
  },
  groupNote: { fontFamily: fonts.regular, fontSize: 12, lineHeight: 17, color: colors.text3, marginTop: 8, marginHorizontal: 6 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 13, paddingHorizontal: 14, minHeight: 56 },
  rowBorder: { borderBottomWidth: 1, borderBottomColor: colors.surface2 },
  tile: { width: 34, height: 34, borderRadius: 10, alignItems: 'center', justifyContent: 'center' },
  rowMain: { flex: 1, minWidth: 0 },
  rowLabel: { fontFamily: fonts.medium, fontSize: 15, color: colors.text },
  rowSub: { fontFamily: fonts.regular, fontSize: 13, lineHeight: 18, color: colors.text2, marginTop: 1 },
  rowValue: { fontFamily: fonts.regular, fontSize: 14, color: colors.text3, maxWidth: '45%' },
  segment: { flexDirection: 'row', padding: 6, gap: 6 },
  segmentItem: { flex: 1, alignItems: 'center', gap: 4, paddingVertical: 12, borderRadius: 14 },
  segmentItemSmall: { paddingVertical: 10 },
  segmentOn: { backgroundColor: colors.accentLight, borderWidth: 1, borderColor: colors.accentBorder },
  segmentText: { fontFamily: fonts.medium, fontSize: 13, color: colors.text2 },
  segmentTextOn: { color: colors.accentText, fontFamily: fonts.semibold },
  signOut: { marginTop: 32 },
  footer: { fontFamily: fonts.regular, fontSize: 12, color: colors.text3, textAlign: 'center', marginTop: 18 },
  sheetText: { fontFamily: fonts.regular, fontSize: 14, lineHeight: 20, color: colors.text2, marginBottom: 16 },
  sheetButton: { marginTop: 8 },
  sureRow: { flexDirection: 'row', alignItems: 'center', gap: 12, marginTop: 4, marginBottom: 12 },
  sureText: { flex: 1, fontFamily: fonts.medium, fontSize: 14, color: colors.text },
}));
