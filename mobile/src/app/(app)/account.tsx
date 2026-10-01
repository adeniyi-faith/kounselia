import Settings from './(tabs)/settings';

// A professional's account settings (password, app lock, notifications,
// appearance, deleting the account), opened from their Profile tab: the
// same screen as the client side's Settings tab, with a back button.
export default function Account() {
  return <Settings inStack />;
}
