# Contributing

This repository is public so the work can be read, but it is not open to outside contributions (see [LICENSE](LICENSE)). These notes are for the people who work on it.

## Working on a change

1. Start a branch from `main`.
2. Make the change. Keep each change small and focused on one thing.
3. Push the branch and open a pull request. Describe what a user would see differently before and after.
4. Wait for the **Tests** check to go green, then merge.

Merging into `main` updates the live website, so only merge a change you are happy to see live.

## Checks to run before you push

```sh
# PHP tests (see docs/setup.md for the one-time setup)
vendor/bin/phpunit

# Shared code, web chat and phone app
(cd packages/core && npm run typecheck)
(cd chat-app && npm run build)
(cd mobile && npm run typecheck && npm run lint)
```

## Things to know

- **The web chat is built and then saved in the repo.** The server has no build step. After changing anything in `chat-app/`, run `npm run build` there and copy `dist/kounselia-chat.js` and `dist/kounselia-chat.css` into `inc/kounselia-chat-engine/assets/react/`.
- **Don't move the public PHP pages.** Their file names are their web addresses, and payment and app links depend on them.
- **Never commit secrets.** API keys live in Admin → Platform Settings, or in GitHub's secret settings.
- **`packages/core` must stay free of browser-only code** (`window`, `localStorage`, the DOM), because the phone app uses it too. Its type check enforces this.
