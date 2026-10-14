# How Kounselia fits together

This page explains the pieces in plain English and how they talk to each other.

## The big picture

```
   Visitors and members                    Professionals and admins
   (website or phone app)                  (website)
            |                                      |
            v                                      v
  +-------------------+   +-----------------------------------+
  |   Website pages   |   |  Admin panel   (portal/admin/)    |
  |  (top-level .php) |   +-----------------------------------+
  +-------------------+                  |
            |        +-------------------+
            v        v
  +---------------------------------------------+        +---------------+
  |  Back end: the "Kounselia" plugin           | <----> |   Database    |
  |  portal/wp-content/mu-plugins/kounselia/    |        |   (MySQL)     |
  +---------------------------------------------+        +---------------+
        |            |             |
        v            v             v
   Google Gemini   Paystack     8x8 video
   (AI chat/voice) (payments)   (video calls)
```

The phone app, the web chat screen and the website pages all ask the same back end for data. There is only one set of rules, whichever way a person arrives.

## The website

The website is a set of PHP pages. Each page's file name is its web address: `talk.php` is `kounselia.com/talk.php`. That is why these files stay in the top folder. Moving one would change its address, and the phone app and payment company have those addresses saved.

- `index.php` is the home page, with the guest trial chat.
- `dashboard.php` is the member dashboard. `pro-dashboard.php` is the professional dashboard.
- `talk.php` is the conversation screen with an AI counselor.
- `video-call.php` is the video session room.
- `apply.php` is the professional application. `pro-write.php` is where professionals write articles.
- `paystack-webhook.php`, `subscription-callback.php` and `booking-payment-callback.php` are the addresses Paystack calls to report payments.
- `blog/`, `page/`, `professionals/` and `newsletter/` serve the blog, the CMS pages, the public professionals list and the newsletter pages.

## WordPress, used as a foundation

Kounselia runs on WordPress, but doesn't look like a WordPress site. WordPress provides user accounts, sign-in, the database and email sending. WordPress itself lives in `portal/` on the server and is **not** stored in this repo.

Kounselia's own code loads as a **"must-use plugin"**. WordPress loads these automatically from `portal/wp-content/mu-plugins/`, so they can't be switched off by accident. `kounselia-core.php` is a small loader. It pulls in the real code from `kounselia/includes/`, where each file is one feature (bookings, payments, the journal, memory, safety, newsletters and so on).

## The admin panel

`portal/admin/` is a separate admin area, with one page per job: members, professionals, bookings, plans, safety flags, the blog, pages, newsletters, platform settings and an audit log. Each admin can turn on two-factor sign-in for their own account.

## Shared pieces

- `inc/` holds pieces used by many pages: the site navigation, the footer, shared styles and the dashboard tabs.
- `inc/kounselia-chat-engine.php` draws the conversation screen. It can use an older hand-written version (still used on the home page's guest trial) or the newer React version.

## The web chat screen

`chat-app/` is the newer conversation screen, written in React and TypeScript. It is built into two files that are saved in `inc/kounselia-chat-engine/assets/react/`. The server has no build tools, so it needs the finished files in the repo.

## The shared code

`packages/core/` holds the code both the web chat and the phone app use to talk to the back end: how to ask for data, what the answers look like, and the translations (English, French, Spanish, Portuguese and Arabic). It can't use anything that only exists in a web browser, so the phone app can use it too.

## The phone app

`mobile/` is the iOS and Android app, built with Expo and React Native. It can't use website cookies, so it signs in with the `kounselia_app_login` request and receives a token. It keeps the token in the phone's secure storage and sends it with every request. It uses the same back end and the same `admin-ajax.php` requests as the website.

## Safety

Messages in chats and in booking messages are scanned for crisis language. When something looks serious, the back end raises a flag and alerts the on-call staff (`safety-detection`, `safety-escalation` and `safety-ai-screening` in the plugin). The AI is never presented as a doctor or an emergency service.

## Money

Members pay for the Pro plan and for sessions through Paystack. The back end checks payments against Paystack's reports (`payment-reconciliation.php`), and keeps track of each professional's share of every paid session so they can request a payout.

## Tests and automatic checks

- `tests/` holds 25 PHP test files that run against a real temporary WordPress.
- On every push, GitHub Actions runs the PHP tests, type-checks the shared code and the phone app, builds the web chat and runs the phone app's health check.
- A separate **Android check** builds the app and taps through the main screens on a virtual phone.
- Merging into `main` also deploys the site.

## Why it looks like this

Most of the layout comes from how the site is hosted: it runs on shared cPanel hosting, where the whole repository is the website's public folder and there is no build step on the server. That is why built files are saved in the repo, why the public PHP pages sit in the top folder, and why `.htaccess` files block public access to `tests/`, `mobile/` and `packages/`.
