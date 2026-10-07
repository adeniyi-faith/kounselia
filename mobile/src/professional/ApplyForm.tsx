// The website's "Join as a professional" form (apply.php): professional
// details, the rate they set themselves, and verification documents.
// Signed out, it asks for the account details too and creates the account
// in the same step. Used by app/(auth)/apply.tsx and app/(app)/apply.tsx.
import type { PickedDocument, Translate } from '@kounselia/core';
import * as Haptics from 'expo-haptics';
import { useRef, useState } from 'react';
import { Pressable, Text, View, type TextInput } from 'react-native';
import { Button } from '@/components/Button';
import { showDialog } from '@/components/Dialog';
import { FormMessage } from '@/components/FormMessage';
import { TablerIcon } from '@/components/TablerIcon';
import { TextField } from '@/components/TextField';
import { useT } from '@/language';
import { useSession } from '@/session';
import { fonts, makeStyles, radius, useColors } from '@/theme';
import { pickDocumentFile, pickDocumentPhoto, type PickResult } from './documents';

export function ApplyForm({ withAccount, onDone }: { withAccount: boolean; onDone?: () => void }) {
  const styles = useStyles();
  const { applyAsProfessional } = useSession();
  const t = useT();
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
      if (!name.trim() || !email.trim() || !password) return fail(t('m.pro.apply_need_account'));
      if (password.length < 8) return fail(t('m.pro.apply_short_password'));
    }
    if (!title.trim()) return fail(t('m.pro.apply_need_title'));
    if (!(Number(rate) > 0)) return fail(t('m.pro.apply_need_rate'));
    if (!licenseDoc) return fail(t('m.pro.apply_need_license'));
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
      title: t('m.pro.apply_done_title'),
      message: t('m.pro.apply_done_body'),
      icon: 'check',
      tone: 'success',
    });
    onDone?.();
  }

  return (
    <View>
      {withAccount && (
        <>
          <Text style={styles.h3}>{t('m.pro.apply_your_account')}</Text>
          <Text style={styles.note}>{t('m.pro.apply_account_note')}</Text>
          <TextField
            label={t('m.pro.apply_full_name')}
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
            label={t('m.pro.apply_email')}
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
            label={t('m.pro.apply_password')}
            password
            value={password}
            onChangeText={setPassword}
            placeholder={t('m.pro.apply_password_hint')}
            autoComplete="new-password"
            textContentType="newPassword"
          />
        </>
      )}

      <Text style={styles.h3}>{t('m.pro.apply_details')}</Text>
      <TextField label={t('m.pro.f_title')} value={title} onChangeText={setTitle} placeholder={t('m.pro.f_title_hint')} />
      <TextField label={t('m.pro.f_license')} value={license} onChangeText={setLicense} placeholder={t('m.pro.f_license_hint')} autoCapitalize="characters" />
      <TextField label={t('m.pro.f_years')} value={years} onChangeText={(t) => setYears(t.replace(/\D/g, '').slice(0, 2))} keyboardType="number-pad" placeholder={t('m.pro.f_years_hint')} />
      <TextField label={t('m.pro.f_specialty')} value={specialty} onChangeText={setSpecialty} placeholder={t('m.pro.f_specialty_hint')} />
      <TextField label={t('m.pro.f_bio')} value={bio} onChangeText={setBio} multiline textAlignVertical="top" style={{ minHeight: 100 }} placeholder={t('m.pro.f_bio_hint')} />

      <Text style={styles.h3}>{t('m.pro.apply_rate')}</Text>
      <Text style={styles.note}>{t('m.pro.apply_rate_note')}</Text>
      <TextField label={t('m.pro.f_rate')} value={rate} onChangeText={(t) => setRate(t.replace(/[^\d.]/g, ''))} keyboardType="decimal-pad" placeholder={t('m.pro.f_rate_hint')} />

      <Text style={styles.h3}>{t('m.pro.apply_docs')}</Text>
      <Text style={styles.note}>{t('m.pro.apply_docs_note')}</Text>
      <DocumentSlot label={t('m.pro.apply_license_slot')} doc={licenseDoc} onPicked={setLicenseDoc} onError={fail} />
      <DocumentSlot label={t('m.pro.apply_id_slot')} doc={idDoc} onPicked={setIdDoc} onError={fail} />

      {error ? <FormMessage tone="error" text={error} /> : null}
      <Button title={t('m.pro.apply_submit')} onPress={submit} busy={busy} style={{ marginTop: 8 }} />
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
  const t = useT();

  async function takeFrom(pick: (t: Translate) => Promise<PickResult>) {
    // Let the question box finish closing first: a phone won't open its
    // file picker over another pop-up.
    await new Promise((resolve) => setTimeout(resolve, 350));
    const res = await pick(t);
    if (!res) return;
    if (res.ok) onPicked(res.doc);
    else onError(res.message);
  }

  function choose() {
    showDialog({
      title: t('m.pro.doc_add_title'),
      message: t('m.pro.doc_add_body'),
      icon: 'file-text',
      buttons: [
        { text: t('m.pro.doc_choose_file'), onPress: () => takeFrom(pickDocumentFile) },
        { text: t('m.pro.doc_choose_photo'), onPress: () => takeFrom(pickDocumentPhoto) },
        { text: t('m.pro.cancel'), style: 'cancel' },
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
          <Pressable onPress={() => onPicked(null)} accessibilityRole="button" accessibilityLabel={t('m.pro.doc_remove_named', { name: doc.name })} hitSlop={10}>
            <TablerIcon name="x" size={18} color={colors.text3} />
          </Pressable>
        </View>
      ) : (
        <Pressable onPress={choose} accessibilityRole="button" style={styles.slotPick}>
          <TablerIcon name="upload" size={18} color={colors.accentText} />
          <Text style={styles.slotPickText}>{t('m.pro.doc_choose_either')}</Text>
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
