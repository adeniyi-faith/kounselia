import { FormScreen } from '@/components/FormScreen';
import { useT } from '@/language';
import { ApplyForm } from '@/professional/ApplyForm';

// "Join as a professional" before having an account: the account and the
// application are made together, then the app opens their professional home.
export default function ApplySignedOut() {
  const t = useT();
  return (
    <FormScreen
      title={t('m.pro.apply_heading')}
      subtitle={t('m.pro.apply_intro')}
    >
      <ApplyForm withAccount />
    </FormScreen>
  );
}
