// Metro is the tool that bundles the app's code. By default it only looks
// inside this folder; the shared code in ../packages/core (used by the
// website chat too) lives outside it, so tell Metro to watch that as well.
const path = require('path');
const { getDefaultConfig } = require('expo/metro-config');

const config = getDefaultConfig(__dirname);
config.watchFolders = [...(config.watchFolders ?? []), path.resolve(__dirname, '../packages/core')];

// This app never renders Expo Router's <NativeTabs> — see
// metro-stubs/expo-symbols-android.js for why this keeps a ~950KB font
// some of Expo Router's own Android code pulls in, but this app never
// reaches, out of the bundle.
const defaultResolveRequest = config.resolver.resolveRequest;
config.resolver.resolveRequest = (context, moduleName, platform) => {
  if (moduleName === 'expo-symbols' && platform === 'android') {
    return { filePath: path.resolve(__dirname, 'metro-stubs/expo-symbols-android.js'), type: 'sourceFile' };
  }
  return defaultResolveRequest
    ? defaultResolveRequest(context, moduleName, platform)
    : context.resolveRequest(context, moduleName, platform);
};

module.exports = config;
