// The phone's sound settings. A voice call needs "play and record" with
// echo cancellation (so the counselor's voice through the speaker isn't
// picked up by the microphone); dictation needs recording; listening to a
// reply needs plain playback that still works with the ringer switch off.
import { AudioManager } from 'react-native-audio-api';

export type SoundMode = 'call' | 'record' | 'listen';

export async function setSoundMode(mode: SoundMode): Promise<void> {
  if (mode === 'call') {
    AudioManager.setAudioSessionOptions({
      iosCategory: 'playAndRecord',
      iosMode: 'voiceChat',
      iosOptions: ['defaultToSpeaker', 'allowBluetoothHFP'],
    });
  } else if (mode === 'record') {
    AudioManager.setAudioSessionOptions({ iosCategory: 'playAndRecord', iosMode: 'default', iosOptions: ['defaultToSpeaker', 'allowBluetoothHFP'] });
  } else {
    AudioManager.setAudioSessionOptions({ iosCategory: 'playback', iosMode: 'spokenAudio', iosOptions: [] });
  }
  await AudioManager.setAudioSessionActivity(true).catch(() => undefined);
}

export async function releaseSound(): Promise<void> {
  await AudioManager.setAudioSessionActivity(false).catch(() => undefined);
}

// Asks for the microphone the first time; afterwards just checks.
export async function micAllowed(): Promise<boolean> {
  try {
    const current = await AudioManager.checkRecordingPermissions();
    if (current === 'Granted') return true;
    return (await AudioManager.requestRecordingPermissions()) === 'Granted';
  } catch {
    return false;
  }
}
