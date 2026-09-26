import { Tabs } from 'expo-router/js-tabs';
import { TabBar } from '@/components/TabBar';
import { useColors } from '@/theme';

// Same tabs as the website dashboard's phone layout, with "Book" (sessions
// with professionals) where the website has a calendar button. "My plan"
// joins with the payments work.
export default function TabsLayout() {
  const colors = useColors();
  return (
    <Tabs
      tabBar={(props) => <TabBar {...props} />}
      // A quick fade between tabs, so switching doesn't jump.
      screenOptions={{ headerShown: false, sceneStyle: { backgroundColor: colors.bg }, animation: 'fade' }}
    >
      <Tabs.Screen name="index" options={{ title: 'Home' }} />
      <Tabs.Screen name="sessions" options={{ title: 'Sessions' }} />
      <Tabs.Screen name="talk" options={{ title: 'Talk to someone' }} />
      <Tabs.Screen name="book" options={{ title: 'Book a professional' }} />
      <Tabs.Screen name="settings" options={{ title: 'Settings' }} />
    </Tabs>
  );
}
