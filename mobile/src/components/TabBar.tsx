// The website dashboard's phone tab bar (.mobile-tabbar): white bar,
// grey icons that turn navy when active, and the round navy "Talk to
// someone" button raised in the middle.
import * as Haptics from 'expo-haptics';
import { LinearGradient } from 'expo-linear-gradient';
import type { BottomTabBarProps } from 'expo-router/js-tabs';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { colors, fonts } from '@/theme';
import { TablerIcon } from './TablerIcon';

const ICONS: Record<string, string> = { index: 'home', settings: 'settings' };
const LABELS: Record<string, string> = { index: 'Home', settings: 'Settings' };
const CENTRE = 'talk';

export function TabBar({ state, navigation, descriptors }: BottomTabBarProps) {
  const insets = useSafeAreaInsets();

  return (
    <View style={[styles.bar, { paddingBottom: insets.bottom, height: 64 + insets.bottom }]}>
      {state.routes.map((route, index) => {
        const focused = state.index === index;
        const onPress = () => {
          const event = navigation.emit({ type: 'tabPress', target: route.key, canPreventDefault: true });
          if (!focused && !event.defaultPrevented) {
            Haptics.selectionAsync().catch(() => undefined);
            navigation.navigate(route.name);
          }
        };
        const label = descriptors[route.key].options.title ?? route.name;

        if (route.name === CENTRE) {
          return (
            <Pressable
              key={route.key}
              onPress={onPress}
              accessibilityRole="tab"
              accessibilityState={{ selected: focused }}
              accessibilityLabel={label}
              style={({ pressed }) => [styles.fab, { transform: [{ scale: pressed ? 0.94 : 1 }] }]}
            >
              <LinearGradient colors={[colors.accent, colors.accent2]} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={styles.fabInner}>
                <TablerIcon name="message-2-plus" size={22} color="#fff" />
              </LinearGradient>
            </Pressable>
          );
        }

        const tint = focused ? colors.accent : colors.text3;
        return (
          <Pressable
            key={route.key}
            onPress={onPress}
            accessibilityRole="tab"
            accessibilityState={{ selected: focused }}
            accessibilityLabel={label}
            style={styles.tab}
          >
            <TablerIcon name={ICONS[route.name] ?? 'circle'} size={22} color={tint} />
            <Text style={[styles.label, { color: tint }]}>{LABELS[route.name] ?? label}</Text>
          </Pressable>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  bar: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-evenly',
    backgroundColor: 'rgba(255,255,255,0.97)',
    borderTopWidth: 1,
    borderTopColor: colors.border,
  },
  tab: { width: 64, alignItems: 'center', gap: 3, paddingVertical: 6 },
  label: { fontFamily: fonts.medium, fontSize: 10 },
  fab: {
    width: 60,
    height: 60,
    marginTop: -26,
    borderRadius: 30,
    borderWidth: 4,
    borderColor: colors.bg,
    shadowColor: colors.accent,
    shadowOpacity: 0.35,
    shadowRadius: 9,
    shadowOffset: { width: 0, height: 6 },
    elevation: 6,
  },
  fabInner: { flex: 1, borderRadius: 26, alignItems: 'center', justifyContent: 'center' },
});
