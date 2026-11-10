<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Faq extends Model
{
    protected $guarded = [];

    protected $casts = ['times_copied' => 'integer', 'show_on_website' => 'boolean'];

    public function editor()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * What a public page may show for one business: only the question and answer, in the same order as the admin list.
     * No editor, copy count or hidden answers reach the website.
     *
     * @return list<array{question: string, answer: string}>
     */
    public static function forWebsite(string $category): array
    {
        return static::where('category', $category)->where('show_on_website', true)
            ->orderByRaw('topic IS NULL')->orderBy('topic')->orderBy('question')
            ->get(['question', 'answer'])
            ->map(fn (Faq $f) => ['question' => $f->question, 'answer' => $f->answer])
            ->all();
    }
}
