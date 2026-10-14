# Kounselia

[![Tests](https://github.com/adeniyi-faith/kounselia/actions/workflows/tests.yml/badge.svg)](https://github.com/adeniyi-faith/kounselia/actions/workflows/tests.yml)

Kounselia is an online counselling platform. Anyone, anywhere, can talk to an AI counselor at any hour, keep a private journal, and book a live session with a verified human professional, on the website or in the phone app.

## The problem it solves

Getting emotional support is slow, expensive and awkward to start. Kounselia lowers the first step: you can start talking straight away, in your own language, with no waiting list. When you want a person, you can book a verified professional on the same platform. Safety is built in. The platform watches for crisis language, tells on-call staff, and points people to real help.

## What it does

**For members**
- Chat or voice-call with AI counselors, each with their own style.
- Private journal, mood tracking, smart check-ins, and 30-day personal development plans.
- Book, pay for, reschedule and review sessions with human professionals. Video and voice sessions run inside the site and the app.
- A community space and articles written by professionals.
- Available in English, French, Spanish, Portuguese and Arabic (Arabic reads right to left).
- On the phone: app lock (Face ID or fingerprint), push reminders, dark mode, and delete-my-account.

**For professionals**
- Apply and get verified, set rates and availability, publish articles, manage bookings and get paid out.

**For the team**
- An admin panel for members, professionals, bookings, plans, safety flags, the blog, pages, the newsletter and platform settings, with an audit log and optional two-factor sign-in.

## Built with

- **Website and back end:** PHP on WordPress, using "must-use plugins" (code WordPress loads automatically) and a MySQL database.
- **Web chat screen:** React and TypeScript, built with Vite.
- **Phone app (iOS and Android):** Expo and React Native.
- **Shared code:** `packages/core` holds the network calls and data shapes that the web chat and the phone app both use.
- **Services:** Google Gemini (AI conversation and voice), Paystack (payments and payouts), 8x8 JaaS (video calls), Expo push notifications, Sentry (app crash reports).
- **Checks and deployment:** GitHub Actions. PHP tests, build checks and an Android emulator tour run automatically. Pushing to `main` updates the live site.

## Where things are

| Folder | What is in it |
|---|---|
| `*.php` in the top folder, `blog/`, `page/`, `professionals/`, `newsletter/` | The public website pages. Their file names are their web addresses, so they stay put. |
| `portal/wp-content/mu-plugins/kounselia/` | The main back end, split by feature. |
| `portal/admin/` | The admin panel. |
| `inc/` | Shared page pieces and the built web chat. |
| `chat-app/` | Source code of the web chat screen. |
| `packages/core/` | Code shared by the web chat and the phone app. |
| `mobile/` | The phone app. |
| `tests/` | The PHP test suite. |
| `docs/` | Setup and architecture guides. |

## Run it

The phone app is the quickest to try:

```sh
cd mobile && npm install
npx expo start
```

The website needs WordPress and a database, so it can't start in two commands. See [docs/setup.md](docs/setup.md) for the full steps, including how to run the tests.

## Learn more

- [Setup guide](docs/setup.md): run the tests, the web chat and the phone app.
- [Architecture](docs/architecture.md): how the pieces fit together, in plain English.
- [Mobile app notes](mobile/README.md): signing in, notifications, builds and releases.
- [Contributing](CONTRIBUTING.md)

## Licence

All rights reserved. See [LICENSE](LICENSE).
