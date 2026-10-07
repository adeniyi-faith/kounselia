// One message, styled like the web chat (.msg, .msg-bubble): the member's
// on the right in navy, the counselor's on the left in soft blue-grey
// with their avatar, plus copy / helpful / not helpful under each reply.
import type { CounselorSummary } from '@kounselia/core';
import * as Clipboard from 'expo-clipboard';
import * as Haptics from 'expo-haptics';
import { LinearGradient } from 'expo-linear-gradient';
import { memo } from 'react';
import { ActivityIndicator, Pressable, Text, View } from 'react-native';
import type { AppMessage } from '@/chat/useChat';
import { useLanguage } from '@/language';
import { fonts, makeStyles, useColors } from '@/theme';
import { CounselorAvatar } from '../CounselorAvatar';
import { TablerIcon } from '../TablerIcon';
import { formatTime } from './formatTime';

interface Props {
  message: AppMessage;
  counselor: CounselorSummary;
  onRate: (message: AppMessage, rating: 'up' | 'down') => void;
  onRetry: (message: AppMessage) => void;
  onNotify: (text: string) => void;
  // 'loading' / 'playing' while this reply is being read aloud.
  playPhase?: 'loading' | 'playing';
  onListen: (message: AppMessage) => void;
}

// A reply's "**Heading**" paragraphs become headings and blank lines
// separate paragraphs — the same formatting the web chat applies.
function FormattedText({ text }: { text: string }) {
  const styles = useStyles();
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

export const MessageBubble = memo(function MessageBubble({ message, counselor, onRate, onRetry, onNotify, playPhase, onListen }: Props) {
  const styles = useStyles();
  const colors = useColors();
  const { language, t } = useLanguage();
  async function copy() {
    try {
      await Clipboard.setStringAsync(message.text);
      Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light).catch(() => undefined);
      onNotify(t('m.b.common.copied'));
    } catch {
      onNotify(t('m.b.msg.copy_failed'));
    }
  }

  if (message.sender === 'user') {
    return (
      <View style={styles.userRow}>
        <Pressable
          onLongPress={copy}
          onPress={message.failed ? () => onRetry(message) : undefined}
          accessibilityHint={message.failed ? t('m.b.msg.failed_hint') : t('m.b.msg.copy_hint')}
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
              <Text style={styles.failedText}>{t('m.b.msg.failed')}</Text>
            </View>
          ) : (
            <Text style={[styles.time, styles.timeRight]}>{formatTime(message.createdAt, language)}</Text>
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
            <Text style={styles.consultedText}>{t('m.b.msg.consulted', { names: message.consulted.join(' & ') })}</Text>
          </View>
        )}
        <Pressable onLongPress={copy} accessibilityHint={t('m.b.msg.copy_hint')}>
          <View style={[styles.bubble, styles.aiBubble]}>
            <FormattedText text={message.text} />
          </View>
        </Pressable>
        <View style={styles.aiFooter}>
          <View style={styles.timeRow}>
            <Text style={styles.time}>{formatTime(message.createdAt, language)}</Text>
            {canRate && (
              <Pressable
                onPress={() => onListen(message)}
                hitSlop={8}
                accessibilityRole="button"
                accessibilityLabel={playPhase === 'playing' ? t('m.b.msg.stop_listening') : t('m.b.msg.listen')}
                style={styles.listen}
              >
                {playPhase === 'loading' ? (
                  <ActivityIndicator size="small" color={colors.accentText} />
                ) : (
                  <TablerIcon name={playPhase === 'playing' ? 'player-stop-filled' : 'volume'} size={15} color={playPhase ? colors.accent : colors.text3} />
                )}
              </Pressable>
            )}
          </View>
          <View style={styles.actions}>
            <ActionButton icon="copy" label={t('m.b.msg.copy')} onPress={copy} />
            {canRate && (
              <>
                <ActionButton
                  icon={message.rating === 'up' ? 'thumb-up-filled' : 'thumb-up'}
                  label={t('m.b.msg.helpful')}
                  color={message.rating === 'up' ? colors.sage : undefined}
                  onPress={() => onRate(message, 'up')}
                />
                <ActionButton
                  icon={message.rating === 'down' ? 'thumb-down-filled' : 'thumb-down'}
                  label={t('m.b.msg.not_helpful')}
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
  const styles = useStyles();
  const colors = useColors();
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

const useStyles = makeStyles((colors) => ({
  userRow: { flexDirection: 'row', justifyContent: 'flex-end', paddingLeft: 48 },
  userWrap: { maxWidth: '100%', alignItems: 'flex-end' },
  aiRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 10, paddingRight: 32 },
  avatar: { marginTop: 2 },
  aiCol: { flexShrink: 1 },
  bubble: { paddingVertical: 12, paddingHorizontal: 16, borderRadius: 20 },
  userBubble: { borderBottomRightRadius: 6 },
  failedBubble: { opacity: 0.6 },
  // #chat .msg.ai .msg-bubble
  aiBubble: { backgroundColor: colors.chatBubble, borderWidth: 1, borderColor: colors.chatBubbleBorder, borderBottomLeftRadius: 4 },
  userText: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 24, color: '#fff' },
  aiText: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 24, color: colors.chatText },
  heading: { fontFamily: fonts.semibold, fontSize: 15, lineHeight: 22, color: colors.accentText },
  gap: { marginTop: 12 },
  time: { fontFamily: fonts.regular, fontSize: 11, color: colors.text3, marginTop: 6, marginLeft: 4 },
  timeRight: { marginLeft: 0, marginRight: 4 },
  failedRow: { flexDirection: 'row', alignItems: 'center', gap: 4, marginTop: 6 },
  failedText: { fontFamily: fonts.medium, fontSize: 12, color: colors.rose },
  consulted: { flexDirection: 'row', alignItems: 'center', gap: 4, marginBottom: 6 },
  consultedText: { fontFamily: fonts.semibold, fontSize: 10, letterSpacing: 0.5, textTransform: 'uppercase', color: colors.gold },
  aiFooter: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  timeRow: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  listen: { width: 26, height: 26, marginTop: 6, alignItems: 'center', justifyContent: 'center' },
  actions: { flexDirection: 'row', gap: 4, marginTop: 4 },
  action: { width: 30, height: 30, borderRadius: 15, alignItems: 'center', justifyContent: 'center' },
}));
