import { FormScreen } from '@/components/FormScreen';
import { ApplyForm } from '@/professional/ApplyForm';

// "Join as a professional" before having an account: the account and the
// application are made together, then the app opens their professional home.
export default function ApplySignedOut() {
  return (
    <FormScreen
      title="Bring your practice to Kounselia"
      subtitle="Tell us about your practice and upload your credentials. We review every application by hand before you can see clients here."
    >
      <ApplyForm withAccount />
    </FormScreen>
  );
}
