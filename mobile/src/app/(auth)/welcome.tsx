import { router } from 'expo-router';
import { Image, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button } from '@/components/Button';
import { colors, fonts } from '@/theme';

export default function Welcome() {
  return (
    <SafeAreaView style={styles.safe}>
      <View style={styles.hero}>
        <Image
          source={require('../../../assets/wordmark.png')}
          style={styles.wordmark}
          resizeMode="contain"
          accessibilityLabel="Kounselia"
        />
        <Text style={styles.tagline}>A calm, private space to talk things through, whenever you need it.</Text>
      </View>
      <View style={styles.actions}>
        <Button title="Create a free account" onPress={() => router.push('/sign-up')} />
        <Button title="I already have an account" variant="ghost" onPress={() => router.push('/sign-in')} />
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.bg, paddingHorizontal: 24 },
  hero: { flex: 1, justifyContent: 'center', alignItems: 'center' },
  wordmark: { width: 260, height: 104, marginBottom: 20 },
  tagline: {
    fontFamily: fonts.serif,
    fontSize: 22,
    lineHeight: 30,
    color: colors.text2,
    textAlign: 'center',
    maxWidth: 320,
  },
  actions: { gap: 12, paddingBottom: 16 },
});
