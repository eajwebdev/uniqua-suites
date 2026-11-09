<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use App\Support\AdminCategory;
use App\Support\Audit;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Ready answers for Messenger: write them once, copy and paste them to guests. One list per business. */
class FaqController extends Controller
{
    public function index()
    {
        $faqs = Faq::with('editor:id,name')->where('category', AdminCategory::current())
            ->orderByRaw('topic IS NULL')->orderBy('topic')->orderBy('question')->get()
            ->map(fn (Faq $f) => [
                'id' => $f->id,
                'topic' => $f->topic,
                'question' => $f->question,
                'answer' => $f->answer,
                'times_copied' => $f->times_copied,
                'updated_by' => $f->editor?->name,
                'updated_at' => $f->updated_at,
            ]);

        return Inertia::render('Admin/Faqs', ['faqs' => $faqs, 'cameras' => AdminCategory::isCameras()]);
    }

    public function store(Request $request)
    {
        $faq = Faq::create($this->validated($request) + ['category' => AdminCategory::current(), 'updated_by' => $request->user()->id]);
        Audit::log('faq.created', $faq, ['question' => $faq->question]);

        return back()->with('success', 'Question added. Use Copy to paste the answer in Messenger.');
    }

    public function update(Request $request, Faq $faq)
    {
        $faq->update($this->validated($request) + ['updated_by' => $request->user()->id]);
        Audit::log('faq.updated', $faq, ['question' => $faq->question]);

        return back()->with('success', 'Answer saved.');
    }

    public function destroy(Faq $faq)
    {
        Audit::log('faq.deleted', $faq, ['question' => $faq->question]);
        $faq->delete();

        return back()->with('success', 'Question deleted.');
    }

    /** Counts copies so the most used answers can be spotted. No page reload. */
    public function copied(Faq $faq)
    {
        $faq->timestamps = false;
        $faq->increment('times_copied');

        return response()->noContent();
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'topic' => 'nullable|string|max:60',
            'question' => 'required|string|max:255',
            'answer' => 'required|string|max:5000',
        ], ['answer.required' => 'Write the answer you would send the guest.']);
        $data['topic'] = trim((string) ($data['topic'] ?? '')) ?: null;

        return $data;
    }
}
