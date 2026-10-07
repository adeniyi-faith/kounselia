// Shimmering placeholders shown while a screen's content loads, shaped like
// what's coming (cards, rows, chat bubbles), instead of a spinner. A soft
// band of light sweeps across them, all in step.
//
//   <Bone width={120} height={14} />          one grey shape
//   <HomeSkeleton />, <ListSkeleton /> …       whole screens' worth
import { LinearGradient } from 'expo-linear-gradient';
import { useEffect, useState, type ReactNode } from 'react';
import { Animated, Easing, StyleSheet, View, type DimensionValue, type StyleProp, type ViewStyle } from 'react-native';
import { useT } from '@/language';
import { makeStyles, radius, useTheme } from '@/theme';

// One sweep shared by every placeholder, started while any are showing.
const sweep = new Animated.Value(0);
let showing = 0;
let loop: Animated.CompositeAnimation | null = null;

function useSweep() {
  useEffect(() => {
    showing += 1;
    if (showing === 1) {
      sweep.setValue(0);
      loop = Animated.loop(Animated.timing(sweep, { toValue: 1, duration: 1300, easing: Easing.inOut(Easing.ease), useNativeDriver: true }));
      loop.start();
    }
    return () => {
      showing -= 1;
      if (showing === 0) loop?.stop();
    };
  }, []);
}

export function Bone({
  width = '100%',
  height = 14,
  round,
  style,
}: {
  width?: DimensionValue;
  height?: number;
  // Corner radius; a circle when it's half the height.
  round?: number;
  style?: StyleProp<ViewStyle>;
}) {
  const styles = useStyles();
  const { scheme } = useTheme();
  const [w, setW] = useState(0);
  useSweep();
  const shine = scheme === 'dark' ? 'rgba(255,255,255,0.07)' : 'rgba(255,255,255,0.75)';
  return (
    <View
      style={[styles.bone, { width, height, borderRadius: round ?? Math.min(8, height / 2) }, style]}
      onLayout={(e) => setW(e.nativeEvent.layout.width)}
      accessible={false}
      importantForAccessibility="no-hide-descendants"
    >
      {w > 0 && (
        <Animated.View
          style={[
            StyleSheet.absoluteFill,
            { width: w, transform: [{ translateX: sweep.interpolate({ inputRange: [0, 1], outputRange: [-w, w] }) }] },
          ]}
        >
          <LinearGradient colors={['transparent', shine, 'transparent']} start={{ x: 0, y: 0.5 }} end={{ x: 1, y: 0.5 }} style={StyleSheet.absoluteFill} />
        </Animated.View>
      )}
    </View>
  );
}

// A card-shaped frame to hold bones, like the real cards.
export function BoneCard({ children, style }: { children?: ReactNode; style?: StyleProp<ViewStyle> }) {
  const styles = useStyles();
  return <View style={[styles.card, style]}>{children}</View>;
}

// Wraps a skeleton so screen readers hear "Loading" once.
function Loading({ children, style }: { children: ReactNode; style?: StyleProp<ViewStyle> }) {
  const t = useT();
  return (
    <View style={style} accessible accessibilityLabel={t('m.b.common.loading')} accessibilityRole="progressbar">
      {children}
    </View>
  );
}

// ---- Whole screens' worth -----------------------------------------------------

export function HomeSkeleton() {
  const styles = useStyles();
  return (
    <Loading style={styles.stack}>
      <BoneCard>
        <Bone width="40%" height={12} />
        <Bone width="75%" height={22} style={styles.mt12} />
        <Bone width="90%" height={12} style={styles.mt12} />
        <Bone height={50} round={radius.pill} style={styles.mt16} />
      </BoneCard>
      <BoneCard>
        <Bone width="60%" height={20} />
        <View style={[styles.row, styles.mt16]}>
          {[0, 1, 2, 3, 4].map((i) => (
            <Bone key={i} height={76} round={16} style={styles.flex} />
          ))}
        </View>
      </BoneCard>
      <View style={styles.row}>
        {[0, 1, 2].map((i) => (
          <BoneCard key={i} style={styles.flex}>
            <Bone width={34} height={34} round={10} />
            <Bone width="50%" height={22} style={styles.mt12} />
            <Bone width="80%" height={10} style={styles.mt8} />
          </BoneCard>
        ))}
      </View>
    </Loading>
  );
}

// Rows with a picture on the left and two lines of text (sessions, bookings).
export function ListSkeleton({ rows = 5, square }: { rows?: number; square?: boolean }) {
  const styles = useStyles();
  return (
    <Loading style={styles.stackSm}>
      {Array.from({ length: rows }, (_, i) => (
        <BoneCard key={i} style={styles.listRow}>
          <Bone width={48} height={48} round={square ? 14 : 24} />
          <View style={styles.flex}>
            <Bone width={i % 2 ? '55%' : '70%'} height={15} />
            <Bone width={i % 2 ? '80%' : '60%'} height={11} style={styles.mt8} />
          </View>
        </BoneCard>
      ))}
    </Loading>
  );
}

// The two-across counselor tiles on "Talk to someone".
export function GridSkeleton({ tiles = 8 }: { tiles?: number }) {
  const styles = useStyles();
  return (
    <Loading style={styles.grid}>
      {Array.from({ length: tiles }, (_, i) => (
        <BoneCard key={i} style={styles.tile}>
          <Bone width={56} height={56} round={18} />
          <Bone width="70%" height={18} style={styles.mt12} />
          <Bone width="55%" height={11} style={styles.mt8} />
        </BoneCard>
      ))}
    </Loading>
  );
}

// Article cards: a cover picture, a topic, a title and a line.
export function ArticlesSkeleton({ cards = 3 }: { cards?: number }) {
  const styles = useStyles();
  return (
    <Loading style={styles.stack}>
      {Array.from({ length: cards }, (_, i) => (
        <BoneCard key={i} style={styles.noPad}>
          <Bone height={180} round={0} />
          <View style={styles.pad}>
            <Bone width="25%" height={10} />
            <Bone width="85%" height={20} style={styles.mt12} />
            <Bone width="65%" height={20} style={styles.mt8} />
            <Bone width="45%" height={10} style={styles.mt12} />
          </View>
        </BoneCard>
      ))}
    </Loading>
  );
}

// One article being opened: title, author, then paragraphs.
export function ArticleSkeleton() {
  const styles = useStyles();
  return (
    <Loading style={styles.page}>
      <Bone width="30%" height={11} />
      <Bone width="92%" height={28} style={styles.mt16} />
      <Bone width="70%" height={28} style={styles.mt8} />
      <View style={[styles.listRow, styles.mt16]}>
        <Bone width={36} height={36} round={18} />
        <Bone width="40%" height={12} />
      </View>
      <Bone height={200} round={radius.sm} style={styles.mt16} />
      {[100, 96, 88, 100, 70].map((w, i) => (
        <Bone key={i} width={`${w}%`} height={13} style={i === 0 ? styles.mt16 : styles.mt10} />
      ))}
    </Loading>
  );
}

// Journal days: a date and a few lines each.
export function JournalSkeleton() {
  const styles = useStyles();
  return (
    <Loading style={styles.stack}>
      {[3, 2, 3].map((lines, i) => (
        <BoneCard key={i}>
          <Bone width="35%" height={12} />
          {Array.from({ length: lines }, (_, j) => (
            <Bone key={j} width={j === lines - 1 ? '60%' : '100%'} height={13} style={j === 0 ? styles.mt12 : styles.mt8} />
          ))}
        </BoneCard>
      ))}
    </Loading>
  );
}

// A conversation opening: bubbles on both sides.
export function ChatSkeleton() {
  const styles = useStyles();
  const bubbles: { mine: boolean; w: DimensionValue; h: number }[] = [
    { mine: false, w: '70%', h: 64 },
    { mine: true, w: '45%', h: 42 },
    { mine: false, w: '78%', h: 90 },
    { mine: true, w: '55%', h: 42 },
  ];
  return (
    <Loading style={styles.chat}>
      {bubbles.map((b, i) => (
        <View key={i} style={[styles.bubbleRow, b.mine && styles.mine]}>
          {!b.mine && <Bone width={32} height={32} round={10} />}
          <Bone width={b.w} height={b.h} round={20} />
        </View>
      ))}
    </Loading>
  );
}

// A few blocks of form-like content (memory profile, booking page).
export function DetailSkeleton() {
  const styles = useStyles();
  return (
    <Loading style={styles.stack}>
      <Bone height={90} round={radius.sm} />
      <Bone width="60%" height={26} />
      <Bone width="90%" height={13} />
      <Bone width="75%" height={13} />
      {[0, 1, 2].map((i) => (
        <BoneCard key={i}>
          <Bone width="30%" height={11} />
          <Bone width="80%" height={15} style={styles.mt10} />
        </BoneCard>
      ))}
    </Loading>
  );
}

// A web page loading in the in-app browser.
export function PageSkeleton() {
  const styles = useStyles();
  return (
    <Loading style={styles.page}>
      <Bone width="55%" height={26} />
      <Bone width="85%" height={13} style={styles.mt16} />
      <Bone width="70%" height={13} style={styles.mt10} />
      <Bone height={160} round={radius.sm} style={styles.mt16} />
      {[100, 92, 97, 60].map((w, i) => (
        <Bone key={i} width={`${w}%`} height={13} style={i === 0 ? styles.mt16 : styles.mt10} />
      ))}
    </Loading>
  );
}

const useStyles = makeStyles((colors) => ({
  bone: { backgroundColor: colors.surface3, overflow: 'hidden' },
  card: { backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderRadius: radius.r, padding: 18 },
  stack: { gap: 16 },
  stackSm: { gap: 10 },
  row: { flexDirection: 'row', gap: 8 },
  flex: { flex: 1 },
  listRow: { flexDirection: 'row', alignItems: 'center', gap: 14 },
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: 12 },
  tile: { width: '47.5%', flexGrow: 1, alignItems: 'center', paddingVertical: 22 },
  noPad: { padding: 0, overflow: 'hidden' },
  pad: { padding: 18 },
  page: { padding: 20 },
  chat: { flex: 1, justifyContent: 'flex-end', padding: 16, gap: 14 },
  bubbleRow: { flexDirection: 'row', alignItems: 'flex-end', gap: 8 },
  mine: { justifyContent: 'flex-end' },
  mt8: { marginTop: 8 },
  mt10: { marginTop: 10 },
  mt12: { marginTop: 12 },
  mt16: { marginTop: 16 },
}));
