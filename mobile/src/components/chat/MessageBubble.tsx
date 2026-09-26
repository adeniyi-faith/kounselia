// One message, styled like the web chat (.msg, .msg-bubble): the member's
// on the right in navy, the counselor's on the left in soft blue-grey
// with their avatar, plus copy / helpful / not helpful under each reply.
import type { CounselorSummary } from '@kounselia/core';
import * as Clipboard from 'expo-clipboard';
import * as Haptics from 'expo-haptics';
import { LinearGradient } from 'expo-linear-gradient';
import { memo } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import type { AppMessage } from '@/chat/useChat';
import { colors, fonts } from '@/theme';
import { CounselorAvatar } from '../CounselorAvatar';
import { TablerIcon } from '../TablerIcon';
import { formatTime } from './formatTime';

interface Props {
  message: AppMessage;
  counselor: CounselorSummary;
  onRate: (message: AppMessage, rating: 'up' | 'down') => void;
  onRetry: (message: AppMessage) => void;
  onNotify: (text: string) => void;
}

// A reply's "**Heading**" paragraphs become headings and blank lines
// separate paragraphs — the same formatting the web chat applies.
function FormattedText({ text }: { text: string }) {
  return (
    <>
      {text.split(/\n\n/).map((para, i) => {
        const heading = para.match(/^\*\*(.*?)\*\*$/);
        if (heading) {
          return (
            <Text key={i} style={[styles.heading, i > 0 && styles.gap]}>
              {heading[1]}
            </Text>
          );
        }
        return (
          <Text key={i} style={[styles.aiText, i > 0 && styles.gap]}>
            {para}
          </Text>
        );
      })}
    </>
  );
}

export const MessageBubble = memo(function MessageBubble({ message, counselor, onRate, onRetry, onNotify }: Props) {
  async function copy() {
    try {
      await Clipboard.setStringAsync(message.text);
      Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light).catch(() => undefined);
      onNotify('Copied');
    } catch {
      onNotify("Couldn't copy the text.");
    }
  }

  if (message.sender === 'user') {
    return (
      <View style={styles.userRow}>
        <Pressable
          onLongPress={copy}
          onPress={message.failed ? () => onRetry(message) : undefined}
          accessibilityHint={message.failed ? 'Not sent. Double tap to try again.' : 'Long press to copy'}
          style={({ pressed }) => [styles.userWrap, pressed && { opacity: 0.85 }]}
        >
          <LinearGradient
            colors={[colors.accent, colors.accent2]}
            start={{ x: 0, y: 0 }}
            end={{ x: 1, y: 1 }}
            style={[styles.bubble, styles.userBubble, message.failed && styles.failedBubble]}
          >
            <Text style={styles.userText} selectable={false}>
              {message.text}
            </Text>
          </LinearGradient>
          {message.failed ? (
            <View style={styles.failedRow}>
              <TablerIcon name="alert-circle" size={13} color={colors.rose} />
              <Text style={styles.failedText}>Not sent. Tap to try again.</Text>
            </View>
          ) : (
            <Text style={[styles.time, styles.timeRight]}>{formatTime(message.createdAt)}</Text>
          )}
        </Pressable>
      </View>
    );
  }

  const canRate = !!message.messageId;
  return (
    <View style={styles.aiRow}>
      <View style={styles.avatar}>
        <CounselorAvatar icon={counselor.icon} color={counselor.color} size={32} />
      </View>
      <View style={styles.aiCol}>
        {message.consulted && message.consulted.length > 0 && (
          <View style={styles.consulted}>
            <TablerIcon name="users" size={11} color={colors.gold} />
            <Text style={styles.consultedText}>Consulted {message.consulted.join(' & ')}</Text>
          </View>
        )}
        <Pressable onLongPress={copy} accessibilityHint="Long press to copy">
          <View style={[styles.bubble, styles.aiBubble]}>
            <FormattedText text={message.text} />
          </View>
        </Pressable>
        <View style={styles.aiFooter}>
          <Text style={styles.time}>{formatTime(message.createdAt)}</Text>
          <View style={styles.actions}>
            <ActionButton icon="copy" label="Copy" onPress={copy} />
            {canRate && (
              <>
                <ActionButton
                  icon={message.rating === 'up' ? 'thumb-up-filled' : 'thumb-up'}
                  label="Helpful"
                  color={message.rating === 'up' ? colors.sage : undefined}
                  onPress={() => onRate(message, 'up')}
                />
                <ActionButton
                  icon={message.rating === 'down' ? 'thumb-down-filled' : 'thumb-down'}
                  label="Not helpful"
                  color={message.rating === 'down' ? colors.rose : undefined}
                  onPress={() => onRate(message, 'down')}
                />
              </>
            )}
          </View>
        </View>
      </View>
    </View>
  );
});

function ActionButton({ icon, label, color, onPress }: { icon: string; label: string; color?: string; onPress: () => void }) {
  return (
    <Pressable
      onPress={onPress}
      hitSlop={6}
      accessibilityRole="button"
      accessibilityLabel={label}
      style={({ pressed }) => [styles.action, pressed && { backgroundColor: colors.surface2 }]}
    >
      <TablerIcon name={icon} size={15} color={color ?? colors.text3} />
    </Pressable>
  );
}

const styles = StyleSheet.create({
  userRow: { flexDirection: 'row', justifyContent: 'flex-end', paddingLeft: 48 },
  userWrap: { maxWidth: '100%', alignItems: 'flex-end' },
  aiRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 10, paddingRight: 32 },
  avatar: { marginTop: 2 },
  aiCol: { flexShrink: 1 },
  bubble: { paddingVertical: 12, paddingHorizontal: 16, borderRadius: 20 },
  userBubble: { borderBottomRightRadius: 6 },
  failedBubble: { opacity: 0.6 },
  // #chat .msg.ai .msg-bubble
  aiBubble: { backgroundColor: '#F0F4F8', borderWidth: 1, borderColor: '#DCE4EC', borderBottomLeftRadius: 4 },
  userText: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 24, color: '#fff' },
  aiText: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 24, color: '#1E293B' },
  heading: { fontFamily: fonts.semibold, fontSize: 15, lineHeight: 22, color: colors.accent },
  gap: { marginTop: 12 },
  time: { fontFamily: fonts.regular, fontSize: 11, color: colors.text3, marginTop: 6, marginLeft: 4 },
  timeRight: { marginLeft: 0, marginRight: 4 },
  failedRow: { flexDirection: 'row', alignItems: 'center', gap: 4, marginTop: 6 },
  failedText: { fontFamily: fonts.medium, fontSize: 12, color: colors.rose },
  consulted: { flexDirection: 'row', alignItems: 'center', gap: 4, marginBottom: 6 },
  consultedText: { fontFamily: fonts.semibold, fontSize: 10, letterSpacing: 0.5, textTransform: 'uppercase', color: colors.gold },
  aiFooter: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  actions: { flexDirection: 'row', gap: 4, marginTop: 4 },
  action: { width: 30, height: 30, borderRadius: 15, alignItems: 'center', justifyContent: 'center' },
});
