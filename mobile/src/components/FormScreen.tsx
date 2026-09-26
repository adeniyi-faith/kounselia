// Page wrapper for the sign-in style screens: keeps content clear of the
// notch and home bar, slides it up when the keyboard opens, and lets a
// tap outside the fields close the keyboard.
import type { ReactNode } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, Text } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { colors, fonts } from '@/theme';

interface Props {
  title: string;
  subtitle?: string;
  children: ReactNode;
}

export function FormScreen({ title, subtitle, children }: Props) {
  return (
    <SafeAreaView style={styles.safe} edges={['bottom', 'left', 'right']}>
      <KeyboardAvoidingView style={styles.flex} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView
          contentContainerStyle={styles.content}
          keyboardShouldPersistTaps="handled"
          keyboardDismissMode="interactive"
        >
          <Text style={styles.title} accessibilityRole="header">
            {title}
          </Text>
          {subtitle ? <Text style={styles.subtitle}>{subtitle}</Text> : null}
          {children}
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.bg },
  flex: { flex: 1 },
  content: { padding: 24, paddingTop: 16 },
  // .modal h2 / .modal .sub
  title: { fontFamily: fonts.serif, fontSize: 30, color: colors.text, marginBottom: 8 },
  subtitle: { fontFamily: fonts.light, fontSize: 15, lineHeight: 24, color: colors.text2, marginBottom: 28 },
});
