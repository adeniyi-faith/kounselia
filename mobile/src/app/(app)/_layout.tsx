import { Stack } from 'expo-router';
import { CounselorsProvider } from '@/counselors';
import { usePushNotifications } from '@/notifications';
import { useSession } from '@/session';
import { useColors } from '@/theme';

// Signed-in screens: the tabs, with conversations opening on top of them
// (swipe back or use the back arrow to return). Every screen slides in from
// the right the same way on iPhone and Android, and on iPhone can be swiped
// back from anywhere on the screen, not just the edge.
export default function AppLayout() {
  const colors = useColors();
  const { config } = useSession();
  // Keeps this phone's notifications working, and opens the right screen
  // when one is tapped.
  usePushNotifications(config);
  return (
    <CounselorsProvider>
      <Stack
        screenOptions={{
          headerShown: false,
          contentStyle: { backgroundColor: colors.bg },
          animation: 'ios_from_right',
          gestureEnabled: true,
          fullScreenGestureEnabled: true,
        }}
      >
        <Stack.Screen name="(tabs)" />
        <Stack.Screen name="chat/[slug]" />
        <Stack.Screen name="book/[proId]" />
        <Stack.Screen name="booking/[id]" />
        <Stack.Screen name="journal" />
        <Stack.Screen name="articles/index" />
        <Stack.Screen name="articles/[slug]" />
        <Stack.Screen name="comments/[postId]" />
        <Stack.Screen name="memory" />
        <Stack.Screen name="plan" />
      </Stack>
    </CounselorsProvider>
  );
}
