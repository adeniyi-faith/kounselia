// The web chat's top bar (.chat-nav): back button, counselor avatar,
// name with the blue "verified" tick, speciality, and a ⋯ options menu
// (Share conversation, Clear chat) that drops down like the website's.
import type { CounselorSummary } from '@kounselia/core';
import { useState } from 'react';
import { Modal, Pressable, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { colors, fonts } from '@/theme';
import { openSafetyResources } from '../openSafety';
import { CounselorAvatar } from '../CounselorAvatar';
import { TablerIcon } from '../TablerIcon';

interface Props {
  counselor: CounselorSummary;
  onBack: () => void;
  onShare: () => void;
  onClear: () => void;
  // Only for counselors with voice switched on in the admin.
  onCall?: () => void;
}

export function ChatHeader({ counselor, onBack, onShare, onClear, onCall }: Props) {
  const insets = useSafeAreaInsets();
  const [menuOpen, setMenuOpen] = useState(false);

  const choose = (action: () => void) => () => {
    setMenuOpen(false);
    action();
  };

  return (
    <View style={[styles.nav, { paddingTop: insets.top + 10 }]}>
      <Pressable onPress={onBack} accessibilityRole="button" accessibilityLabel="Back" style={styles.back} hitSlop={6}>
        <TablerIcon name="arrow-left" size={20} color={colors.text} />
      </Pressable>
      <CounselorAvatar icon={counselor.icon} color={counselor.color} size={42} />
      <View style={styles.info} accessible accessibilityRole="header" accessibilityLabel={`${counselor.name}, ${counselor.spec}`}>
        <View style={styles.nameRow}>
          <Text style={styles.name} numberOfLines={1}>
            {counselor.name}
          </Text>
          <View style={styles.badge}>
            <TablerIcon name="check" size={9} color="#fff" />
          </View>
        </View>
        <Text style={styles.spec} numberOfLines={1}>
          {counselor.spec}
        </Text>
      </View>
      {onCall && (
        <Pressable onPress={onCall} accessibilityRole="button" accessibilityLabel={`Voice call with ${counselor.name}`} style={[styles.iconBtn, styles.call]} hitSlop={6}>
          <TablerIcon name="phone" size={19} color="#00A884" />
        </Pressable>
      )}
      <Pressable
        onPress={() => setMenuOpen(true)}
        accessibilityRole="button"
        accessibilityLabel="More options"
        style={styles.iconBtn}
        hitSlop={6}
      >
        <TablerIcon name="dots-vertical" size={20} color={colors.text2} />
      </Pressable>

      <Modal visible={menuOpen} transparent animationType="fade" onRequestClose={() => setMenuOpen(false)}>
        <Pressable style={StyleSheet.absoluteFill} onPress={() => setMenuOpen(false)} accessibilityLabel="Close menu">
          <View style={[styles.menu, { top: insets.top + 62 }]}>
            <MenuItem icon="share" label="Share conversation" onPress={choose(onShare)} />
            <MenuItem icon="trash" label="Clear chat" color={colors.rose} onPress={choose(onClear)} />
            <MenuItem icon="lifebuoy" label="Get urgent help" onPress={choose(openSafetyResources)} last />
          </View>
        </Pressable>
      </Modal>
    </View>
  );
}

function MenuItem({ icon, label, color, onPress, last }: { icon: string; label: string; color?: string; onPress: () => void; last?: boolean }) {
  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="menuitem"
      style={({ pressed }) => [styles.item, !last && styles.itemBorder, pressed && { backgroundColor: colors.surface2 }]}
    >
      <TablerIcon name={icon} size={20} color={color ?? colors.text2} />
      <Text style={[styles.itemText, color ? { color } : null]}>{label}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  nav: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    paddingHorizontal: 16,
    paddingBottom: 12,
    backgroundColor: 'rgba(255,255,255,0.97)',
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
    zIndex: 2,
  },
  back: {
    width: 40,
    height: 40,
    borderRadius: 12,
    backgroundColor: colors.surface2,
    borderWidth: 1,
    borderColor: colors.border,
    alignItems: 'center',
    justifyContent: 'center',
  },
  info: { flex: 1, minWidth: 0 },
  nameRow: { flexDirection: 'row', alignItems: 'center', gap: 5 },
  name: { fontFamily: fonts.medium, fontSize: 16, color: colors.text, flexShrink: 1 },
  // .trust-badge
  badge: { width: 14, height: 14, borderRadius: 7, backgroundColor: '#3B82F6', alignItems: 'center', justifyContent: 'center' },
  spec: { fontFamily: fonts.regular, fontSize: 12, color: colors.text2, marginTop: 2 },
  iconBtn: { width: 38, height: 38, borderRadius: 10, alignItems: 'center', justifyContent: 'center' },
  // .icon-btn.action-call
  call: { backgroundColor: 'rgba(0,168,132,0.1)' },
  // .chat-dropdown
  menu: {
    position: 'absolute',
    right: 16,
    width: 230,
    backgroundColor: colors.surface,
    borderRadius: 16,
    borderWidth: 1,
    borderColor: colors.border,
    overflow: 'hidden',
    shadowColor: '#000',
    shadowOpacity: 0.15,
    shadowRadius: 16,
    shadowOffset: { width: 0, height: 12 },
    elevation: 8,
  },
  item: { flexDirection: 'row', alignItems: 'center', gap: 14, paddingVertical: 14, paddingHorizontal: 18 },
  itemBorder: { borderBottomWidth: 1, borderBottomColor: colors.surface2 },
  itemText: { fontFamily: fonts.medium, fontSize: 15, color: colors.text },
});
