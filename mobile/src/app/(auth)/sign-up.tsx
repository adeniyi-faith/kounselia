import * as Haptics from 'expo-haptics';
import { router } from 'expo-router';
import { useRef, useState } from 'react';
import { Keyboard, Text, type TextInput } from 'react-native';
import { Button } from '@/components/Button';
import { FormMessage } from '@/components/FormMessage';
import { FormScreen } from '@/components/FormScreen';
import { TextField } from '@/components/TextField';
import { useT } from '@/language';
import { useSession } from '@/session';
import { fonts, makeStyles } from '@/theme';

export default function SignUp() {
  const styles = useStyles();
  const t = useT();
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
      setError(t('m.auth.enter_both'));
      return;
    }
    if (password.length < 8) {
      setError(t('m.auth.pw_too_short'));
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
    <FormScreen title={t('m.auth.create_your_account')} subtitle={t('m.auth.sign_up_sub')}>
      <TextField
        label={t('m.auth.your_name')}
        value={name}
        onChangeText={setName}
        placeholder={t('m.auth.name_placeholder')}
        autoComplete="name"
        textContentType="givenName"
        returnKeyType="next"
        onSubmitEditing={() => emailRef.current?.focus()}
        submitBehavior="submit"
      />
      <TextField
        ref={emailRef}
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
        placeholder={t('m.auth.pw_placeholder')}
        autoComplete="new-password"
        textContentType="newPassword"
        returnKeyType="go"
        onSubmitEditing={submit}
      />
      {error ? <FormMessage tone="error" text={error} /> : null}
      <Button title={t('m.auth.create_account')} onPress={submit} busy={busy} />
      <Text style={styles.switch}>
        {t('m.auth.have_account')}{' '}
        <Text
          accessibilityRole="link"
          style={styles.link}
          onPress={() => {
            // Put the keyboard away first, then cross-fade to the other form
            // in place (see "switched" in app/(auth)/_layout.tsx).
            Keyboard.dismiss();
            router.replace({ pathname: '/sign-in', params: { switched: '1' } });
          }}
        >
          {t('m.auth.sign_in')}
        </Text>
      </Text>
    </FormScreen>
  );
}

const useStyles = makeStyles((colors) => ({
  switch: { textAlign: 'center', marginTop: 20, fontFamily: fonts.regular, fontSize: 14, color: colors.text2 },
  link: { color: colors.accentText, fontFamily: fonts.medium },
}));
