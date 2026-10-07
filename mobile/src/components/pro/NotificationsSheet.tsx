// The bell on the professional's home: the same in-app notifications the
// website's bell shows (new bookings, cancellations, messages, article
// reviews, payouts). Opening it marks them read, as on the website.
import { fetchNotifications, fetchUnreadNotificationCount, type AppNotification, type KounseliaConfig } from '@kounselia/core';
import { router } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, AppState, Pressable, Text, View } from 'react-native';
import { Sheet } from '@/components/Sheet';
import { TablerIcon } from '@/components/TablerIcon';
import { useLanguage, useT } from '@/language';
import { routeForLink } from '@/notifications';
import { fonts, makeStyles, useColors } from '@/theme';

// The round bell button, with a dot while something is unread.
export function NotificationBell({ config }: { config: KounseliaConfig }) {
  const styles = useStyles();
  const colors = useColors();
  const t = useT();
  const [open, setOpen] = useState(false);
  const [unread, setUnread] = useState(0);

  const check = useCallback(async () => {
    const res = await fetchUnreadNotificationCount(config);
    if (res.ok) setUnread(res.data.count);
  }, [config]);

  useEffect(() => {
    // Asking the server how many are unread is what this effect is for.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    check();
    const timer = setInterval(check, 60000);
    const sub = AppState.addEventListener('change', (s) => {
      if (s === 'active') check();
    });
    return () => {
      clearInterval(timer);
      sub.remove();
    };
  }, [check]);

  return (
    <>
      <Pressable
        onPress={() => setOpen(true)}
        accessibilityRole="button"
        accessibilityLabel={unread ? t('m.pro.notifications_unread', { n: unread }) : t('m.pro.notifications')}
        style={styles.bell}
        hitSlop={6}
      >
        <TablerIcon name="bell" size={19} color={colors.text2} />
        {unread > 0 && <View style={styles.dot} />}
      </Pressable>
      <NotificationsSheet
        config={config}
        visible={open}
        onClose={() => {
          setOpen(false);
          setUnread(0);
        }}
      />
    </>
  );
}

function NotificationsSheet({ config, visible, onClose }: { config: KounseliaConfig; visible: boolean; onClose: () => void }) {
  const styles = useStyles();
  const colors = useColors();
  const { language, t } = useLanguage();
  const [items, setItems] = useState<AppNotification[] | null>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    if (!visible) return;
    let live = true;
    fetchNotifications(config).then((res) => {
      if (!live) return;
      if (res.ok) {
        setItems(res.data.notifications);
        setFailed(false);
      } else {
        setFailed(true);
      }
    });
    return () => {
      live = false;
    };
  }, [visible, config]);

  return (
    <Sheet visible={visible} title={t('m.pro.notifications')} onClose={onClose}>
      {items === null ? (
        failed ? (
          <Text style={styles.empty}>{t('m.pro.notifications_load_failed')}</Text>
        ) : (
          <ActivityIndicator color={colors.accentText} style={{ marginVertical: 24 }} />
        )
      ) : items.length === 0 ? (
        <Text style={styles.empty}>{t('m.pro.notifications_empty')}</Text>
      ) : (
        items.map((n) => (
          <Pressable
            key={n.id}
            onPress={() => {
              onClose();
              if (n.url) router.push(routeForLink(n.url));
            }}
            accessibilityRole="button"
            style={[styles.item, n.unread && styles.itemUnread]}
          >
            <Text style={styles.title}>{n.title}</Text>
            {n.body ? <Text style={styles.body}>{n.body}</Text> : null}
            <Text style={styles.time}>{when(n.created_at, language)}</Text>
          </Pressable>
        ))
      )}
      <View style={{ height: 8 }} />
    </Sheet>
  );
}

// The server sends the site's own time ("2026-10-01 14:05:00").
function when(siteTime: string, language: string): string {
  const d = new Date(siteTime.replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return '';
  return d.toLocaleString(language, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

const useStyles = makeStyles((colors) => ({
  bell: {
    width: 40,
    height: 40,
    borderRadius: 12,
    backgroundColor: colors.surface2,
    borderWidth: 1,
    borderColor: colors.border,
    alignItems: 'center',
    justifyContent: 'center',
  },
  dot: { position: 'absolute', top: 8, right: 9, width: 8, height: 8, borderRadius: 4, backgroundColor: colors.rose },
  empty: { fontFamily: fonts.regular, fontSize: 14, lineHeight: 21, color: colors.text3, textAlign: 'center', marginVertical: 24 },
  item: { padding: 14, borderRadius: 14, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.bg, marginBottom: 8 },
  itemUnread: { borderColor: colors.accentBorder, backgroundColor: colors.accentLight },
  title: { fontFamily: fonts.semibold, fontSize: 14, color: colors.text },
  body: { fontFamily: fonts.regular, fontSize: 13, lineHeight: 19, color: colors.text2, marginTop: 3 },
  time: { fontFamily: fonts.regular, fontSize: 11.5, color: colors.text3, marginTop: 6 },
}));
