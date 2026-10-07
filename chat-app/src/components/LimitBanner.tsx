import { LINK_MARK, splitAtLink, useT } from '../i18n';

interface Props {
  kind: 'soft' | 'hard';
  onSignUp: () => void;
}

// Shown to guests as they approach, then hit, the free-message limit.
export function LimitBanner({ kind, onSignUp }: Props) {
  const t = useT();
  const isHard = kind === 'hard';
  const [before, after] = splitAtLink(
    t(isHard ? 'c.limit.hard' : 'c.limit.soft', { link: LINK_MARK }),
  );
  return (
    <div className="limit-banner">
      <i className={`ti ${isHard ? 'ti-lock' : 'ti-info-circle'}`} />
      <span>
        {before}
        <a onClick={onSignUp}>{t(isHard ? 'c.limit.hard_link' : 'c.limit.soft_link')}</a>
        {after}
      </span>
    </div>
  );
}
