<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\User;
use Tests\DomainTestCase;

class FaqTest extends DomainTestCase
{
    public function test_staff_who_send_quotes_create_edit_and_delete_answers(): void
    {
        $this->actingAs($this->owner)->post('/admin/faqs', ['topic' => 'Check-in', 'question' => 'What time is check-in?', 'answer' => "Check-in is 2:00 PM.\nEarly check-in depends on availability."])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $faq = Faq::sole();
        $this->assertSame(['suites', 'Check-in', "Check-in is 2:00 PM.\nEarly check-in depends on availability."], [$faq->category, $faq->topic, $faq->answer]);

        $this->post("/admin/faqs/{$faq->id}", ['topic' => '', 'question' => 'What time is check-in?', 'answer' => 'Check-in is 3:00 PM.'])->assertSessionHasNoErrors();
        $this->assertSame([null, 'Check-in is 3:00 PM.'], [$faq->fresh()->topic, $faq->fresh()->answer]);

        $this->post('/admin/faqs', ['question' => '', 'answer' => ''])->assertSessionHasErrors(['question', 'answer']);

        $this->post("/admin/faqs/{$faq->id}/delete")->assertSessionHasNoErrors();
        $this->assertSame(0, Faq::count());
    }

    public function test_each_business_keeps_its_own_answers(): void
    {
        $this->actingAs($this->owner)->post('/admin/faqs', ['question' => 'Is parking free?', 'answer' => 'Yes.']);
        $this->post('/admin/category', ['category' => 'cameras']);
        $this->post('/admin/faqs', ['question' => 'Do you need an ID?', 'answer' => 'Yes, one valid ID.']);

        $page = $this->get('/admin/faqs')->assertOk()->original->getData()['page']['props'];
        $this->assertSame(['Do you need an ID?'], array_column($page['faqs'], 'question'));
        $this->assertSame('cameras', Faq::where('question', 'Do you need an ID?')->value('category'));
    }

    public function test_people_without_quotes_access_cannot_see_or_change_answers(): void
    {
        $faq = Faq::create(['category' => 'suites', 'question' => 'Pets?', 'answer' => 'Sorry, no pets.']);
        $cleaner = User::factory()->create();
        $cleaner->assignRole('Housekeeping / Laundry');

        $this->actingAs($cleaner)->get('/admin/faqs')->assertForbidden();
        $this->post('/admin/faqs', ['question' => 'X?', 'answer' => 'Y'])->assertForbidden();
        $this->post("/admin/faqs/{$faq->id}/delete")->assertForbidden();
        $this->assertSame(1, Faq::count());
    }
}
