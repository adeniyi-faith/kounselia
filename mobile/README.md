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
  - `(app)/` — everything behind sign-in.
- `src/components/` — buttons, text fields, etc. styled like the website.
- `src/theme.ts` — colours and fonts, copied from `inc/kounselia-styles.php`.
- `src/session.tsx` — who is signed in; hands out the `config` every
  `@kounselia/core` call needs.

Builds for the App Store and Play Store are made with EAS
(`npx eas-cli build`); nothing needs Xcode or Android Studio locally.
