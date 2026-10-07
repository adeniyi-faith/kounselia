// Small building blocks for the professional's screens, drawn like the
// website's pro dashboard: the status banner, the number cards, a list to
// choose from, and the round "nothing here yet" box.
import * as Haptics from 'expo-haptics';
import type { ReactNode } from 'react';
import { Pressable, Text, View, type ViewStyle } from 'react-native';
import { Sheet } from '@/components/Sheet';
import { TablerIcon } from '@/components/TablerIcon';
import { counselorColors, fonts, makeStyles, radius, shadows, useColors } from '@/theme';

// The four boxes under the page title (.stat-card), two to a row.
export function StatGrid({ items }: { items: { icon: string; color: string; num: string; label: string }[] }) {
  const styles = useStyles();
  const colors = useColors();
  return (
    <View style={styles.grid}>
      {items.map((s) => {
        const { fg, bg } = counselorColors(s.color, colors);
        return (
          <View key={s.label} style={styles.stat} accessible accessibilityLabel={`${s.label}: ${s.num}`}>
            <View style={[styles.statIcon, { backgroundColor: bg }]}>
              <TablerIcon name={s.icon} size={17} color={fg} />
            </View>
            <Text style={styles.statNum} numberOfLines={1} adjustsFontSizeToFit minimumFontScale={0.7}>
              {s.num}
            </Text>
            <Text style={styles.statLabel}>{s.label}</Text>
          </View>
        );
      })}
    </View>
  );
}

// "Under review", "Verified"... (.status-banner)
export function Banner({ tone, title, children }: { tone: 'gold' | 'sage' | 'rose' | 'blue'; title?: string; children: ReactNode }) {
  const styles = useStyles();
  const colors = useColors();
  const { fg, bg } = counselorColors(tone, colors);
  return (
    <View style={[styles.banner, { backgroundColor: bg }]}>
      {title ? <Text style={[styles.bannerTitle, { color: fg }]}>{title}</Text> : null}
      <Text style={[styles.bannerText, { color: fg }]}>{children}</Text>
    </View>
  );
}

// A small rounded label (Weekly, Free, Live...).
export function Pill({ label, tone = 'blue' }: { label: string; tone?: string }) {
  const styles = useStyles();
  const colors = useColors();
  const { fg, bg } = counselorColors(tone, colors);
  return <Text style={[styles.pill, { color: fg, backgroundColor: bg }]}>{label}</Text>;
}

// A tappable row that shows the current choice and opens a list (a
// select box on the website).
export function SelectField({ label, value, onPress, placeholder }: { label: string; value: string | null; onPress: () => void; placeholder?: string }) {
  const styles = useStyles();
  const colors = useColors();
  return (
    <View style={{ marginBottom: 18 }}>
      <Text style={styles.fieldLabel}>{label}</Text>
      <Pressable onPress={onPress} accessibilityRole="button" accessibilityLabel={`${label}: ${value ?? placeholder ?? ''}`} style={styles.select}>
        <Text style={[styles.selectText, !value && { color: colors.text3 }]} numberOfLines={1}>
          {value ?? placeholder}
        </Text>
        <TablerIcon name="chevron-down" size={18} color={colors.text3} />
      </Pressable>
    </View>
  );
}

// The list a SelectField opens.
export function ChoiceSheet<T extends string | number>({
  visible,
  title,
  choices,
  selected,
  onPick,
  onClose,
}: {
  visible: boolean;
  title: string;
  choices: { value: T; label: string; sub?: string }[];
  selected: T | null;
  onPick: (value: T) => void;
  onClose: () => void;
}) {
  const styles = useStyles();
  const colors = useColors();
  return (
    <Sheet visible={visible} title={title} onClose={onClose}>
      {choices.map((c) => {
        const on = c.value === selected;
        return (
          <Pressable
            key={String(c.value)}
            onPress={() => {
              Haptics.selectionAsync().catch(() => undefined);
              onPick(c.value);
              onClose();
            }}
            accessibilityRole="radio"
            accessibilityState={{ checked: on }}
            style={[styles.choice, on && styles.choiceOn]}
          >
            <View style={{ flex: 1 }}>
              <Text style={[styles.choiceText, on && { color: colors.accentText }]}>{c.label}</Text>
              {c.sub ? <Text style={styles.choiceSub}>{c.sub}</Text> : null}
            </View>
            {on && <TablerIcon name="check" size={18} color={colors.accentText} />}
          </Pressable>
        );
      })}
      <View style={{ height: 8 }} />
    </Sheet>
  );
}

// A white card with a heading, for one part of a page.
export function Panel({ title, note, children, style }: { title?: string; note?: string; children: ReactNode; style?: ViewStyle }) {
  const styles = useStyles();
  return (
    <View style={[styles.panel, style]}>
      {title ? (
        <Text style={styles.panelTitle} accessibilityRole="header">
          {title}
        </Text>
      ) : null}
      {note ? <Text style={styles.panelNote}>{note}</Text> : null}
      {children}
    </View>
  );
}

// A small outlined button (Message, Reschedule, Cancel...).
export function SmallButton({
  icon,
  label,
  onPress,
  tone = 'default',
  disabled,
}: {
  icon?: string;
  label: string;
  onPress: () => void;
  tone?: 'default' | 'join' | 'danger';
  disabled?: boolean;
}) {
  const styles = useStyles();
  const colors = useColors();
  const tint = tone === 'join' ? '#fff' : tone === 'danger' ? colors.rose : colors.accentText;
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      accessibilityRole="button"
      style={({ pressed }) => [styles.small, tone === 'join' && styles.smallJoin, { opacity: disabled ? 0.5 : pressed ? 0.8 : 1 }]}
    >
      {icon ? <TablerIcon name={icon} size={16} color={tint} /> : null}
      <Text style={[styles.smallText, { color: tint }]}>{label}</Text>
    </Pressable>
  );
}

const useStyles = makeStyles((colors) => ({
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: 10, marginTop: 16 },
  stat: {
    width: '48%',
    flexGrow: 1,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.sm,
    padding: 14,
    ...shadows.soft,
  },
  statIcon: { width: 34, height: 34, borderRadius: 10, alignItems: 'center', justifyContent: 'center', marginBottom: 12 },
  statNum: { fontFamily: fonts.semibold, fontSize: 22, lineHeight: 28, color: colors.text, fontVariant: ['tabular-nums'] },
  statLabel: { fontFamily: fonts.regular, fontSize: 12.5, lineHeight: 16, color: colors.text2, marginTop: 4 },
  banner: { borderRadius: radius.sm, padding: 16, marginTop: 16 },
  bannerTitle: { fontFamily: fonts.semibold, fontSize: 12, letterSpacing: 0.5, textTransform: 'uppercase', marginBottom: 4 },
  bannerText: { fontFamily: fonts.regular, fontSize: 14, lineHeight: 21 },
  pill: {
    fontFamily: fonts.semibold,
    fontSize: 10.5,
    letterSpacing: 0.3,
    textTransform: 'uppercase',
    borderRadius: 999,
    paddingVertical: 2,
    paddingHorizontal: 8,
    overflow: 'hidden',
    alignSelf: 'flex-start',
  },
  fieldLabel: { fontFamily: fonts.medium, fontSize: 13, color: colors.text2, marginBottom: 6, letterSpacing: 0.2 },
  select: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    borderWidth: 1.5,
    borderColor: colors.border,
    borderRadius: radius.field,
    backgroundColor: colors.bg,
    paddingVertical: 14,
    paddingHorizontal: 16,
  },
  selectText: { flex: 1, fontFamily: fonts.regular, fontSize: 15, color: colors.text },
  choice: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 14, paddingHorizontal: 14, borderRadius: 14, marginBottom: 6, backgroundColor: colors.bg },
  choiceOn: { backgroundColor: colors.accentLight },
  choiceText: { fontFamily: fonts.medium, fontSize: 15, color: colors.text },
  choiceSub: { fontFamily: fonts.regular, fontSize: 12.5, color: colors.text3, marginTop: 2 },
  panel: { backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderRadius: radius.r, padding: 18, marginTop: 16, ...shadows.soft },
  panelTitle: { fontFamily: fonts.serifMedium, fontSize: 21, color: colors.text },
  panelNote: { fontFamily: fonts.regular, fontSize: 13, lineHeight: 19, color: colors.text3, marginTop: 4, marginBottom: 14 },
  small: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    paddingVertical: 8,
    paddingHorizontal: 14,
    borderRadius: 50,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.bg,
  },
  smallJoin: { backgroundColor: colors.sageFill, borderColor: colors.sageFill },
  smallText: { fontFamily: fonts.medium, fontSize: 13 },
}));
