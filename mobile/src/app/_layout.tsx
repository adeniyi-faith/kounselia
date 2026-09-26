import { CormorantGaramond_400Regular, CormorantGaramond_500Medium } from '@expo-google-fonts/cormorant-garamond';
import {
  Outfit_300Light,
  Outfit_400Regular,
  Outfit_500Medium,
  Outfit_600SemiBold,
  useFonts,
} from '@expo-google-fonts/outfit';
import { SplashScreen, Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { tablerFont } from '@/components/TablerIcon';
import { SessionProvider, useSession } from '@/session';
import { colors } from '@/theme';

// Keep the Kounselia splash up until the fonts are in and we know whether
// someone is signed in, so nobody sees a flash of the wrong screen.
SplashScreen.preventAutoHideAsync();

export default function RootLayout() {
  const [fontsLoaded, fontError] = useFonts({
    Outfit_300Light,
    Outfit_400Regular,
    Outfit_500Medium,
    Outfit_600SemiBold,
    CormorantGaramond_400Regular,
    CormorantGaramond_500Medium,
    ...tablerFont,
  });

  return (
    <SessionProvider>
      <StatusBar style="dark" />
      {/* If a font fails to load, carry on with the system font rather than hang on the splash. */}
      {fontsLoaded || fontError ? <RootNavigator /> : null}
    </SessionProvider>
  );
}

function RootNavigator() {
  const { status } = useSession();
  if (status === 'loading') return null;
  SplashScreen.hide();

  const signedIn = status === 'signed-in';
  // Signed-out members can only reach the (auth) screens and signed-in
  // members only the (app) ones; switching between them happens by itself
  // when `status` changes.
  return (
    <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: colors.bg } }}>
      <Stack.Protected guard={signedIn}>
        <Stack.Screen name="(app)" />
      </Stack.Protected>
      <Stack.Protected guard={!signedIn}>
        <Stack.Screen name="(auth)" />
      </Stack.Protected>
    </Stack>
  );
}
