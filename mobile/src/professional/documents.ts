// Picking a verification document (a license, an ID, a certificate) on the
// phone, ready to send: a PDF or picture from Files, or a photo from the
// library. Photos are shrunk first, so a phone camera's huge picture
// still uploads quickly on a slow connection and stays under the 8MB the
// server accepts.
import type { PickedDocument, Translate } from '@kounselia/core';
import * as DocumentPicker from 'expo-document-picker';
import { File } from 'expo-file-system';
import { ImageManipulator, SaveFormat } from 'expo-image-manipulator';
import * as ImagePicker from 'expo-image-picker';
import { withoutLocking } from '@/appLockSetting';

const MAX_BYTES = 8 * 1024 * 1024;

export type PickResult = { ok: true; doc: PickedDocument } | { ok: false; message: string } | null; // null: they backed out

async function shrinkPhoto(t: Translate, uri: string, name: string): Promise<PickResult> {
  const image = await ImageManipulator.manipulate(uri).resize({ width: 2000 }).renderAsync();
  const saved = await image.saveAsync({ compress: 0.8, format: SaveFormat.JPEG, base64: true });
  if (!saved.base64) return { ok: false, message: t('m.pro.doc_photo_unreadable') };
  return { ok: true, doc: { name: name.replace(/\.[^.]*$/, '') + '.jpg', base64: saved.base64 } };
}

/** A PDF, JPG or PNG from the phone's files (or iCloud / Google Drive). */
export async function pickDocumentFile(t: Translate): Promise<PickResult> {
  const res = await withoutLocking(() =>
    DocumentPicker.getDocumentAsync({ type: ['application/pdf', 'image/jpeg', 'image/png'], copyToCacheDirectory: true }),
  );
  const asset = res.canceled ? null : res.assets[0];
  if (!asset) return null;
  try {
    const isPdf = asset.mimeType === 'application/pdf' || /\.pdf$/i.test(asset.name);
    if (!isPdf) return await shrinkPhoto(t, asset.uri, asset.name);
    if (asset.size && asset.size > MAX_BYTES) return { ok: false, message: t('m.pro.doc_too_large') };
    return { ok: true, doc: { name: asset.name, base64: await new File(asset.uri).base64() } };
  } catch {
    return { ok: false, message: t('m.pro.doc_file_unreadable') };
  }
}

/** A photo of the document from the phone's photo library. */
export async function pickDocumentPhoto(t: Translate): Promise<PickResult> {
  const res = await withoutLocking(() => ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], quality: 1 }));
  const asset = res.canceled ? null : res.assets[0];
  if (!asset) return null;
  try {
    return await shrinkPhoto(t, asset.uri, asset.fileName || 'document.jpg');
  } catch {
    return { ok: false, message: t('m.pro.doc_photo_unusable') };
  }
}
