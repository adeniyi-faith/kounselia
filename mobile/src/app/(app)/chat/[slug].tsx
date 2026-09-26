import type { CounselorSummary } from '@kounselia/core';
import * as Haptics from 'expo-haptics';
import { router, useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { ActivityIndicator, Alert, FlatList, Keyboard, KeyboardAvoidingView, Platform, Share, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useChat, type AppMessage } from '@/chat/useChat';
import { Button } from '@/components/Button';
import { ChatHeader } from '@/components/chat/ChatHeader';
import { Composer } from '@/components/chat/Composer';
import { formatTime } from '@/components/chat/formatTime';
import { MessageBubble } from '@/components/chat/MessageBubble';
import { Toast, useToast } from '@/components/chat/Toast';
import { TypingIndicator } from '@/components/chat/TypingIndicator';
import { useCounselors } from '@/counselors';
import { useSession } from '@/session';
import { colors, fonts } from '@/theme';

function goBack() {
  if (router.canGoBack()) router.back();
  else router.replace('/talk');
}

// Waits for the counselor list, then opens the conversation.
export default function ChatRoute() {
  const { slug } = useLocalSearchParams<{ slug: string }>();
  const { status, bySlug } = useCounselors();
  const counselor = bySlug(slug);

  if (counselor) return <Conversation key={counselor.slug} counselor={counselor} />;

  return (
    <View style={styles.centerScreen}>
      {status === 'loading' ? (
        <ActivityIndicator color={colors.accent} />
      ) : (
        <>
          <Text style={styles.notice}>
            {status === 'error'
              ? "We couldn't reach Kounselia. Please check your internet connection."
              : "This counselor isn't available right now."}
          </Text>
          <Button title="Back" variant="ghost" onPress={goBack} />
        </>
      )}
    </View>
  );
}

// The home-bar gap under the message box is only needed while the
// keyboard is closed; with it open, the box sits right on the keyboard.
function useKeyboardOpen() {
  const [open, setOpen] = useState(false);
  useEffect(() => {
    const show = Keyboard.addListener(Platform.OS === 'ios' ? 'keyboardWillShow' : 'keyboardDidShow', () => setOpen(true));
    const hide = Keyboard.addListener(Platform.OS === 'ios' ? 'keyboardWillHide' : 'keyboardDidHide', () => setOpen(false));
    return () => {
      show.remove();
      hide.remove();
    };
  }, []);
  return open;
}

function Conversation({ counselor }: { counselor: CounselorSummary }) {
  const { config } = useSession();
  const chat = useChat(config, counselor);
  const toast = useToast();
  const insets = useSafeAreaInsets();
  const keyboardOpen = useKeyboardOpen();

  useEffect(() => {
    chat.load().then((ok) => {
      if (!ok) toast.show("Couldn't load your earlier messages.");
    });
    // Load once per conversation.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Newest first, because the list is drawn upside down so it starts at
  // the bottom and grows upwards like any messaging app.
  const data = useMemo(() => [...chat.messages].reverse(), [chat.messages]);

  const onRate = useCallback(
    async (message: AppMessage, rating: 'up' | 'down') => {
      if (message.rating === rating) return;
      Haptics.selectionAsync().catch(() => undefined);
      const ok = await chat.rate(message, rating);
      toast.show(ok ? (rating === 'up' ? 'Thanks for the feedback!' : 'Feedback recorded.') : "Couldn't save your feedback.");
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [chat.rate],
  );

  function share() {
    const lines = chat.messages.map(
      (m) => `[${formatTime(m.createdAt)}] ${m.sender === 'user' ? 'You' : counselor.name}: ${m.text}`,
    );
    if (lines.length === 0) {
      toast.show('No messages to share.');
      return;
    }
    Share.share({ message: `Conversation with ${counselor.name} on Kounselia\n\n${lines.join('\n\n')}` }).catch(() => undefined);
  }

  function confirmClear() {
    Alert.alert(
      'Clear this conversation?',
      'It will be removed from your chat history and cannot be brought back.',
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Clear',
          style: 'destructive',
          onPress: async () => {
            const ok = await chat.clear();
            toast.show(ok ? 'Conversation cleared.' : "Couldn't clear the chat. Please check your connection.");
          },
        },
      ],
    );
  }

  return (
    <View style={styles.screen}>
      <ChatHeader counselor={counselor} onBack={goBack} onShare={share} onClear={confirmClear} />
      <KeyboardAvoidingView style={styles.flex} behavior="padding">
        {chat.phase === 'loading' ? (
          <View style={styles.centerFill}>
            <ActivityIndicator color={colors.accent} />
            <Text style={styles.loadingText}>Connecting you with {counselor.name}…</Text>
          </View>
        ) : (
          <FlatList
            inverted
            data={data}
            keyExtractor={(m) => m.id}
            renderItem={({ item }) => (
              <MessageBubble message={item} counselor={counselor} onRate={onRate} onRetry={chat.retry} onNotify={toast.show} />
            )}
            ListHeaderComponent={chat.typing ? <TypingIndicator counselor={counselor} /> : null}
            ItemSeparatorComponent={() => <View style={styles.separator} />}
            ListHeaderComponentStyle={chat.typing ? styles.typingGap : undefined}
            contentContainerStyle={styles.list}
            keyboardDismissMode="interactive"
            keyboardShouldPersistTaps="handled"
          />
        )}
        <View style={[styles.footer, { paddingBottom: keyboardOpen ? 0 : insets.bottom }]}>
          <Composer busy={chat.typing || chat.phase === 'loading'} onSend={chat.send} />
        </View>
      </KeyboardAvoidingView>
      <Toast message={toast.message} />
    </View>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.bg },
  flex: { flex: 1 },
  list: { paddingHorizontal: 16, paddingVertical: 20 },
  separator: { height: 18 },
  typingGap: { marginTop: 18 },
  // .chat-footer (the website's warm sand dock)
  footer: { backgroundColor: '#EFEAE2', borderTopWidth: 1, borderTopColor: colors.border },
  centerFill: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 12 },
  loadingText: { fontFamily: fonts.regular, fontSize: 14, color: colors.text2 },
  centerScreen: { flex: 1, backgroundColor: colors.bg, alignItems: 'center', justifyContent: 'center', gap: 16, padding: 24 },
  notice: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 22, color: colors.text2, textAlign: 'center' },
});
