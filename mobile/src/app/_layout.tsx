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
import * as SystemUI from 'expo-system-ui';
import { useEffect } from 'react';
import { BrowserProvider } from '@/browser/BrowserProvider';
import { tablerFont } from '@/components/TablerIcon';
import { SessionProvider, useSession } from '@/session';
import { ThemeProvider, useColors, useTheme } from '@/theme';

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
    <ThemeProvider>
      <SessionProvider>
        <BrowserProvider>
          <SystemColors />
          {/* If a font fails to load, carry on with the system font rather than hang on the splash. */}
          {fontsLoaded || fontError ? <RootNavigator /> : null}
        </BrowserProvider>
      </SessionProvider>
    </ThemeProvider>
  );
}

// The clock and battery icons, and the colour behind the app (seen for a
// moment when the keyboard opens or a screen slides), follow light or dark.
function SystemColors() {
  const { scheme, colors } = useTheme();
  useEffect(() => {
    SystemUI.setBackgroundColorAsync(colors.bg).catch(() => undefined);
  }, [colors.bg]);
  return <StatusBar style={scheme === 'dark' ? 'light' : 'dark'} />;
}

function RootNavigator() {
  const colors = useColors();
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
