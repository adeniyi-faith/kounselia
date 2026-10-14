# Setup guide

This guide covers the three parts you can run on their own: the PHP tests, the web chat screen and the phone app. Running the whole website on your own computer is covered at the end.

## What you need

- **PHP 8.1** and **Composer** (for the tests)
- **MySQL 8** or MariaDB (the tests create a throwaway database)
- **Subversion** (`svn`). The test setup uses it to download WordPress's own test tools.
- **Node.js 22** and npm (for the web chat and the phone app)

## Run the PHP tests

The tests check the back end's rules, such as who may see what, how payments are matched and how safety flags work. They run against a real, temporary WordPress.

```sh
composer install
bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1:3306 latest
WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit
```

The second line downloads WordPress and creates a test database called `wordpress_test`. Change the user, password and host to match your MySQL. It is safe to run again. The same steps run automatically on GitHub for every push (see `.github/workflows/tests.yml`).

## Check the shared code

```sh
cd packages/core
npm ci
npm run typecheck
```

## Build the web chat screen

The chat screen on `talk.php` is a React app in `chat-app/`.

```sh
cd chat-app
npm ci
npm run dev       # live preview while you work
npm run build     # makes dist/kounselia-chat.js and dist/kounselia-chat.css
```

The server has no build step, so the finished files are saved in the repo at `inc/kounselia-chat-engine/assets/react/`. After a change, copy the two files from `dist/` to that folder and commit them.

## Run the phone app

```sh
cd packages/core && npm ci
cd ../../mobile
npm install
npx expo start
```

Then scan the QR code with a phone. Note that the voice features need a real build, so they don't work in the Expo Go app. By default the app talks to kounselia.com. To point it at a test copy of the site:

```sh
EXPO_PUBLIC_KOUNSELIA_AJAX_URL=https://staging.example.com/portal/wp-admin/admin-ajax.php npx expo start
```

Checks for the app:

```sh
npm run typecheck
npm run lint
npx expo-doctor
```

[mobile/README.md](../mobile/README.md) explains test builds, store builds, notifications and crash reports.

## Run the whole website locally

The repository holds Kounselia's own code only. WordPress itself, its database, its uploads and `wp-config.php` are not stored here (they are listed in `.gitignore`). To run the site on your own computer:

1. Install WordPress into the `portal/` folder (so `portal/wp-admin/` and `portal/wp-includes/` exist) and create its `wp-config.php` and a database.
2. Make sure your web server serves the top folder of this repository as the site's root, with PHP 8.1.
3. Open the site and sign in to WordPress once so it creates its tables. Kounselia's own tables are created by `schema.php` in the main plugin folder.

I have not run this path end to end for this guide. Treat step 3 in particular as a starting point.

## Settings and keys

Keys are never stored in the code. An admin pastes them in **Admin → Platform Settings**:

| Setting | Used for |
|---|---|
| Gemini key | AI conversation and voice |
| Paystack public and secret keys | Payments and payouts |
| 8x8 AppID, key ID and private key | Video calls |

Some values live in GitHub's secret settings (**Settings → Secrets and variables → Actions**):

| Secret | Used for |
|---|---|
| `CPANEL_HOST`, `CPANEL_USERNAME`, `CPANEL_PASSWORD` | The automatic deploy to the web host |
| `EXPO_TOKEN` | Building the phone app on Expo |

## How the live site updates

When something is merged into `main`, the **Deploy to cPanel** workflow signs in to the web host over SSH and makes the server's copy of the repo match `main`. If someone edited a tracked file directly on the server, the deploy stops and lists those files instead of overwriting them.
