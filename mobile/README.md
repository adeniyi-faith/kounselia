# Kounselia mobile app

The iOS and Android app, built with Expo and React Native. It talks to the
same WordPress server as the website, through the same admin-ajax actions.

## How it signs in

The website uses a browser cookie plus a nonce printed into the page. The
app can't, so it signs in with `kounselia_app_login` and gets back a token,
which it keeps in the phone's secure storage and sends as an
`X-Kounselia-Token` header on every request. Server side:
`portal/wp-content/mu-plugins/kounselia/includes/app-auth.php`.

## Shared code

Network calls and data shapes live in `../packages/core`, used by both this
app and the website chat (`../chat-app`). It must not use anything
browser-only (window, localStorage, the DOM); its own typecheck enforces
that.

## Running it

```sh
npm install
npx expo start          # then scan the QR code with the Expo Go app
npm run typecheck
npx expo-doctor
```

To point the app at a test copy of the site instead of kounselia.com:

```sh
EXPO_PUBLIC_KOUNSELIA_AJAX_URL=https://staging.example.com/portal/wp-admin/admin-ajax.php npx expo start
```

## Layout

- `src/app/` — screens (Expo Router: each file is a screen).
  - `(auth)/` — welcome, sign in, sign up, forgot password (signed-out only).
  - `(app)/(tabs)/` — Home, Talk (the counselor grid) and Settings, with
    the website dashboard's tab bar (`components/TabBar.tsx`).
  - `(app)/chat/[slug].tsx` — a conversation with one counselor.
- `src/components/` — buttons, text fields, etc. styled like the website.
- `src/theme.ts` — colours and fonts, copied from `inc/kounselia-styles.php`.
- `src/counselors.tsx` — the counselor list, loaded from the server
  (`kounselia_get_counselors`), so admin changes show up without an app update.
- `src/chat/useChat.ts` — one conversation: history, sending, retrying,
  rating, clearing.
- `components/TablerIcon.tsx` — the website's icon set, so counselor icons
  chosen in the admin match. Update with `node scripts/build-tabler-icons.mjs`.
- `src/session.tsx` — who is signed in; hands out the `config` every
  `@kounselia/core` call needs.

## Privacy, notifications and crash reports

- **Delete account** (Settings → Your data): asks for the password again,
  then erases the account and everything private in it. Server side:
  `includes/account-deletion.php`, which lists what's erased and what's kept.
- **App lock** (Settings → Privacy and security): asks for the phone's own
  Face ID, fingerprint or passcode when the app opens and when the member
  comes back to it. Kept on the phone only; off after signing out.
  `src/components/AppLock.tsx`.
- **Push notifications**: whatever the server sends through
  `kounselia_notify_user()` (session reminders, booking changes, comment
  replies) also arrives as a notification. The member is asked when they
  book a session, or can switch it on in Settings. `src/notifications.ts`;
  server side `includes/notifications.php`, which sends through Expo's push
  service (free; no key needed on the server).
- **Crash reports** go to Sentry (sentry.io) with no names, emails, typed
  text or screenshots. `src/monitoring.ts`.

### One-time setup for notifications and crash reports

These need accounts only the owner can create; until they're done the app
works normally, with notifications hidden in Settings and no crash reports.

1. **Link the app to Expo** (needed for notifications). In this folder:
   `npx eas-cli@latest login`, then `npx eas-cli@latest init`. It adds an
   `owner` and a project id to `app.json`: commit that change.
2. **Android notifications**: create a free Firebase project and add an
   Android app with the package name `com.kounselia.app`. Then:
   - download its `google-services.json`, put it in this folder, and add
     `"googleServicesFile": "./google-services.json"` inside `"android"` in
     `app.json` (commit both; the file isn't secret). Until this is in a
     build, Android hides the Notifications switch;
   - upload its FCM V1 service account key to Expo (this one *is* secret,
     don't commit it): https://docs.expo.dev/push-notifications/fcm-credentials/
3. **iPhone notifications**: the first `npm run build:test:ios` or
   `npm run build:store` asks whether to set up push notifications; answer
   yes and let EAS create the key (needs the Apple Developer account).
4. **Crash reports**: create a free Sentry account and a React Native
   project. On expo.dev → the project → Environment variables, add:
   - `EXPO_PUBLIC_SENTRY_DSN`: the project's DSN (Settings → Client Keys)
   - `SENTRY_ORG` and `SENTRY_PROJECT`: the organization and project slugs
   - `SENTRY_AUTH_TOKEN` (visibility "Secret"): an organization auth token
     from Sentry → Settings → Auth Tokens

   Set them for the `preview` and `production` environments.
5. **Build a new version.** These features include new native code, so an
   over-the-air update isn't enough: run a new build (see below).

Any of these can be done later, in any order. A build made before them
works normally, just without that feature: nothing crashes. Most need a
new build afterwards to take effect, because they're built into the app
(the Sentry settings and `google-services.json`). The two keys kept on
Expo's servers (Android's FCM key and Apple's push key) work for builds
already out there as soon as they're added.

## Version numbers

`version` in `app.json` is the number people see in the stores and in
Settings → App version (now 1.1.0). Raise it for each release: the middle
number for new features (1.2.0), the last for fixes only (1.1.1). The
separate build number the stores also need is counted by EAS itself
(`autoIncrement` in `eas.json`), so it never needs changing by hand.

## Installing a test version on a phone

The voice features use their own sound code, so the app can't be tried
in the Expo Go app. It needs a real build, made in the cloud by Expo's
EAS service (nothing needs Xcode or Android Studio). Build settings are
in `eas.json`.

**The easy way, from GitHub** (no computer setup): the repository has an
"App build" job. On GitHub open **Actions → App build → Run workflow**,
choose the phone type (start with `android`) and `preview`, and press
**Run workflow**. It takes about 15–20 minutes. When it's done, open the
run: its summary shows a link with a QR code; open that on the Android
phone to install. It uses the repository secret `EXPO_TOKEN` (an Expo
access token from expo.dev → Account settings → Access tokens).

**From a computer instead** — one-time setup:

1. Create a free account at https://expo.dev.
2. In this folder: `npx eas-cli@latest login`, then `npx eas-cli@latest init`
   (links the app to your account; it adds an `owner` and project id to
   `app.json` — commit that change).

**Android** (no store account needed):

    npm run build:test:android

When it finishes (about 15 minutes) EAS shows a link and QR code. Open it
on the Android phone, download the `.apk` and install it (Android asks you
to allow installs from your browser the first time).

**iPhone** (needs an Apple Developer account, $99/year):

    npx eas-cli@latest device:create     # once per iPhone: open the link on it
    npm run build:test:ios

Sign in with your Apple ID when asked; EAS creates the certificates. The
link it gives installs the app on the registered iPhones. For more
testers, use TestFlight instead: `npm run build:store`, then
`npx eas-cli@latest submit --platform ios`.

**Store builds**: `npm run build:store`, then `npx eas-cli@latest submit`.
