<?php
/**
 * Kounselia Admin — Broadcasts (old address).
 *
 * The one-shot "email all members" composer grew into the Newsletter
 * section (segments, batched sending, unsubscribes, tracking). This
 * keeps old bookmarks working.
 */
require_once __DIR__ . '/../inc/admin-auth.php';
wp_safe_redirect( '/portal/admin/pages/newsletter.php' );
exit;