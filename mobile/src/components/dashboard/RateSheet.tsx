// "Rate this session": five stars and an optional comment, sent with the
// website's kounselia_submit_review action.
import { submitReview, type KounseliaConfig } from '@kounselia/core';
import * as Haptics from 'expo-haptics';
import { useState } from 'react';
import { KeyboardAvoidingView, Modal, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { colors, fonts } from '@/theme';
import { Button } from '../Button';
import { FormMessage } from '../FormMessage';
import { TablerIcon } from '../TablerIcon';

interface Props {
  config: KounseliaConfig;
  booking: { id: number; pro_name: string } | null;
  onClose: (rated: boolean) => void;
}

export function RateSheet({ config, booking, onClose }: Props) {
  const insets = useSafeAreaInsets();
  const [stars, setStars] = useState(0);
  const [comment, setComment] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function close(rated: boolean) {
    setStars(0);
    setComment('');
    setError(null);
    onClose(rated);
  }

  async function send() {
    if (!booking || !stars) return;
    setBusy(true);
    setError(null);
    const res = await submitReview(config, booking.id, stars, comment.trim());
    setBusy(false);
    if (res.ok) close(true);
    else setError(res.message);
  }

  return (
    <Modal visible={!!booking} transparent animationType="slide" onRequestClose={() => close(false)}>
      <Pressable style={styles.backdrop} onPress={() => close(false)} accessibilityLabel="Close" />
      <KeyboardAvoidingView behavior="padding" style={styles.sheetWrap} pointerEvents="box-none">
        <View style={[styles.sheet, { paddingBottom: insets.bottom + 20 }]}>
          <View style={styles.handle} />
          <Text style={styles.title}>How was your session?</Text>
          <Text style={styles.sub}>With {booking?.pro_name}. Your rating helps other members choose.</Text>
          <View style={styles.stars} accessibilityRole="adjustable" accessibilityLabel={`${stars} out of 5 stars`}>
            {[1, 2, 3, 4, 5].map((n) => (
              <Pressable
                key={n}
                onPress={() => {
                  Haptics.selectionAsync().catch(() => undefined);
                  setStars(n);
                }}
                accessibilityRole="button"
                accessibilityLabel={`${n} star${n > 1 ? 's' : ''}`}
                hitSlop={4}
              >
                <TablerIcon name={n <= stars ? 'star-filled' : 'star'} size={36} color={n <= stars ? colors.gold : colors.border} />
              </Pressable>
            ))}
          </View>
          <TextInput
            value={comment}
            onChangeText={setComment}
            placeholder="Anything you'd like to share? (optional)"
            placeholderTextColor={colors.text3}
            multiline
            maxLength={1000}
            style={styles.input}
            textAlignVertical="top"
          />
          {error ? <FormMessage tone="error" text={error} /> : null}
          <Button title="Send rating" onPress={send} busy={busy} disabled={!stars} />
        </View>
      </KeyboardAvoidingView>
    </Modal>
  );
}

const styles = StyleSheet.create({
  backdrop: { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, backgroundColor: 'rgba(24,22,15,0.45)' },
  sheetWrap: { flex: 1, justifyContent: 'flex-end' },
  sheet: { backgroundColor: colors.surface, borderTopLeftRadius: 28, borderTopRightRadius: 28, padding: 24, gap: 14 },
  handle: { alignSelf: 'center', width: 40, height: 5, borderRadius: 3, backgroundColor: colors.border, marginBottom: 6 },
  title: { fontFamily: fonts.serif, fontSize: 28, color: colors.text },
  sub: { fontFamily: fonts.light, fontSize: 15, lineHeight: 22, color: colors.text2 },
  stars: { flexDirection: 'row', justifyContent: 'center', gap: 10, marginVertical: 6 },
  input: {
    minHeight: 90,
    borderWidth: 1.5,
    borderColor: colors.border,
    borderRadius: 14,
    padding: 14,
    backgroundColor: colors.bg,
    fontFamily: fonts.regular,
    fontSize: 15,
    color: colors.text,
  },
});
