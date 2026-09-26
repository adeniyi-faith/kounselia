// What inc/kounselia-chat-engine.php prints onto the page before the
// chat bundle loads. Browser-only, so it lives here rather than in
// @kounselia/core, which the mobile app shares.
import type { CounselorMap, KounseliaConfig } from '@kounselia/core';

declare global {
  interface Window {
    KOUNSELIA: KounseliaConfig;
    C: CounselorMap;
    kounseliaMountChat?: () => void;
    __kounseliaDeferMount?: boolean;
  }
}
