// A stand-in for the "expo-symbols" package, used only on Android — see
// metro.config.js for where this is wired in.
//
// This app never renders Expo Router's <NativeTabs> (we use our own
// <TabBar>), so the one thing in the whole dependency tree that reaches
// into expo-symbols on Android — expo-router's NativeTabs Material-icon
// helper — never actually runs. But Metro bundles whatever is `require()`d
// no matter whether it's ever called, and that one reachable path drags in
// a ~950KB Material Symbols font purely so this unused code CAN draw an
// icon if asked to. Standing in for the package here keeps that font out
// of the app; if this app ever does start using <NativeTabs> with Material
// icon names on Android, remove this file and its use in metro.config.js
// and the real package (and its font) comes back automatically.
//
// Mirrors expo-symbols' two exports, doing nothing instead:
export async function unstable_getMaterialSymbolSourceAsync() {
  return null;
}
export function SymbolView() {
  return null;
}
