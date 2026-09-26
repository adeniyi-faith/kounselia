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

export default function SignUp() {
  const { signUp } = useSession();
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const emailRef = useRef<TextInput>(null);
  const passwordRef = useRef<TextInput>(null);

  async function submit() {
    if (!email.trim() || !password) {
      setError('Please enter both email and password.');
      return;
    }
    if (password.length < 8) {
      setError('Please choose a password of at least 8 characters.');
      return;
    }
    setBusy(true);
    setError(null);
    const result = await signUp(name.trim(), email.trim(), password);
    if (!result.success) {
      setBusy(false);
      setError(result.message);
      Haptics.notificationAsync(Haptics.NotificationFeedbackType.Error).catch(() => undefined);
    }
  }

  return (
    <FormScreen title="Create your account" subtitle="Free to start. Your conversations stay private.">
      <TextField
        label="Your name"
        value={name}
        onChangeText={setName}
        placeholder="What should we call you?"
        autoComplete="name"
        textContentType="givenName"
        returnKeyType="next"
        onSubmitEditing={() => emailRef.current?.focus()}
        submitBehavior="submit"
      />
      <TextField
        ref={emailRef}
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
        placeholder="At least 8 characters"
        autoComplete="new-password"
        textContentType="newPassword"
        returnKeyType="go"
        onSubmitEditing={submit}
      />
      {error ? <FormMessage tone="error" text={error} /> : null}
      <Button title="Create account" onPress={submit} busy={busy} />
      <Text style={styles.switch}>
        Already have an account?{' '}
        <Link href="/sign-in" replace style={styles.link}>
          Sign in
        </Link>
      </Text>
    </FormScreen>
  );
}

const styles = StyleSheet.create({
  switch: { textAlign: 'center', marginTop: 20, fontFamily: fonts.regular, fontSize: 14, color: colors.text2 },
  link: { color: colors.accent, fontFamily: fonts.medium },
});
