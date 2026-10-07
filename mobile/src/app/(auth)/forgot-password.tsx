import { forgotPassword } from '@kounselia/core';
import { useState } from 'react';
import { Button } from '@/components/Button';
import { FormMessage } from '@/components/FormMessage';
import { FormScreen } from '@/components/FormScreen';
import { TextField } from '@/components/TextField';
import { useT } from '@/language';
import { useSession } from '@/session';

// Sends the same reset email as the website; its link opens the website's
// reset page, and the new password then works in the app too.
export default function ForgotPassword() {
  const { config } = useSession();
  const t = useT();
  const [email, setEmail] = useState('');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ tone: 'error' | 'success'; text: string } | null>(null);

  async function submit() {
    if (!email.trim()) {
      setMessage({ tone: 'error', text: t('m.auth.enter_email') });
      return;
    }
    setBusy(true);
    setMessage(null);
    try {
      const result = await forgotPassword(config, email.trim(), '');
      setMessage(
        result.success
          ? { tone: 'success', text: t('m.auth.reset_sent', { email: email.trim() }) }
          : { tone: 'error', text: result.message || t('m.auth.went_wrong') },
      );
    } catch {
      setMessage({ tone: 'error', text: t('m.auth.no_connection') });
    }
    setBusy(false);
  }

  return (
    <FormScreen title={t('m.auth.reset_title')} subtitle={t('m.auth.reset_sub')}>
      <TextField
        label={t('m.auth.email')}
        value={email}
        onChangeText={setEmail}
        placeholder="you@example.com"
        keyboardType="email-address"
        autoCapitalize="none"
        autoComplete="email"
        textContentType="emailAddress"
        returnKeyType="send"
        onSubmitEditing={submit}
      />
      {message ? <FormMessage tone={message.tone} text={message.text} /> : null}
      <Button title={t('m.auth.send_reset')} onPress={submit} busy={busy} />
    </FormScreen>
  );
}
