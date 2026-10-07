interface Props {
  open: boolean;
  onExport: () => void;
  onClear: () => void;
  onUpgrade: () => void;
}

import { useT } from '../i18n';

export function ChatMenu({ open, onExport, onClear, onUpgrade }: Props) {
  const t = useT();
  return (
    <div className={`chat-dropdown${open ? ' show' : ''}`}>
      <div className="chat-dropdown-item" onClick={onExport}>
        <i className="ti ti-download" /> {t('c.menu.export')}
      </div>
      <div className="chat-dropdown-item" style={{ color: 'var(--rose)' }} onClick={onClear}>
        <i className="ti ti-trash" style={{ color: 'inherit' }} /> {t('c.menu.clear')}
      </div>
      <div className="chat-dropdown-item" style={{ borderTop: '1px solid var(--border)' }} onClick={onUpgrade}>
        <i className="ti ti-sparkles" style={{ color: 'var(--gold)' }} /> {t('c.menu.upgrade')}
      </div>
    </div>
  );
}
