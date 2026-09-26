import Constants from 'expo-constants';
import { Alert, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button } from '@/components/Button';
import { openSafetyResources } from '@/components/openSafety';
import { TablerIcon } from '@/components/TablerIcon';
import { useSession } from '@/session';
import { colors, fonts, radius, shadows } from '@/theme';

export default function Settings() {
  const { user, signOut } = useSession();
  const initial = (user?.name || user?.email || '?').trim().charAt(0).toUpperCase();

  function confirmSignOut() {
    Alert.alert('Sign out?', "You'll need your email and password to sign back in.", [
      { text: 'Cancel', style: 'cancel' },
      { text: 'Sign out', style: 'destructive', onPress: () => signOut() },
    ]);
  }

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
      <ScrollView contentContainerStyle={styles.content}>
        <Text style={styles.title} accessibilityRole="header">
          Settings
        </Text>
        <View style={styles.card}>
          <View style={styles.avatar}>
            <Text style={styles.avatarText}>{initial}</Text>
          </View>
          <View style={styles.who}>
            <Text style={styles.name} numberOfLines={1}>
              {user?.name || 'Your account'}
            </Text>
            {user?.email ? (
              <Text style={styles.email} numberOfLines={1}>
                {user.email}
              </Text>
            ) : null}
          </View>
        </View>
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
        <Button title="Sign out" variant="ghost" onPress={confirmSignOut} style={styles.signOut} />
        <Text style={styles.version}>Kounselia {Constants.expoConfig?.version ?? ''}</Text>
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.bg },
  content: { padding: 20 },
  title: { fontFamily: fonts.serifMedium, fontSize: 28, color: colors.text, marginTop: 20, marginBottom: 18 },
  card: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 14,
    backgroundColor: colors.surface,
    borderRadius: radius.r,
    borderWidth: 1,
    borderColor: colors.border,
    padding: 18,
    ...shadows.soft,
  },
  // .user-av
  avatar: {
    width: 52,
    height: 52,
    borderRadius: 26,
    backgroundColor: colors.accentLight,
    alignItems: 'center',
    justifyContent: 'center',
  },
  avatarText: { fontFamily: fonts.semibold, fontSize: 20, color: colors.accent },
  who: { flex: 1 },
  name: { fontFamily: fonts.medium, fontSize: 16, color: colors.text },
  email: { fontFamily: fonts.regular, fontSize: 14, color: colors.text2, marginTop: 2 },
  help: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 14,
    marginTop: 16,
    padding: 16,
    borderRadius: radius.r,
    backgroundColor: colors.roseLight,
    borderWidth: 1,
    borderColor: 'rgba(139,58,82,0.18)',
  },
  helpIcon: { width: 40, height: 40, borderRadius: 12, backgroundColor: colors.surface, alignItems: 'center', justifyContent: 'center' },
  helpTitle: { fontFamily: fonts.semibold, fontSize: 15, color: colors.rose },
  helpText: { fontFamily: fonts.regular, fontSize: 13, lineHeight: 18, color: colors.text2, marginTop: 2 },
  signOut: { marginTop: 28 },
  version: { fontFamily: fonts.regular, fontSize: 12, color: colors.text3, textAlign: 'center', marginTop: 20 },
});
