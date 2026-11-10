<?php

namespace Tests\Feature;

use App\Domain\Bookings\MessageService;
use App\Models\MessageTemplate;
use App\Models\Property;
use Database\Seeders\DatabaseSeeder;
use Tests\DomainTestCase;

/** Templates under Settings: title and text are editable, each can be for The Loop, Mesaverte or both, and can be deleted. */
class MessageTemplatesTest extends DomainTestCase
{
    private function shownOn(string $roomSlug): array
    {
        $booking = $this->confirmed($roomSlug, '2031-03-03', '2031-03-05');

        return array_column(app(MessageService::class)->renderAll($booking), 'name');
    }

    private function property(string $slug): int
    {
        return Property::where('slug', $slug)->value('id');
    }

    public function test_title_text_and_property_are_saved(): void
    {
        $template = MessageTemplate::where('key', 'directions')->firstOrFail();

        $this->post("/admin/settings/templates/{$template->id}", ['name' => 'Directions to Mesaverte', 'body' => "Hi {{guest}}!\nTake the north gate.", 'property_id' => $this->property('mesaverte')])
            ->assertSessionHasNoErrors();

        $fresh = $template->fresh();
        $this->assertSame(['Directions to Mesaverte', "Hi {{guest}}!\nTake the north gate.", $this->property('mesaverte')], [$fresh->name, $fresh->body, (int) $fresh->property_id]);

        $this->post("/admin/settings/templates/{$template->id}", ['name' => '', 'body' => ''])->assertSessionHasErrors(['name', 'body']);
    }

    public function test_a_template_for_one_property_only_shows_on_that_propertys_bookings(): void
    {
        MessageTemplate::where('key', 'directions')->update(['name' => 'Mesaverte directions', 'property_id' => $this->property('mesaverte')]);
        MessageTemplate::where('key', 'arrival')->update(['name' => 'Loop arrival', 'property_id' => $this->property('the-loop')]);

        $loop = $this->shownOn('venice');
        $mesaverte = $this->shownOn('berlin');

        $this->assertContains('Loop arrival', $loop);
        $this->assertNotContains('Mesaverte directions', $loop);
        $this->assertContains('Mesaverte directions', $mesaverte);
        $this->assertNotContains('Loop arrival', $mesaverte);
        $this->assertContains('Booking confirmation', $loop, 'A template for both shows everywhere.');
        $this->assertContains('Booking confirmation', $mesaverte);
    }

    public function test_new_templates_are_added_for_suites_or_cameras(): void
    {
        $this->post('/admin/settings/templates', ['business' => 'suites', 'name' => 'Pool hours', 'body' => 'Hi {{guest}}, the pool is open 8 AM to 8 PM.', 'property_id' => $this->property('the-loop')])->assertSessionHasNoErrors();
        $this->post('/admin/settings/templates', ['business' => 'suites', 'name' => 'Pool hours', 'body' => 'Second one with the same title.'])->assertSessionHasNoErrors();
        $this->post('/admin/settings/templates', ['business' => 'cameras', 'name' => 'Battery tips', 'body' => 'Charge it overnight.'])->assertSessionHasNoErrors();

        $pools = MessageTemplate::where('name', 'Pool hours')->orderBy('id')->get();
        $this->assertCount(2, $pools);
        $this->assertNotSame($pools[0]->key, $pools[1]->key);
        $this->assertTrue(MessageTemplate::where('name', 'Battery tips')->sole()->isCamera());
        $this->assertContains('Pool hours', $this->shownOn('venice'));
    }

    public function test_a_property_must_be_a_suite_property(): void
    {
        $this->seed(\Database\Seeders\GrabCamSeeder::class);
        $camera = Property::where('category', Property::CAMERAS)->value('id');

        $this->post('/admin/settings/templates', ['business' => 'suites', 'name' => 'X', 'body' => 'Y', 'property_id' => $camera])->assertSessionHasErrors('property_id');
    }

    public function test_deleted_templates_disappear_and_stay_deleted_when_seeders_run_again(): void
    {
        $template = MessageTemplate::where('key', 'thank_you')->firstOrFail();

        $this->post("/admin/settings/templates/{$template->id}/delete")->assertSessionHasNoErrors();
        $this->assertNotContains('Post-stay thank-you', $this->shownOn('venice'));

        $this->seed(DatabaseSeeder::class);
        $this->assertSame(0, MessageTemplate::where('key', 'thank_you')->count());
        $this->assertNotContains('Post-stay thank-you', array_column($this->get('/admin/settings')->assertOk()->original->getData()['page']['props']['templates'], 'name'));
    }

    public function test_the_quotation_template_cannot_be_deleted_or_tied_to_one_property(): void
    {
        $quote = MessageTemplate::where('key', 'quote')->firstOrFail();

        $this->post("/admin/settings/templates/{$quote->id}/delete")->assertSessionHasErrors('template');
        $this->assertNotNull($quote->fresh());

        $this->post("/admin/settings/templates/{$quote->id}", ['name' => 'Rate quotation', 'body' => $quote->body, 'property_id' => $this->property('mesaverte')])->assertSessionHasNoErrors();
        $this->assertSame(['Rate quotation', null], [$quote->fresh()->name, $quote->fresh()->property_id]);
    }
}
