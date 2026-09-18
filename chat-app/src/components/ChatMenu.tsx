interface Props {
  open: boolean;
  onExport: () => void;
  onClear: () => void;
  onUpgrade: () => void;
}

export function ChatMenu({ open, onExport, onClear, onUpgrade }: Props) {
  return (
    <div className={`chat-dropdown${open ? ' show' : ''}`}>
      <div className="chat-dropdown-item" onClick={onExport}>
        <i className="ti ti-download" /> Export chat
      </div>
      <div className="chat-dropdown-item" style={{ color: 'var(--rose)' }} onClick={onClear}>
        <i className="ti ti-trash" style={{ color: 'inherit' }} /> Clear chat
      </div>
      <div className="chat-dropdown-item" style={{ borderTop: '1px solid var(--border)' }} onClick={onUpgrade}>
        <i className="ti ti-sparkles" style={{ color: 'var(--gold)' }} /> Upgrade
      </div>
    </div>
  );
}
