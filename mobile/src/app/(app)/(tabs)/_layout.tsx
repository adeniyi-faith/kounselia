import { Tabs } from 'expo-router/js-tabs';
import { TabBar } from '@/components/TabBar';
import { useT } from '@/language';
import { useColors } from '@/theme';

// Same tabs as the website dashboard's phone layout, with "Book" (sessions
// with professionals) where the website has a calendar button. "My plan"
// joins with the payments work.
export default function TabsLayout() {
  const colors = useColors();
  const t = useT();
  return (
    <Tabs
      tabBar={(props) => <TabBar {...props} />}
      // A quick fade between tabs, so switching doesn't jump.
      screenOptions={{ headerShown: false, sceneStyle: { backgroundColor: colors.bg }, animation: 'fade' }}
    >
      <Tabs.Screen name="index" options={{ title: t('m.tabs.home') }} />
      <Tabs.Screen name="sessions" options={{ title: t('m.tabs.sessions') }} />
      <Tabs.Screen name="talk" options={{ title: t('m.tabs.talk') }} />
      <Tabs.Screen name="book" options={{ title: t('m.tabs.book_pro') }} />
      <Tabs.Screen name="settings" options={{ title: t('m.tabs.settings') }} />
    </Tabs>
  );
}
