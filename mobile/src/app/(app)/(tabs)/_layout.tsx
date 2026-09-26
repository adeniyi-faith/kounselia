import { Tabs } from 'expo-router/js-tabs';
import { TabBar } from '@/components/TabBar';
import { colors } from '@/theme';

// Same tabs as the website dashboard's phone layout, with "Book" (sessions
// with professionals) where the website has a calendar button. "My plan"
// joins with the payments work.
export default function TabsLayout() {
  return (
    <Tabs
      tabBar={(props) => <TabBar {...props} />}
      screenOptions={{ headerShown: false, sceneStyle: { backgroundColor: colors.bg } }}
    >
      <Tabs.Screen name="index" options={{ title: 'Home' }} />
      <Tabs.Screen name="sessions" options={{ title: 'Sessions' }} />
      <Tabs.Screen name="talk" options={{ title: 'Talk to someone' }} />
      <Tabs.Screen name="book" options={{ title: 'Book a professional' }} />
      <Tabs.Screen name="settings" options={{ title: 'Settings' }} />
    </Tabs>
  );
}
