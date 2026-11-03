import { peso, time12 } from '@/lib/format';
import PublicLayout from '@/layouts/PublicLayout';

export default function Policies({ policy }: any) {
    const faqs = [
        ['Can I book and pay on this website?', 'No. The website gives estimates and helps you inquire. Our staff confirm every booking and arrange payment with you directly.'],
        ['Is the availability shown guaranteed?', 'No. It is indicative, since some bookings are made on other channels. Please confirm with us before making plans.'],
        ['What time is check-in?', `Standard check-in is ${time12(policy.standard_check_in)}.${policy.standard_check_out ? ` Standard check-out is ${time12(policy.standard_check_out)}.` : ' Please ask us about check-out time.'}`],
        ['Can I check in early?', `Please ask us. Early check-in is ${peso(policy.early_fee_cents_per_hour)} per started hour before ${time12(policy.standard_check_in)}, subject to availability, room readiness and staff approval. For example, arriving at 12:00 PM adds ${peso(policy.early_fee_cents_per_hour * 2)} under this policy.`],
        ['Are extra guests allowed?', "Each suite lists how many guests are included and its maximum. We can't accommodate more than the maximum. Please ask us about any charge for guests above the included number."],
        ['Is parking available?', 'Parking is arranged separately and depends on what is available for your dates. Please ask us.'],
    ];

    return (
        <PublicLayout title="Policies & FAQ" description="UNIQA SUITES check-in, early arrival, extra guest and booking policies, plus our privacy notice.">
            <div className="mx-auto max-w-3xl px-4 py-10">
                <h1 className="mb-6 font-serif text-4xl font-medium">Policies &amp; FAQ</h1>
                <div className="space-y-5">
                    {faqs.map(([q, a]) => (
                        <section key={q}>
                            <h2 className="font-serif text-xl font-semibold">{q}</h2>
                            <p className="mt-1 text-sm text-muted">{a}</p>
                        </section>
                    ))}
                </div>
                <section id="privacy" className="mt-10 border-t border-line pt-6">
                    <h2 className="mb-2 font-serif text-2xl font-medium">Privacy notice</h2>
                    <p className="text-sm text-muted">This website does not collect your details through its search or estimate tools, and your inquiry summary stays in your browser until you choose to send it to us. Details you share when you message us are used to respond to your inquiry and manage your stay, and are accessible only to authorized staff.</p>
                </section>
            </div>
        </PublicLayout>
    );
}
