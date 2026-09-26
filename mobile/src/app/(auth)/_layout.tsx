import { Stack } from 'expo-router';
import { fonts, useColors } from '@/theme';

export const unstable_settings = { initialRouteName: 'welcome' };

// Native stack: iOS swipe-back and the Android back gesture work as in
// any other app. Screens slide in from the right the same way on iPhone
// and Android; switching between "Sign in" and "Create account" (a
// `switched` link on each form) cross-fades instead, since it swaps one
// form for the other in the same place rather than going somewhere new.
function formTransition({ route }: { route: { params?: object } }) {
  const switched = (route.params as { switched?: string } | undefined)?.switched === '1';
  return { animation: switched ? ('fade' as const) : ('ios_from_right' as const) };
}

export default function AuthLayout() {
  const colors = useColors();
  return (
    <Stack
      screenOptions={{
        headerShadowVisible: false,
        headerStyle: { backgroundColor: colors.bg },
        headerTintColor: colors.accentText,
        headerTitle: '',
        headerBackButtonDisplayMode: 'minimal',
        headerTitleStyle: { fontFamily: fonts.medium },
        contentStyle: { backgroundColor: colors.bg },
        animation: 'ios_from_right',
      }}
    >
      <Stack.Screen name="welcome" options={{ headerShown: false }} />
      <Stack.Screen name="sign-in" options={formTransition} />
      <Stack.Screen name="sign-up" options={formTransition} />
      <Stack.Screen name="forgot-password" />
    </Stack>
  );
}
