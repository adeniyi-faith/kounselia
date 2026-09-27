// Settings: everything the website's "Profile and settings" tab has, laid
// out like a phone's settings — your profile and photo, your plan, account
// details, what your counselors remember, emails, reading, help and about.
import {
  changePassword,
  fetchAccount,
  saveEmailPrefs,
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
import { useBrowser } from '@/browser/BrowserProvider';
import { Button } from '@/components/Button';
import { Toast, useToast } from '@/components/chat/Toast';
import { FormMessage } from '@/components/FormMessage';
import { openSafetyResources } from '@/components/openSafety';
import { Sheet } from '@/components/Sheet';
import { TablerIcon } from '@/components/TablerIcon';
import { TextField } from '@/components/TextField';
import { useSession } from '@/session';
import { type Appearance, counselorColors, fonts, makeStyles, radius, shadows, useColors, useTheme } from '@/theme';
import { showDialog } from '@/components/Dialog';

const APPEARANCES: { key: Appearance; label: string; icon: string }[] = [
  { key: 'light', label: 'Light', icon: 'sun' },
  { key: 'dark', label: 'Dark', icon: 'moon' },
  { key: 'system', label: 'Device', icon: 'device-mobile' },
];

export default function Settings() {
  const styles = useStyles();
  const colors = useColors();
  const { user, config, signOut, updateUser } = useSession();
  const { appearance, setAppearance, scheme } = useTheme();
  const appearanceNote = appearance === 'system' ? `Matches your phone, which is using ${scheme} mode now.` : undefined;
  const { openInApp } = useBrowser();
  const toast = useToast();
  const [account, setAccount] = useState<Account | null>(null);
  const [refreshing, setRefreshing] = useState(false);
  const [photoBusy, setPhotoBusy] = useState(false);
  const [sheet, setSheet] = useState<'name' | 'password' | null>(null);

  const load = useCallback(async () => {
    const res = await fetchAccount(config);
    if (res.ok && res.data.user) setAccount(res.data);
  }, [config]);

  // Fresh every time Settings is opened (e.g. after editing memory).
  useFocusEffect(
    useCallback(() => {
      load();
    }, [load]),
  );

  async function refresh() {
    setRefreshing(true);
    await load();
    setRefreshing(false);
  }

  async function changePhoto() {
    const pick = await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], allowsEditing: true, aspect: [1, 1], quality: 1 });
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
      toast.show('Photo updated');
    } catch {
      toast.show("Couldn't use that photo. Please try another one.");
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

  function confirmSignOut() {
    showDialog({
      title: 'Sign out?',
      message: "You'll need your email and password to sign back in.",
      icon: 'logout',
      buttons: [
        { text: 'Cancel', style: 'cancel' },
        { text: 'Sign out', style: 'destructive', onPress: () => signOut() },
      ],
    });
  }

  const name = account?.user.name || user?.name || 'Your account';
  const email = account?.user.email || user?.email || '';
  const initial = (name || email || '?').trim().charAt(0).toUpperCase();
  const plan = account?.plan;
  const links = account?.links;
  const memory = account?.memory;

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
      <ScrollView
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.accentText} />}
      >
        <Text style={styles.title} accessibilityRole="header">
          Settings
        </Text>

        {/* Profile */}
        <View style={styles.profile}>
          <Pressable
            onPress={changePhoto}
            disabled={photoBusy}
            accessibilityRole="button"
            accessibilityLabel="Change profile photo"
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
                <Text style={[styles.pillText, { color: plan.is_pro ? colors.gold : colors.sage }]}>{plan.is_pro ? 'Pro member' : 'Free plan'}</Text>
              </View>
            )}
            {account?.user.member_since ? <Text style={styles.since}>Member since {account.user.member_since}</Text> : null}
          </View>
        </View>

        {/* Urgent help, always near the top */}
        <Pressable
          onPress={openSafetyResources}
          accessibilityRole="link"
          accessibilityHint="Opens emergency numbers and crisis lines"
          style={({ pressed }) => [styles.help, pressed && { opacity: 0.85 }]}
        >
          <View style={styles.helpIcon}>
            <TablerIcon name="lifebuoy" size={20} color={colors.rose} />
          </View>
          <View style={{ flex: 1 }}>
            <Text style={styles.helpTitle}>Get help now</Text>
            <Text style={styles.helpText}>If you’re in danger or thinking about ending your life, reach people who can help right away.</Text>
          </View>
          <TablerIcon name="chevron-right" size={18} color={colors.text3} />
        </Pressable>

        {plan && (
          <Group title="Your plan">
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

        <Group title="Appearance" note={appearanceNote}>
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
                  accessibilityLabel={a.label}
                  style={[styles.segmentItem, on && styles.segmentOn]}
                >
                  <TablerIcon name={a.icon} size={20} color={on ? colors.accentText : colors.text3} />
                  <Text style={[styles.segmentText, on && styles.segmentTextOn]}>{a.label}</Text>
                </Pressable>
              );
            })}
          </View>
        </Group>

        <Group title="Account">
          <Row icon="user" label="Name" value={name} onPress={() => setSheet('name')} />
          <Row icon="mail" label="Email" value={email} />
          <Row icon="camera" label="Profile photo" value={account?.user.avatar ? 'Change' : 'Add'} onPress={changePhoto} />
          <Row icon="key" label="Password" value="Change" onPress={() => setSheet('password')} last />
        </Group>

        <Group title="Your counselors" note="What they remember helps them know you">
          <Row
            icon="brain"
            tint="plum"
            label="Memory profile"
            sub={memory ? 'Your story, goals and what matters to you' : 'Not set up yet. Tell your counselors about you'}
            onPress={() => router.push('/memory')}
            last
          />
        </Group>

        <Group title="Reading and reflection">
          <Row icon="notebook" tint="sage" label="My journal" sub="Today’s reflection and every earlier day" onPress={() => router.push('/journal')} />
          <Row icon="news" tint="gold" label="Articles" sub="From The Kounselia Journal" onPress={() => router.push('/articles')} last />
        </Group>

        <Group title="Emails" note="Account emails, like booking confirmations, always arrive">
          <Row
            icon="mail"
            label="Newsletter"
            sub="Occasional ideas for looking after your mind"
            right={
              <Switch
                value={!!account?.emails.newsletter}
                disabled={!account}
                onValueChange={(on) => toggleEmail('newsletter', on)}
                trackColor={{ true: colors.accent, false: colors.surface3 }}
                accessibilityLabel="Newsletter emails"
              />
            }
          />
          <Row
            icon="feather"
            label="New articles"
            sub="A short email when a new story is published"
            right={
              <Switch
                value={!!account?.emails.blog}
                disabled={!account}
                onValueChange={(on) => toggleEmail('blog', on)}
                trackColor={{ true: colors.accent, false: colors.surface3 }}
                accessibilityLabel="New article emails"
              />
            }
            last
          />
        </Group>

        <Group title="Help and about">
          <Row icon="lifebuoy" tint="rose" label="Safety resources" onPress={openSafetyResources} />
          <Row
            icon="message-circle"
            label="Contact us"
            value={links?.email}
            onPress={() => Linking.openURL(`mailto:${links?.email ?? 'hello@kounselia.com'}`).catch(() => undefined)}
          />
          {links?.mission ? <Row icon="heart-handshake" label="Our mission" onPress={() => openInApp(links.mission!)} /> : null}
          {links?.privacy ? <Row icon="shield-lock" label="Privacy policy" onPress={() => openInApp(links.privacy!)} /> : null}
          {links?.terms ? <Row icon="file-text" label="Terms of service" onPress={() => openInApp(links.terms!)} /> : null}
          <Row icon="info-circle" label="App version" value={Constants.expoConfig?.version ?? ''} last />
        </Group>

        <Button title="Sign out" variant="ghost" onPress={confirmSignOut} style={styles.signOut} />
        <Text style={styles.footer}>Made with care by Kounselia</Text>
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
          toast.show('Name updated');
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
          toast.show('Password changed. Other phones have been signed out.');
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
      setError('Please enter your name.');
      return;
    }
    setBusy(true);
    const problem = await onSave(name.trim());
    setBusy(false);
    if (problem) setError(problem);
  }

  return (
    <Sheet visible={visible} title="Your name" onClose={onClose}>
      <Text style={styles.sheetText}>This is how your counselors and professionals will greet you.</Text>
      <TextField label="Full name" value={name} onChangeText={setName} autoFocus autoCapitalize="words" autoComplete="name" returnKeyType="done" onSubmitEditing={save} />
      {error ? <FormMessage tone="error" text={error} /> : null}
      <Button title="Save" onPress={save} busy={busy} style={styles.sheetButton} />
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
    if (!current || !next) return setError('Please fill in both password fields.');
    if (next.length < 8) return setError('Your new password needs at least 8 characters.');
    if (next !== confirm) return setError('The new passwords don’t match.');
    setBusy(true);
    const problem = await onSave(current, next);
    setBusy(false);
    if (problem) setError(problem);
  }

  return (
    <Sheet visible={visible} title="Change password" onClose={onClose}>
      <Text style={styles.sheetText}>You’ll stay signed in on this phone. Other phones will need the new password.</Text>
      <TextField label="Current password" password value={current} onChangeText={setCurrent} autoComplete="current-password" autoFocus />
      <TextField label="New password" password value={next} onChangeText={setNext} autoComplete="new-password" placeholder="At least 8 characters" />
      <TextField label="Confirm new password" password value={confirm} onChangeText={setConfirm} autoComplete="new-password" onSubmitEditing={save} />
      {error ? <FormMessage tone="error" text={error} /> : null}
      <Button title="Update password" onPress={save} busy={busy} style={styles.sheetButton} />
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
  segmentOn: { backgroundColor: colors.accentLight, borderWidth: 1, borderColor: colors.accentBorder },
  segmentText: { fontFamily: fonts.medium, fontSize: 13, color: colors.text2 },
  segmentTextOn: { color: colors.accentText, fontFamily: fonts.semibold },
  signOut: { marginTop: 32 },
  footer: { fontFamily: fonts.regular, fontSize: 12, color: colors.text3, textAlign: 'center', marginTop: 18 },
  sheetText: { fontFamily: fonts.regular, fontSize: 14, lineHeight: 20, color: colors.text2, marginBottom: 16 },
  sheetButton: { marginTop: 8 },
}));
