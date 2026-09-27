// Crash reporting starts first, so it also catches errors while the rest loads.
import { identify, report, wrapRoot } from '@/monitoring';
// Each weight is imported from its own subpath (as the @expo-google-fonts
// packages' own docs recommend), not the package root: importing from the
// root pulls in every weight's font file as a side effect, even ones never
// used, because the root module requires() all of them at the top. Cormorant
// Garamond alone has 10 weights (~5MB); importing the 2 this app uses this
// way, instead of the root, keeps the other 8 out of the app entirely.
import { CormorantGaramond_400Regular } from '@expo-google-fonts/cormorant-garamond/400Regular';
import { CormorantGaramond_500Medium } from '@expo-google-fonts/cormorant-garamond/500Medium';
import { Outfit_300Light } from '@expo-google-fonts/outfit/300Light';
import { Outfit_400Regular } from '@expo-google-fonts/outfit/400Regular';
import { Outfit_500Medium } from '@expo-google-fonts/outfit/500Medium';
import { Outfit_600SemiBold } from '@expo-google-fonts/outfit/600SemiBold';
import { useFonts } from 'expo-font';
import { SplashScreen, Stack, type ErrorBoundaryProps } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import * as SystemUI from 'expo-system-ui';
import { useEffect } from 'react';
import { Text, View } from 'react-native';
import { BrowserProvider } from '@/browser/BrowserProvider';
import { VideoCallProvider } from '@/browser/VideoCallProvider';
import { AppLockProvider } from '@/components/AppLock';
import { Button } from '@/components/Button';
import { DialogHost } from '@/components/Dialog';
import { tablerFont } from '@/components/TablerIcon';
import { SessionProvider, useSession } from '@/session';
import { fonts, ThemeProvider, useColors, useTheme } from '@/theme';

// Keep the Kounselia splash up until the fonts are in and we know whether
// someone is signed in, so nobody sees a flash of the wrong screen.
SplashScreen.preventAutoHideAsync();

export default wrapRoot(RootLayout);

function RootLayout() {
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
          <VideoCallProvider>
            <AppLockProvider>
              <SystemColors />
              {/* If a font fails to load, carry on with the system font rather than hang on the splash. */}
              {fontsLoaded || fontError ? <RootNavigator /> : null}
              <DialogHost />
            </AppLockProvider>
          </VideoCallProvider>
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
  const { status, user } = useSession();
  const userId = user?.id ?? null;
  useEffect(() => {
    identify(userId);
  }, [userId]);
  if (status === 'loading') return null;
  SplashScreen.hide();

  const signedIn = status === 'signed-in';
  // Signed-out members can only reach the (auth) screens and signed-in
  // members only the (app) ones; switching between them happens by itself
  // when `status` changes.
  return (
    // Signing in or out swaps the whole app over, so it fades rather than
    // sliding as if it were one more screen.
    <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: colors.bg }, animation: 'fade' }}>
      <Stack.Protected guard={signedIn}>
        <Stack.Screen name="(app)" />
      </Stack.Protected>
      <Stack.Protected guard={!signedIn}>
        <Stack.Screen name="(auth)" />
      </Stack.Protected>
    </Stack>
  );
}

// If a screen crashes while drawing, show this instead of closing the app,
// and send the details to crash reporting (src/monitoring.ts).
export function ErrorBoundary({ error, retry }: ErrorBoundaryProps) {
  const colors = useColors();
  useEffect(() => {
    report(error);
    SplashScreen.hide();
  }, [error]);
  return (
    <View style={{ flex: 1, backgroundColor: colors.bg, alignItems: 'center', justifyContent: 'center', padding: 32 }}>
      <Text style={{ fontFamily: fonts.serifMedium, fontSize: 26, color: colors.text, textAlign: 'center' }}>Something went wrong</Text>
      <Text style={{ fontFamily: fonts.regular, fontSize: 15, lineHeight: 22, color: colors.text2, textAlign: 'center', marginTop: 10 }}>
        Sorry about that. Please try again.
      </Text>
      <Button title="Try again" onPress={retry} style={{ alignSelf: 'stretch', marginTop: 24 }} />
    </View>
  );
}
