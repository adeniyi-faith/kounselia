import { Tabs } from 'expo-router/js-tabs';
import { TabBar } from '@/components/TabBar';
import { ProDashboardProvider, usePro } from '@/professional/ProDashboard';
import { useColors } from '@/theme';

// A professional's own home: the same tabs as the website's professional
// dashboard on a phone (Overview, Bookings, Articles, Earnings, Profile).
// Articles only shows once the Journal is open to professionals, or they
// already have articles, as on the website.
const ICONS = { index: 'layout-dashboard', bookings: 'calendar-event', articles: 'feather', earnings: 'cash', profile: 'user-edit' };
const LABELS = { index: 'Overview', bookings: 'Bookings', articles: 'Articles', earnings: 'Earnings', profile: 'Profile' };

export default function ProLayout() {
  return (
    <ProDashboardProvider>
      <ProTabs />
    </ProDashboardProvider>
  );
}

function ProTabs() {
  const colors = useColors();
  const { data } = usePro();
  const hidden = data && !data.articles.show_tab ? ['articles'] : [];
  return (
    <Tabs
      tabBar={(props) => <TabBar {...props} icons={ICONS} labels={LABELS} hidden={hidden} />}
      screenOptions={{ headerShown: false, sceneStyle: { backgroundColor: colors.bg }, animation: 'fade' }}
    >
      <Tabs.Screen name="index" options={{ title: 'Overview' }} />
      <Tabs.Screen name="bookings" options={{ title: 'Bookings' }} />
      <Tabs.Screen name="articles" options={{ title: 'Articles' }} />
      <Tabs.Screen name="earnings" options={{ title: 'Earnings' }} />
      <Tabs.Screen name="profile" options={{ title: 'Profile and rate' }} />
    </Tabs>
  );
}
