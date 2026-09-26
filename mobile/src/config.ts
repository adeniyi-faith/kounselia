// Where the app finds the Kounselia server: the same WordPress admin-ajax
// endpoint the website uses. Point it at a test copy of the site by
// starting the app with EXPO_PUBLIC_KOUNSELIA_AJAX_URL set.
export const AJAX_URL =
  process.env.EXPO_PUBLIC_KOUNSELIA_AJAX_URL || 'https://kounselia.com/portal/wp-admin/admin-ajax.php';

// Web pages the app sends people to (password reset links in emails
// already open these).
export const SITE_URL = 'https://kounselia.com';
