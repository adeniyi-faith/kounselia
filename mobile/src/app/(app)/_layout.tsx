import { Stack } from 'expo-router';
import { CounselorsProvider } from '@/counselors';
import { colors } from '@/theme';

// Signed-in screens: the tabs, with conversations opening on top of them
// (swipe back or use the back arrow to return).
export default function AppLayout() {
  return (
    <CounselorsProvider>
      <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: colors.bg } }}>
        <Stack.Screen name="(tabs)" />
        <Stack.Screen name="chat/[slug]" />
      </Stack>
    </CounselorsProvider>
  );
}
