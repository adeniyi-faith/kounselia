import { fetchBookingMessages, sendBookingMessage, type BookingMessage } from '@kounselia/core';
import * as Haptics from 'expo-haptics';
import { router, useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { ActivityIndicator, FlatList, KeyboardAvoidingView, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { formatTime } from '@/components/chat/formatTime';
import { Toast, useToast } from '@/components/chat/Toast';
import { TablerIcon } from '@/components/TablerIcon';
import { useSession } from '@/session';
import { colors, fonts } from '@/theme';

// Messages with the professional for one booked session (the website's
// "Message" button on a booking). New messages are checked every 15
// seconds while this screen is open.
export default function BookingMessages() {
  const { id, name } = useLocalSearchParams<{ id: string; name?: string }>();
  const bookingId = Number(id);
  const { config } = useSession();
  const [messages, setMessages] = useState<BookingMessage[] | null>(null);
  const [text, setText] = useState('');
  const [sending, setSending] = useState(false);
  const toast = useToast();

  const load = useCallback(async () => {
    const res = await fetchBookingMessages(config, bookingId);
    if (res.ok) setMessages(res.data.messages);
    else if (!res.offline) toast.show(res.message);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [config, bookingId]);

  useEffect(() => {
    // Loading from the server when the screen opens is what this effect is for.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
    const timer = setInterval(load, 15000);
    return () => clearInterval(timer);
  }, [load]);

  async function send() {
    const content = text.trim();
    if (!content || sending) return;
    setSending(true);
    const res = await sendBookingMessage(config, bookingId, content);
    setSending(false);
    if (!res.ok) {
      toast.show(res.message);
      return;
    }
    Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light).catch(() => undefined);
    setText('');
    load();
  }

  const data = useMemo(() => [...(messages ?? [])].reverse(), [messages]);

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right', 'bottom']}>
      <View style={styles.nav}>
        <Pressable onPress={() => router.back()} accessibilityRole="button" accessibilityLabel="Back" style={styles.back} hitSlop={6}>
          <TablerIcon name="arrow-left" size={20} color={colors.text} />
        </Pressable>
        <View style={{ flex: 1 }}>
          <Text style={styles.navTitle} numberOfLines={1}>
            {name || 'Your professional'}
          </Text>
          <Text style={styles.navSub}>About your booked session</Text>
        </View>
      </View>
      <KeyboardAvoidingView style={{ flex: 1 }} behavior="padding">
        {messages === null ? (
          <ActivityIndicator color={colors.accent} style={{ flex: 1 }} />
        ) : (
          <FlatList
            inverted
            data={data}
            keyExtractor={(m) => String(m.id)}
            contentContainerStyle={styles.list}
            ItemSeparatorComponent={() => <View style={{ height: 12 }} />}
            ListEmptyComponent={
              <Text style={[styles.empty, { transform: [{ scaleY: -1 }] }]}>
                No messages yet. Say hello, or share anything they should know before your session.
              </Text>
            }
            renderItem={({ item }) => (
              <View style={[styles.msgWrap, item.is_mine ? styles.mine : styles.theirs]}>
                <View style={[styles.bubble, item.is_mine ? styles.bubbleMine : styles.bubbleTheirs]}>
                  <Text style={[styles.msgText, item.is_mine && { color: '#fff' }]}>{item.content}</Text>
                </View>
                <Text style={styles.time}>{item.sent_at ? formatTime(Date.parse(item.sent_at)) : ''}</Text>
              </View>
            )}
          />
        )}
        <View style={styles.composer}>
          <TextInput
            value={text}
            onChangeText={setText}
            placeholder="Message"
            placeholderTextColor="#94A3B8"
            multiline
            maxLength={2000}
            style={styles.input}
            accessibilityLabel="Message"
          />
          <Pressable
            onPress={send}
            disabled={!text.trim() || sending}
            accessibilityRole="button"
            accessibilityLabel="Send"
            style={[styles.send, { backgroundColor: text.trim() ? colors.accent : colors.surface3 }]}
          >
            {sending ? <ActivityIndicator color="#fff" /> : <TablerIcon name="send" size={20} color={text.trim() ? '#fff' : colors.text3} />}
          </Pressable>
        </View>
      </KeyboardAvoidingView>
      <Toast note={toast.note} />
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.bg },
  nav: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: 16, paddingVertical: 10, borderBottomWidth: 1, borderBottomColor: colors.border, backgroundColor: colors.surface },
  back: { width: 40, height: 40, borderRadius: 12, backgroundColor: colors.surface2, borderWidth: 1, borderColor: colors.border, alignItems: 'center', justifyContent: 'center' },
  navTitle: { fontFamily: fonts.medium, fontSize: 16, color: colors.text },
  navSub: { fontFamily: fonts.regular, fontSize: 12, color: colors.text2, marginTop: 2 },
  list: { padding: 16 },
  empty: { fontFamily: fonts.regular, fontSize: 14, lineHeight: 21, color: colors.text3, textAlign: 'center', marginTop: 40, paddingHorizontal: 20 },
  msgWrap: { maxWidth: '82%' },
  mine: { alignSelf: 'flex-end', alignItems: 'flex-end' },
  theirs: { alignSelf: 'flex-start' },
  bubble: { paddingVertical: 10, paddingHorizontal: 14, borderRadius: 18 },
  bubbleMine: { backgroundColor: colors.accent, borderBottomRightRadius: 6 },
  bubbleTheirs: { backgroundColor: '#F0F4F8', borderWidth: 1, borderColor: '#DCE4EC', borderBottomLeftRadius: 4 },
  msgText: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 22, color: '#1E293B' },
  time: { fontFamily: fonts.regular, fontSize: 11, color: colors.text3, marginTop: 4, marginHorizontal: 4 },
  composer: { flexDirection: 'row', alignItems: 'flex-end', gap: 10, padding: 12, borderTopWidth: 1, borderTopColor: colors.border, backgroundColor: '#EFEAE2' },
  input: {
    flex: 1,
    maxHeight: 120,
    minHeight: 44,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 22,
    paddingHorizontal: 16,
    paddingTop: 11,
    paddingBottom: 11,
    fontFamily: fonts.regular,
    fontSize: 16,
    color: '#1E293B',
  },
  send: { width: 44, height: 44, borderRadius: 22, alignItems: 'center', justifyContent: 'center' },
});
