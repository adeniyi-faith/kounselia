import * as Haptics from 'expo-haptics';
import { Link, router } from 'expo-router';
import { useRef, useState } from 'react';
import { Keyboard, Text, type TextInput } from 'react-native';
import { Button } from '@/components/Button';
import { FormMessage } from '@/components/FormMessage';
import { FormScreen } from '@/components/FormScreen';
import { TextField } from '@/components/TextField';
import { useT } from '@/language';
import { useSession } from '@/session';
import { fonts, makeStyles } from '@/theme';

export default function SignIn() {
  const styles = useStyles();
  const t = useT();
  const { signIn } = useSession();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const passwordRef = useRef<TextInput>(null);

  async function submit() {
    if (!email.trim() || !password) {
      setError(t('m.auth.enter_both'));
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
    <FormScreen title={t('m.auth.welcome_back')} subtitle={t('m.auth.sign_in_sub')}>
      <TextField
        label={t('m.auth.email')}
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
        label={t('m.auth.password')}
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
        {t('m.auth.forgot')}
      </Link>
      {error ? <FormMessage tone="error" text={error} /> : null}
      <Button title={t('m.auth.sign_in')} onPress={submit} busy={busy} />
      <Text style={styles.switch}>
        {t('m.auth.new_to')}{' '}
        <Text
          accessibilityRole="link"
          style={styles.link}
          onPress={() => {
            // Put the keyboard away first, then cross-fade to the other form
            // in place (see "switched" in app/(auth)/_layout.tsx).
            Keyboard.dismiss();
            router.replace({ pathname: '/sign-up', params: { switched: '1' } });
          }}
        >
          {t('m.auth.create_an_account')}
        </Text>
      </Text>
    </FormScreen>
  );
}

const useStyles = makeStyles((colors) => ({
  forgot: { alignSelf: 'flex-end', fontFamily: fonts.medium, fontSize: 13, color: colors.accentText, marginTop: -6, marginBottom: 20 },
  switch: { textAlign: 'center', marginTop: 20, fontFamily: fonts.regular, fontSize: 14, color: colors.text2 },
  link: { color: colors.accentText, fontFamily: fonts.medium },
}));
