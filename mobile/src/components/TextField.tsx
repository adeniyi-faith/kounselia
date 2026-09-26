// The site's .form-field: small label above a rounded input that turns
// white with a navy ring while you type in it. Password fields get the
// same show/hide eye as the website.
import Ionicons from '@expo/vector-icons/Ionicons';
import { forwardRef, useState } from 'react';
import { Pressable, StyleSheet, Text, TextInput, View, type TextInputProps } from 'react-native';
import { colors, fonts, radius } from '@/theme';

interface Props extends TextInputProps {
  label: string;
  password?: boolean;
}

export const TextField = forwardRef<TextInput, Props>(function TextField({ label, password = false, style, ...input }, ref) {
  const [focused, setFocused] = useState(false);
  const [visible, setVisible] = useState(false);

  return (
    <View style={styles.field}>
      <Text style={styles.label}>{label}</Text>
      <View style={[styles.box, focused && styles.boxFocused]}>
        <TextInput
          ref={ref}
          {...input}
          secureTextEntry={password && !visible}
          placeholderTextColor={colors.text3}
          onFocus={(e) => {
            setFocused(true);
            input.onFocus?.(e);
          }}
          onBlur={(e) => {
            setFocused(false);
            input.onBlur?.(e);
          }}
          style={[styles.input, password && styles.inputWithToggle, style]}
        />
        {password && (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={visible ? 'Hide password' : 'Show password'}
            hitSlop={12}
            onPress={() => setVisible((v) => !v)}
            style={styles.toggle}
          >
            <Ionicons name={visible ? 'eye-off-outline' : 'eye-outline'} size={20} color={colors.text3} />
          </Pressable>
        )}
      </View>
    </View>
  );
});

const styles = StyleSheet.create({
  field: { marginBottom: 18 },
  label: { fontFamily: fonts.medium, fontSize: 13, color: colors.text2, marginBottom: 6, letterSpacing: 0.2 },
  box: {
    flexDirection: 'row',
    alignItems: 'center',
    borderWidth: 1.5,
    borderColor: colors.border,
    borderRadius: radius.field,
    backgroundColor: colors.bg,
  },
  boxFocused: {
    borderColor: colors.accent,
    backgroundColor: colors.surface,
    // Stands in for the site's 4px accent-light focus ring.
    outlineColor: colors.accentLight,
    outlineWidth: 4,
    outlineStyle: 'solid',
  },
  input: {
    flex: 1,
    paddingVertical: 14,
    paddingHorizontal: 16,
    fontFamily: fonts.regular,
    fontSize: 15,
    color: colors.text,
  },
  inputWithToggle: { paddingRight: 48 },
  toggle: { position: 'absolute', right: 16 },
});
