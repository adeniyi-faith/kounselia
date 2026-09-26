import { Stack } from 'expo-router';
import { fonts, useColors } from '@/theme';

export const unstable_settings = { initialRouteName: 'welcome' };

// Native stack: iOS swipe-back and the Android back gesture work as in
// any other app.
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
      }}
    >
      <Stack.Screen name="welcome" options={{ headerShown: false }} />
      <Stack.Screen name="sign-in" />
      <Stack.Screen name="sign-up" />
      <Stack.Screen name="forgot-password" />
    </Stack>
  );
}
