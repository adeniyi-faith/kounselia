// The website's "Join as a professional" form (apply.php): professional
// details, the rate they set themselves, and verification documents.
// Signed out, it asks for the account details too and creates the account
// in the same step. Used by app/(auth)/apply.tsx and app/(app)/apply.tsx.
import type { PickedDocument } from '@kounselia/core';
import * as Haptics from 'expo-haptics';
import { useRef, useState } from 'react';
import { Pressable, Text, View, type TextInput } from 'react-native';
import { Button } from '@/components/Button';
import { showDialog } from '@/components/Dialog';
import { FormMessage } from '@/components/FormMessage';
import { TablerIcon } from '@/components/TablerIcon';
import { TextField } from '@/components/TextField';
import { useSession } from '@/session';
import { fonts, makeStyles, radius, useColors } from '@/theme';
import { pickDocumentFile, pickDocumentPhoto, type PickResult } from './documents';

export function ApplyForm({ withAccount, onDone }: { withAccount: boolean; onDone?: () => void }) {
  const styles = useStyles();
  const { applyAsProfessional } = useSession();
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [title, setTitle] = useState('');
  const [license, setLicense] = useState('');
  const [years, setYears] = useState('');
  const [specialty, setSpecialty] = useState('');
  const [bio, setBio] = useState('');
  const [rate, setRate] = useState('');
  const [licenseDoc, setLicenseDoc] = useState<PickedDocument | null>(null);
  const [idDoc, setIdDoc] = useState<PickedDocument | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const emailRef = useRef<TextInput>(null);
  const passwordRef = useRef<TextInput>(null);

  function fail(message: string) {
    setError(message);
    Haptics.notificationAsync(Haptics.NotificationFeedbackType.Error).catch(() => undefined);
  }

  async function submit() {
    if (withAccount) {
      if (!name.trim() || !email.trim() || !password) return fail('Please fill in your name, email, and password.');
      if (password.length < 8) return fail('Please choose a password of at least 8 characters.');
    }
    if (!title.trim()) return fail('Please enter your professional title.');
    if (!(Number(rate) > 0)) return fail('Please enter your rate per session.');
    if (!licenseDoc) return fail('Please upload a license or credential document.');
    setBusy(true);
    setError(null);
    const problem = await applyAsProfessional(
      { title: title.trim(), license_number: license.trim(), specialty: specialty.trim(), years_experience: years.trim(), bio: bio.trim(), rate_amount: rate.trim() },
      licenseDoc,
      idDoc,
      withAccount ? { name: name.trim(), email: email.trim(), password } : undefined,
    );
    setBusy(false);
    if (problem) return fail(problem);
    Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => undefined);
    showDialog({
      title: 'Application submitted',
      message: 'Thanks for applying. We’ll review your documents and email you, usually within a few business days. Meanwhile you can get your profile ready.',
      icon: 'check',
      tone: 'success',
    });
    onDone?.();
  }

  return (
    <View>
      {withAccount && (
        <>
          <Text style={styles.h3}>Your account</Text>
          <Text style={styles.note}>You’ll use this to sign in and manage your profile.</Text>
          <TextField
            label="Full name"
            value={name}
            onChangeText={setName}
            placeholder="Jane Okafor"
            autoComplete="name"
            textContentType="name"
            returnKeyType="next"
            onSubmitEditing={() => emailRef.current?.focus()}
            submitBehavior="submit"
          />
          <TextField
            ref={emailRef}
            label="Email address"
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
          />
        </>
      )}

      <Text style={styles.h3}>Professional details</Text>
      <TextField label="Professional title" value={title} onChangeText={setTitle} placeholder="e.g. Licensed Clinical Psychologist" />
      <TextField label="License / registration number" value={license} onChangeText={setLicense} placeholder="Optional, if applicable" autoCapitalize="characters" />
      <TextField label="Years of experience" value={years} onChangeText={(t) => setYears(t.replace(/\D/g, '').slice(0, 2))} keyboardType="number-pad" placeholder="e.g. 8" />
      <TextField label="Specialty" value={specialty} onChangeText={setSpecialty} placeholder="e.g. Anxiety, trauma, relationships" />
      <TextField label="Short bio" value={bio} onChangeText={setBio} multiline textAlignVertical="top" style={{ minHeight: 100 }} placeholder="A couple of sentences clients will see on your profile." />

      <Text style={styles.h3}>Your rate</Text>
      <Text style={styles.note}>You set this. Kounselia doesn’t set it for you. You can change it later from your profile.</Text>
      <TextField label="Rate per session (₦)" value={rate} onChangeText={(t) => setRate(t.replace(/[^\d.]/g, ''))} keyboardType="decimal-pad" placeholder="e.g. 15000" />

      <Text style={styles.h3}>Verification documents</Text>
      <Text style={styles.note}>PDF, JPG, or PNG, up to 8MB each. We review every application by hand before you can see clients.</Text>
      <DocumentSlot label="License or credential document (required)" doc={licenseDoc} onPicked={setLicenseDoc} onError={fail} />
      <DocumentSlot label="Government-issued ID (optional, speeds up review)" doc={idDoc} onPicked={setIdDoc} onError={fail} />

      {error ? <FormMessage tone="error" text={error} /> : null}
      <Button title="Submit application" onPress={submit} busy={busy} style={{ marginTop: 8 }} />
    </View>
  );
}

function DocumentSlot({
  label,
  doc,
  onPicked,
  onError,
}: {
  label: string;
  doc: PickedDocument | null;
  onPicked: (doc: PickedDocument | null) => void;
  onError: (message: string) => void;
}) {
  const styles = useStyles();
  const colors = useColors();

  async function takeFrom(pick: () => Promise<PickResult>) {
    // Let the question box finish closing first: a phone won't open its
    // file picker over another pop-up.
    await new Promise((resolve) => setTimeout(resolve, 350));
    const res = await pick();
    if (!res) return;
    if (res.ok) onPicked(res.doc);
    else onError(res.message);
  }

  function choose() {
    showDialog({
      title: 'Add a document',
      message: 'A PDF or a clear picture of it.',
      icon: 'file-text',
      buttons: [
        { text: 'Choose a file', onPress: () => takeFrom(pickDocumentFile) },
        { text: 'Choose a photo', onPress: () => takeFrom(pickDocumentPhoto) },
        { text: 'Cancel', style: 'cancel' },
      ],
    });
  }

  return (
    <View style={styles.slot}>
      <Text style={styles.slotLabel}>{label}</Text>
      {doc ? (
        <View style={styles.slotDoc}>
          <TablerIcon name="file-text" size={18} color={colors.accentText} />
          <Text style={styles.slotName} numberOfLines={1}>
            {doc.name}
          </Text>
          <Pressable onPress={() => onPicked(null)} accessibilityRole="button" accessibilityLabel={`Remove ${doc.name}`} hitSlop={10}>
            <TablerIcon name="x" size={18} color={colors.text3} />
          </Pressable>
        </View>
      ) : (
        <Pressable onPress={choose} accessibilityRole="button" style={styles.slotPick}>
          <TablerIcon name="upload" size={18} color={colors.accentText} />
          <Text style={styles.slotPickText}>Choose a file or photo</Text>
        </Pressable>
      )}
    </View>
  );
}

const useStyles = makeStyles((colors) => ({
  h3: { fontFamily: fonts.semibold, fontSize: 15, color: colors.text, marginTop: 10, marginBottom: 4 },
  note: { fontFamily: fonts.regular, fontSize: 13, lineHeight: 19, color: colors.text3, marginBottom: 14 },
  slot: { borderWidth: 1.5, borderStyle: 'dashed', borderColor: colors.border, borderRadius: radius.field, padding: 14, marginBottom: 12 },
  slotLabel: { fontFamily: fonts.medium, fontSize: 13, color: colors.text2, marginBottom: 10 },
  slotDoc: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  slotName: { flex: 1, fontFamily: fonts.medium, fontSize: 14, color: colors.text },
  slotPick: { flexDirection: 'row', alignItems: 'center', gap: 8, alignSelf: 'flex-start', paddingVertical: 8, paddingHorizontal: 14, borderRadius: 50, backgroundColor: colors.accentLight },
  slotPickText: { fontFamily: fonts.medium, fontSize: 13.5, color: colors.accentText },
}));
