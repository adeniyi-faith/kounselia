import { router } from 'expo-router';
import { KeyboardAvoidingView, Platform, ScrollView, Text } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { ScreenHeader } from '@/components/ScreenHeader';
import { ApplyForm } from '@/professional/ApplyForm';
import { fonts, makeStyles } from '@/theme';

// "Join as a professional" for someone already signed in: a member who
// also wants to see clients, or a professional reapplying after their
// application wasn't approved. Account details are already known.
export default function ApplySignedIn() {
  const styles = useStyles();
  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right', 'bottom']}>
      <ScreenHeader title="Join as a professional" />
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled" keyboardDismissMode="interactive">
          <Text style={styles.title}>Bring your practice to Kounselia</Text>
          <Text style={styles.subtitle}>Tell us about your practice and upload your credentials. We review every application by hand before you can see clients here.</Text>
          <ApplyForm withAccount={false} onDone={() => router.replace('/pro')} />
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const useStyles = makeStyles((colors) => ({
  safe: { flex: 1, backgroundColor: colors.bg },
  content: { padding: 24, paddingTop: 8, paddingBottom: 40 },
  title: { fontFamily: fonts.serif, fontSize: 30, color: colors.text, marginBottom: 8 },
  subtitle: { fontFamily: fonts.light, fontSize: 15, lineHeight: 24, color: colors.text2, marginBottom: 24 },
}));
