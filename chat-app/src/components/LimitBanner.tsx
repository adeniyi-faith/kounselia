interface Props {
  kind: 'soft' | 'hard';
  onSignUp: () => void;
}

// Shown to guests as they approach, then hit, the free-message limit.
export function LimitBanner({ kind, onSignUp }: Props) {
  const isHard = kind === 'hard';
  return (
    <div className="limit-banner">
      <i className={`ti ${isHard ? 'ti-lock' : 'ti-info-circle'}`} />
      <span>
        {isHard
          ? "You've reached the guest limit. "
          : 'One message left as a guest. '}
        <a onClick={onSignUp}>{isHard ? 'Sign up free' : 'Create a free account'}</a>
        {isHard ? ' to continue and save your progress.' : ' to keep going and save this session.'}
      </span>
    </div>
  );
}
