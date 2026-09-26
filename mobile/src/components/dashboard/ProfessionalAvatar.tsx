import type { Professional } from '@kounselia/core';
import { Image } from 'expo-image';
import { Text, View } from 'react-native';
import { fonts, makeStyles } from '@/theme';

// A professional's photo, or the first letter of their name if they
// haven't added one (like the website's tiles).
export function ProfessionalAvatar({ pro, size }: { pro: Pick<Professional, 'name' | 'avatar_url'>; size: number }) {
  const styles = useStyles();
  return (
    <View style={[styles.proAv, { width: size, height: size, borderRadius: size / 2 }]}>
      {pro.avatar_url ? (
        <Image source={{ uri: pro.avatar_url }} style={{ width: size, height: size, borderRadius: size / 2 }} contentFit="cover" accessibilityIgnoresInvertColors />
      ) : (
        <Text style={[styles.proInitial, { fontSize: size * 0.4 }]}>{pro.name.trim().charAt(0).toUpperCase()}</Text>
      )}
    </View>
  );
}

const useStyles = makeStyles((colors) => ({
  proAv: { backgroundColor: colors.accentLight, alignItems: 'center', justifyContent: 'center', overflow: 'hidden' },
  proInitial: { fontFamily: fonts.semibold, color: colors.accentText },
}));
