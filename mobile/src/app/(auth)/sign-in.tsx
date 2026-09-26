import * as Haptics from 'expo-haptics';
import { Link } from 'expo-router';
import { useRef, useState } from 'react';
import { StyleSheet, Text, type TextInput } from 'react-native';
import { Button } from '@/components/Button';
import { FormMessage } from '@/components/FormMessage';
import { FormScreen } from '@/components/FormScreen';
import { TextField } from '@/components/TextField';
import { useSession } from '@/session';
import { colors, fonts } from '@/theme';

export default function SignIn() {
  const { signIn } = useSession();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const passwordRef = useRef<TextInput>(null);

  async function submit() {
    if (!email.trim() || !password) {
      setError('Please enter both email and password.');
      return;
    }
    setBusy(true);
    setError(null);
    const result = await signIn(email.trim(), password);
    // On success the app switches to the signed-in screens by itself.
    if (!result.success) {
      setBusy(false);
      setError(result.message);
      Haptics.notificationAsync(Haptics.NotificationFeedbackType.Error).catch(() => undefined);
    }
  }

  return (
    <FormScreen title="Welcome back" subtitle="Sign in to pick up where you left off.">
      <TextField
        label="Email"
        value={email}
        onChangeText={setEmail}
        placeholder="you@example.com"
        keyboardType="email-address"
        autoCapitalize="none"
        autoComplete="email"
        textContentType="emailAddress"
        returnKeyType="next"
        onSubmitEditing={() => passwordRef.current?.focus()}
        submitBehavior="submit"
      />
      <TextField
        ref={passwordRef}
        label="Password"
        password
        value={password}
        onChangeText={setPassword}
        placeholder="••••••••"
        autoComplete="current-password"
        textContentType="password"
        returnKeyType="go"
        onSubmitEditing={submit}
      />
      <Link href="/forgot-password" style={styles.forgot}>
        Forgot your password?
      </Link>
      {error ? <FormMessage tone="error" text={error} /> : null}
      <Button title="Sign in" onPress={submit} busy={busy} />
      <Text style={styles.switch}>
        New to Kounselia?{' '}
        <Link href="/sign-up" replace style={styles.link}>
          Create an account
        </Link>
      </Text>
    </FormScreen>
  );
}

const styles = StyleSheet.create({
  forgot: { alignSelf: 'flex-end', fontFamily: fonts.medium, fontSize: 13, color: colors.accent, marginTop: -6, marginBottom: 20 },
  switch: { textAlign: 'center', marginTop: 20, fontFamily: fonts.regular, fontSize: 14, color: colors.text2 },
  link: { color: colors.accent, fontFamily: fonts.medium },
});
