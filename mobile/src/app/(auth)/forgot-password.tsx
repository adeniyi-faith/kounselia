import { forgotPassword } from '@kounselia/core';
import { useState } from 'react';
import { Button } from '@/components/Button';
import { FormMessage } from '@/components/FormMessage';
import { FormScreen } from '@/components/FormScreen';
import { TextField } from '@/components/TextField';
import { useSession } from '@/session';

// Sends the same reset email as the website; its link opens the website's
// reset page, and the new password then works in the app too.
export default function ForgotPassword() {
  const { config } = useSession();
  const [email, setEmail] = useState('');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ tone: 'error' | 'success'; text: string } | null>(null);

  async function submit() {
    if (!email.trim()) {
      setMessage({ tone: 'error', text: 'Please enter your email address.' });
      return;
    }
    setBusy(true);
    setMessage(null);
    try {
      const result = await forgotPassword(config, email.trim(), '');
      setMessage(
        result.success
          ? { tone: 'success', text: `If ${email.trim()} has an account, a reset link is on its way. Check your inbox.` }
          : { tone: 'error', text: result.message || 'Something went wrong. Please try again.' },
      );
    } catch {
      setMessage({ tone: 'error', text: "Couldn't connect. Please check your internet connection and try again." });
    }
    setBusy(false);
  }

  return (
    <FormScreen title="Reset your password" subtitle="Enter your email and we'll send you a link to choose a new one.">
      <TextField
        label="Email"
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
      <Button title="Send reset link" onPress={submit} busy={busy} />
    </FormScreen>
  );
}
