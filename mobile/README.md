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

## Installing a test version on a phone

The voice features use their own sound code, so the app can't be tried
in the Expo Go app. It needs a real build, made in the cloud by Expo's
EAS service (nothing needs Xcode or Android Studio). Build settings are
in `eas.json`.

One-time setup:

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
