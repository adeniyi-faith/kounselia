import { Text, View } from 'react-native';
import { fonts, makeStyles, useColors } from '@/theme';

// An error (rose) or success (sage) note above a form's button.
export function FormMessage({ tone, text }: { tone: 'error' | 'success'; text: string }) {
  const styles = useStyles();
  const colors = useColors();
  const error = tone === 'error';
  return (
    <View
      accessibilityLiveRegion="polite"
      style={[styles.box, { backgroundColor: error ? colors.roseLight : colors.sageLight }]}
    >
      <Text style={[styles.text, { color: error ? colors.rose : colors.sage }]}>{text}</Text>
    </View>
  );
}

const useStyles = makeStyles((colors) => ({
  box: { borderRadius: 12, paddingVertical: 12, paddingHorizontal: 16, marginBottom: 16 },
  text: { fontFamily: fonts.regular, fontSize: 14, lineHeight: 20 },
}));
