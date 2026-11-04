import { MessageButton } from '@/components/public';
import { time12 } from '@/lib/format';
import PublicLayout from '@/layouts/PublicLayout';

export default function Policies({ policy }: any) {
    const faqs = [
        ['How do I book?', 'Press Book Now on any suite and choose free dates. Taken dates cannot be chosen. It then opens a Messenger chat with us, where our staff send your quotation. Your dates are reserved only when staff confirm them in the chat, and payment is arranged there, not on this website.'],
        ['How much does a suite cost?', 'It depends on the suite, your dates and your party size, so our staff quote it for you when you message us.'],
        ['What time is check-in?', `Standard check-in is ${time12(policy.standard_check_in)}.${policy.standard_check_out ? ` Standard check-out is ${time12(policy.standard_check_out)}.` : ' Please ask us about check-out time.'}`],
        ['Can I check in early?', 'Please ask us. Early check-in depends on availability and room readiness, needs staff approval, and may have a fee. We will tell you before you decide.'],
        ['Are extra guests allowed?', "Each suite lists its maximum number of guests, and we can't accommodate more than that. Please ask us about any charge for additional guests."],
        ['Is parking available?', 'Parking is arranged separately and depends on what is available for your dates. Please ask us.'],
        ...(policy.cancellation ? [['What is your cancellation policy?', policy.cancellation]] : []),
        ...(policy.house_rules ? [['What are the house rules?', policy.house_rules]] : []),
    ];

    return (
        <PublicLayout title="Policies & FAQ" description="How to book with UNIQA SUITES, check-in, early arrival, extra guests and parking, plus our privacy notice.">
            <div className="mx-auto max-w-3xl px-4 py-10">
                <h1 className="mb-6 font-serif text-4xl font-medium">Policies &amp; FAQ</h1>
                <div className="space-y-5">
                    {faqs.map(([q, a]) => (
                        <section key={q}>
                            <h2 className="font-serif text-xl font-semibold">{q}</h2>
                            <p className="mt-1 text-base whitespace-pre-line text-muted sm:text-sm">{a}</p>
                        </section>
                    ))}
                </div>
                <div className="mt-8"><MessageButton tone="dark" /></div>
                <section id="privacy" className="mt-10 border-t border-line pt-6">
                    <h2 className="mb-2 font-serif text-2xl font-medium">Privacy notice</h2>
                    <p className="text-base text-muted sm:text-sm">This website does not ask for your details or take payments. Details you share when you message us are used to respond to your inquiry and manage your stay, and are accessible only to authorized staff.</p>
                </section>
            </div>
        </PublicLayout>
    );
}
