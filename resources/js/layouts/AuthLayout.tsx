import { Head, usePage } from '@inertiajs/react';
import { ReactNode } from 'react';

export default function AuthLayout({ title, children }: { title: string; children: ReactNode }) {
    const { flash } = usePage<any>().props as any;

    return (
        <div className="flex min-h-screen items-center justify-center bg-chocolate p-4">
            <Head title={title} />
            <div className="w-full max-w-sm">
                <img src="/brand/uniqa-system.png" alt="UNIQA SUITES Management System" className="mx-auto mb-6 w-64" />
                <div className="rounded-lg bg-white p-6 text-chocolate">
                    <h1 className="mb-4 font-serif text-2xl font-semibold">{title}</h1>
                    {flash?.success && <p role="status" className="mb-3 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-900">{flash.success}</p>}
                    {flash?.error && <p role="alert" className="mb-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-900">{flash.error}</p>}
                    {children}
                </div>
            </div>
        </div>
    );
}
